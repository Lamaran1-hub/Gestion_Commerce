<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\ClotureCaisse;
use App\Models\Depense;
use App\Models\FondCaisse;
use App\Models\Acompte;
use App\Models\PaiementFournisseur;
use App\Models\JournalActivite;
use App\Models\Paiement;
use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Point et clôture de caisse.
 *
 * Règles :
 * - la caisse d'un caissier se clôture une fois par jour ;
 * - après clôture, ce caissier ne peut plus vendre ni encaisser ce jour-là (le rapport Z reste juste) ;
 *   seul l'administrateur de la boutique peut rouvrir la caisse ;
 * - espèces théoriques = espèces encaissées − remboursements − dépenses et règlements fournisseurs payés en espèces
 *   ± transferts de trésorerie touchant la caisse (versement à la banque, dépôt mobile money, apport) ;
 * - tout écart entre espèces comptées et théoriques doit être justifié.
 */
class CaisseService
{
    public function estCloturee(User $caissier, ?Carbon $jour = null): bool
    {
        return ClotureCaisse::where('user_id', $caissier->id)->whereDate('jour', ($jour ?? now())->toDateString())->exists();
    }

    /** Bloque ventes et encaissements d'un caissier dont la caisse du jour est clôturée. */
    public function verifierOuverte(?User $caissier): void
    {
        if ($caissier && $this->estCloturee($caissier)) {
            throw new OperationRefusee("Votre caisse est clôturée pour aujourd'hui. Pour enregistrer une nouvelle opération, "
                ."demandez à l'administrateur de la boutique de la rouvrir (menu Ventes → Clôtures de caisse).");
        }
    }

    /**
     * Bilan de la journée d'un caissier (sans rien enregistrer).
     *
     * @return array{nb_ventes:int, total_ventes:int, encaisse:array<string,int>, rembourse:array<string,int>, net:array<string,int>, especes_theoriques:int}
     */
    public function fond(User $caissier, ?Carbon $jour = null): ?FondCaisse
    {
        return FondCaisse::where('user_id', $caissier->id)->whereDate('jour', ($jour ?? now())->toDateString())->first();
    }

    /** Ce qui a été laissé dans le tiroir à la dernière clôture : c'est le fond attendu ce matin. */
    public function laisseLaVeille(User $caissier): ?int
    {
        return ClotureCaisse::where('user_id', $caissier->id)->whereDate('jour', '<', now()->toDateString())
            ->whereNotNull('laisse_en_caisse')->latest('jour')->value('laisse_en_caisse');
    }

    /**
     * Fond de caisse du jour. Il se déclare avant de vendre : après le premier encaissement, seul l'administrateur
     * peut le corriger (sinon un fond diminué en fin de journée cacherait un manque).
     */
    public function declarerFond(User $caissier, int $montant, User $auteur): FondCaisse
    {
        if ($this->estCloturee($caissier)) {
            throw new OperationRefusee('La caisse est déjà clôturée aujourd\'hui.');
        }
        $fond = $this->fond($caissier);
        $aEncaisse = Paiement::where('user_id', $caissier->id)->whereDate('date_paiement', now()->toDateString())->exists();
        if ($fond && $aEncaisse && ! $auteur->role?->systeme) {
            throw new OperationRefusee('Des ventes ont déjà été encaissées : seul l\'administrateur peut corriger le fond de caisse.');
        }
        $avant = $fond?->montant;
        $fond ??= new FondCaisse(['user_id' => $caissier->id, 'jour' => now()->toDateString()]);
        $fond->fill(['montant' => $montant])->save();
        $veille = $this->laisseLaVeille($caissier);
        JournalActivite::noter('caisse', ($avant === null ? 'Fond de caisse déclaré : ' : 'Fond de caisse corrigé : '.gnf($avant).' → ').gnf($montant)
            .' ('.$caissier->nomComplet().($auteur->id !== $caissier->id ? ', par '.$auteur->nomComplet() : '').')'
            .($veille !== null && $veille !== $montant ? ' — laissé la veille : '.gnf($veille) : ''));

        return $fond;
    }

