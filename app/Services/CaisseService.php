<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\ClotureCaisse;
use App\Models\Depense;
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
            'especes_theoriques' => ($net['especes'] ?? 0) - $depenses - $fournisseurs + $tresorerie,
        ];
    }

    /** @param  array<int,int>|null  $billetage  comptage billet par billet (coupure => nombre) ; s'il est donné, il fait foi */
    public function cloturer(User $caissier, int $especesComptees, ?string $motif, ?string $note, User $auteur, ?array $billetage = null): ClotureCaisse
    {
        [$billetage, $totalBillets] = ClotureCaisse::compterBillets($billetage ?? []);
        if ($billetage) {
            $especesComptees = $totalBillets;
        }

        return DB::transaction(function () use ($caissier, $especesComptees, $motif, $note, $auteur, $billetage) {
            if ($this->estCloturee($caissier)) {
                throw new OperationRefusee("La caisse de {$caissier->nomComplet()} est déjà clôturée aujourd'hui.");
            }
            $bilan = $this->bilan($caissier, now());
            $ecart = $especesComptees - $bilan['especes_theoriques'];
            if ($ecart !== 0 && ! trim((string) $motif)) {
                throw new OperationRefusee('Écart de '.gnf(abs($ecart)).' ('.($ecart > 0 ? 'excédent' : 'manque').') : indiquez-en le motif avant de clôturer.');
            }

            $cloture = ClotureCaisse::create([
                'user_id' => $caissier->id, 'jour' => now()->toDateString(),
                'nb_ventes' => $bilan['nb_ventes'], 'total_ventes' => $bilan['total_ventes'], 'encaissements' => $bilan['net'],
                'sorties_especes' => $bilan['sorties_especes'],
                'especes_theoriques' => $bilan['especes_theoriques'], 'especes_comptees' => $especesComptees, 'billetage' => $billetage ?: null, 'ecart' => $ecart,
                'motif_ecart' => $ecart !== 0 ? trim($motif) : null, 'note' => $note, 'cloturee_par' => $auteur->id,
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