    public function bilan(User $caissier, Carbon $jour): array
    {
        [$debut, $fin] = [$jour->copy()->startOfDay(), $jour->copy()->endOfDay()];

        $ventes = Vente::validees()->where('user_id', $caissier->id)->whereBetween('date_vente', [$debut, $fin]);
        // L'acompte utilisé à la livraison n'est pas un encaissement du jour : l'argent est compté le jour du versement (plus bas)
        $paiementsDuJour = Paiement::where('user_id', $caissier->id)->where('mode', '!=', Acomptes::MODE)->whereBetween('date_paiement', [$debut, $fin]);
        $encaisse = (clone $paiementsDuJour)->where('montant', '>', 0)
            ->selectRaw('mode, SUM(montant) as total')->groupBy('mode')->pluck('total', 'mode')->map(fn ($v) => (int) $v)->all();

        // Ventes annulées ce jour-là par ce caissier : l'argent déjà reçu a été rendu au client
        $rembourse = Paiement::whereIn('vente_id', Vente::where('statut', 'annulee')->where('annulee_par', $caissier->id)
                ->whereBetween('annulee_le', [$debut, $fin])->select('id'))->where('mode', '!=', Acomptes::MODE)   // l'acompte reste sur la commande
            ->selectRaw('mode, SUM(montant) as total')->groupBy('mode')->pluck('total', 'mode')->map(fn ($v) => (int) $v)->all();
        // Retours de marchandise remboursés (paiements négatifs) : affichés comme remboursements, pas en moins sur l'encaissé
        foreach ((clone $paiementsDuJour)->where('montant', '<', 0)->selectRaw('mode, SUM(montant) as total')->groupBy('mode')->pluck('total', 'mode') as $mode => $total) {
            $rembourse[$mode] = ($rembourse[$mode] ?? 0) - (int) $total;
        }

        // Acomptes versés sur des commandes (entrées) et acomptes remboursés (sorties), au moyen de paiement réel
        foreach (Acompte::where('user_id', $caissier->id)->whereBetween('date_versement', [$debut, $fin])
            ->selectRaw('mode, SUM(CASE WHEN montant > 0 THEN montant ELSE 0 END) as verse, SUM(CASE WHEN montant < 0 THEN -montant ELSE 0 END) as rendu')
            ->groupBy('mode')->get() as $a) {
            if ($a->verse) {
                $encaisse[$a->mode] = ($encaisse[$a->mode] ?? 0) + (int) $a->verse;
            }
            if ($a->rendu) {
                $rembourse[$a->mode] = ($rembourse[$a->mode] ?? 0) + (int) $a->rendu;
            }
        }

        // Cartes cadeaux vendues (entrées) et soldes de cartes annulées rendus (sorties), au moyen de paiement réel
        foreach (\App\Models\MouvementCarteCadeau::where('user_id', $caissier->id)->whereNotNull('mode')->whereBetween('date_mouvement', [$debut, $fin])
            ->selectRaw('mode, SUM(CASE WHEN montant > 0 THEN montant ELSE 0 END) as verse, SUM(CASE WHEN montant < 0 THEN -montant ELSE 0 END) as rendu')
            ->groupBy('mode')->get() as $m) {
            if ($m->verse) {
                $encaisse[$m->mode] = ($encaisse[$m->mode] ?? 0) + (int) $m->verse;
            }
            if ($m->rendu) {
                $rembourse[$m->mode] = ($rembourse[$m->mode] ?? 0) + (int) $m->rendu;
            }
        }

        $net = [];
        foreach (array_unique(array_merge(array_keys($encaisse), array_keys($rembourse))) as $mode) {
            $net[$mode] = ($encaisse[$mode] ?? 0) - ($rembourse[$mode] ?? 0);
        }
        ksort($net);

        // Sorties d'espèces du tiroir : dépenses et règlements fournisseurs payés en espèces par ce caissier
        $depenses = (int) Depense::where('user_id', $caissier->id)->where('mode', 'especes')->whereBetween('created_at', [$debut, $fin])->sum('montant');
        $fournisseurs = (int) PaiementFournisseur::where('user_id', $caissier->id)->where('mode', 'especes')->whereBetween('date_paiement', [$debut, $fin])->sum('montant');

        // Transferts de trésorerie touchant le tiroir (versement à la banque, dépôt Orange Money, apport…)
        $tresorerie = app(Tresorerie::class)->netEspecesCaissier($caissier, $debut, $fin);

        // Espèces reçues en devises : ces billets sont dans le tiroir mais pas en GNF. Leur contre-valeur sort des GNF
        // attendus (seule la monnaie rendue en GNF a quitté le tiroir) et ils se comptent à part, dans leur devise.
        $devises = Paiement::where('user_id', $caissier->id)->whereNotNull('devise')->whereBetween('date_paiement', [$debut, $fin])
            ->selectRaw('devise, SUM(montant_devise) as recu, SUM(contre_valeur) as contre')->groupBy('devise')->orderBy('devise')->get()
            ->mapWithKeys(fn ($d) => [$d->devise => ['attendu' => round((float) $d->recu, 2), 'contre_valeur' => (int) $d->contre]])->all();
        $contreValeur = array_sum(array_column($devises, 'contre_valeur'));
        $fond = (int) $this->fond($caissier, $jour)?->montant;

        return [
            'nb_ventes' => (clone $ventes)->count(),
            'total_ventes' => (int) (clone $ventes)->sum('total_ttc'),
            'encaisse' => $encaisse,
            'rembourse' => $rembourse,
            'net' => $net,
            'depenses_especes' => $depenses,
            'fournisseurs_especes' => $fournisseurs,
            // Détail : règlements versés et remboursements reçus après un retour fournisseur (montants négatifs)
            'fournisseurs_payes_especes' => (int) PaiementFournisseur::where('user_id', $caissier->id)->where('mode', 'especes')->where('montant', '>', 0)->whereBetween('date_paiement', [$debut, $fin])->sum('montant'),
            'fournisseurs_rembourses_especes' => -(int) PaiementFournisseur::where('user_id', $caissier->id)->where('mode', 'especes')->where('montant', '<', 0)->whereBetween('date_paiement', [$debut, $fin])->sum('montant'),
            'tresorerie_especes' => $tresorerie,
            'sorties_especes' => $depenses + $fournisseurs - $tresorerie,
            'devises' => $devises,
            'fond' => $fond,
            'fond_declare' => $this->fond($caissier, $jour) !== null,
            'especes_theoriques' => $fond + ($net['especes'] ?? 0) - $contreValeur - $depenses - $fournisseurs + $tresorerie,
        ];
    }

    /** @param  array<int,int>|null  $billetage  comptage billet par billet (coupure => nombre) ; s'il est donné, il fait foi */
    /** @param  array<string,float>|null  $devisesComptees  billets en devises comptés (code => montant) */
    public function cloturer(User $caissier, int $especesComptees, ?string $motif, ?string $note, User $auteur, ?array $billetage = null, ?array $devisesComptees = null, ?int $laisse = null): ClotureCaisse
    {
        [$billetage, $totalBillets] = ClotureCaisse::compterBillets($billetage ?? []);
        if ($billetage) {
            $especesComptees = $totalBillets;
        }

        return DB::transaction(function () use ($caissier, $especesComptees, $motif, $note, $auteur, $billetage, $devisesComptees, $laisse) {
            if ($this->estCloturee($caissier)) {
                throw new OperationRefusee("La caisse de {$caissier->nomComplet()} est déjà clôturée aujourd'hui.");
            }
            $bilan = $this->bilan($caissier, now());
            if ($laisse !== null && $laisse > $especesComptees) {
                throw new OperationRefusee('Vous ne pouvez pas laisser dans le tiroir plus que les espèces comptées ('.gnf($especesComptees).').');
            }
            $ecart = $especesComptees - $bilan['especes_theoriques'];
            if ($ecart !== 0 && ! trim((string) $motif)) {
                throw new OperationRefusee('Écart de '.gnf(abs($ecart)).' ('.($ecart > 0 ? 'excédent' : 'manque').') : indiquez-en le motif avant de clôturer.');
            }
            $devises = [];
            foreach ($bilan['devises'] as $code => $d) {
                $compte = round((float) ($devisesComptees[$code] ?? 0), 2);
                $devises[$code] = ['attendu' => $d['attendu'], 'compte' => $compte];
                if ($compte != $d['attendu'] && ! trim((string) $motif)) {
                    throw new OperationRefusee('Écart sur les '.config('gestion.devises.'.$code.'.0').' : '.devise($d['attendu'], $code).' attendus, '
                        .devise($compte, $code).' comptés. Indiquez-en le motif avant de clôturer.');
                }
            }
            $avecEcart = $ecart !== 0 || collect($devises)->contains(fn ($d) => $d['compte'] != $d['attendu']);

            $cloture = ClotureCaisse::create([
                'user_id' => $caissier->id, 'jour' => now()->toDateString(),
                'nb_ventes' => $bilan['nb_ventes'], 'total_ventes' => $bilan['total_ventes'], 'encaissements' => $bilan['net'],
                'sorties_especes' => $bilan['sorties_especes'], 'fond_caisse' => $bilan['fond'], 'laisse_en_caisse' => $laisse,
                'especes_theoriques' => $bilan['especes_theoriques'], 'especes_comptees' => $especesComptees, 'billetage' => $billetage ?: null, 'devises' => $devises ?: null, 'ecart' => $ecart,
                'motif_ecart' => $avecEcart ? trim($motif) : null, 'note' => $note, 'cloturee_par' => $auteur->id,
            ]);
            // Rapport Z signé dans le registre inaltérable ; son empreinte est imprimée sur le rapport
            $cloture->forceFill(['empreinte' => app(Registre::class)->inscrire($cloture->boutique_id, 'cloture', $cloture->id, Registre::donneesCloture($cloture))])->saveQuietly();
            JournalActivite::noter('caisse', "Clôture de caisse de {$caissier->nomComplet()} : ".gnf($cloture->totalEncaisse())
                .' encaissés, '.$cloture->libelleEcart().($cloture->motif_ecart ? " ({$cloture->motif_ecart})" : ''));

            return $cloture;
        });
    }

    public function rouvrir(ClotureCaisse $cloture, User $admin): void
    {
        if (! $admin->role?->systeme) {
            throw new OperationRefusee("Seul l'administrateur de la boutique peut rouvrir une caisse.");
        }
        if (! $cloture->jour->isToday()) {
            throw new OperationRefusee('Seule la clôture du jour peut être rouverte ; les journées passées restent figées.');
        }
        $cloture->delete();
        JournalActivite::noter('caisse', "Réouverture de la caisse de {$cloture->caissier?->nomComplet()} du ".$cloture->jour->format('d/m/Y').' par '.$admin->nomComplet());
    }
}
