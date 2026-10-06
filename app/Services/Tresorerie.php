<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Depense;
use App\Models\JournalActivite;
use App\Models\OperationTresorerie;
use App\Models\Paiement;
use App\Models\PaiementFournisseur;
use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Trésorerie par compte : caisse, Orange Money, MTN, autres portefeuilles, banque.
 *
 * Solde d'un compte = dernier constat de solde (argent réellement compté ou lu sur le relevé)
 * + encaissements − remboursements (annulations, retours) − dépenses − règlements fournisseurs
 * ± transferts (les frais sont payés par le compte de départ) + apports − retraits de l'exploitant.
 * Sans constat, le calcul part de la première opération enregistrée.
 */
class Tresorerie
{
    public static function comptes(): array
    {
        return config('gestion.comptes_tresorerie');
    }

    /** Compte sur lequel arrive un moyen de paiement (un moyen inconnu va dans « Autres moyens »). */
    public static function compteDuMode(?string $mode): string
    {
        foreach (self::comptes() as $compte => [, , $modes]) {
            if (in_array($mode, $modes, true)) {
                return $compte;
            }
        }

        return 'autre';
    }

    /** @return string[] moyens de paiement rattachés au compte (pour « autre » : tous les moyens non répertoriés aussi) */
    private function modes(string $compte): array
    {
        return self::comptes()[$compte][2];
    }

    private function filtrerModes($requete, string $compte, string $colonne = 'mode')
    {
        if ($compte !== 'autre') {
            return $requete->whereIn($colonne, $this->modes($compte));
        }
        $connus = collect(self::comptes())->except('autre')->flatMap(fn ($c) => $c[2])->push(Fidelite::MODE)->push(Avoirs::MODE)->push(PaiementFournisseur::MODE_AVOIR)->push(Acomptes::MODE)->push(CartesCadeaux::MODE)->all();

        return $requete->where(fn ($q) => $q->whereNotIn($colonne, $connus)->orWhereNull($colonne));
    }

    /** @return array<string, array{libelle:string, icone:string, solde:int, constat:?OperationTresorerie}> */
    public function soldes(?Carbon $au = null): array
    {
        $au ??= now();
        $resultat = [];
        foreach (self::comptes() as $compte => [$libelle, $icone]) {
            $constat = OperationTresorerie::where('type', 'constat')->where('compte_destination', $compte)
                ->where('date_operation', '<=', $au)->latest('date_operation')->latest('id')->first();
            $depuis = $constat?->date_operation;
            $resultat[$compte] = ['libelle' => $libelle, 'icone' => $icone, 'constat' => $constat,
                'solde' => ($constat?->montant ?? 0) + $this->flux($compte, $depuis, $au, $constat?->id)];
        }

        return $resultat;
    }

    /**
     * Somme des entrées − sorties d'un compte entre deux instants (début exclu).
     * Calculée par la base (sommes SQL), avec les mêmes filtres que lignes() : rien n'est chargé en mémoire,
     * même avec des années de ventes.
     */
    public function flux(string $compte, ?Carbon $depuis, Carbon $au, ?int $apresOperation = null): int
    {
        $entre = fn ($q, string $col) => $q->where($col, '<=', $au)->when($depuis, fn ($q) => $q->where($col, '>', $depuis));

        $total = (int) $entre($this->filtrerModes(Paiement::query(), $compte), 'date_paiement')->sum('montant');
        // Vente annulée : l'argent reçu est rendu au client le jour de l'annulation
        $total -= (int) $this->filtrerModes(Paiement::join('ventes', 'ventes.id', '=', 'paiements.vente_id')
            ->where('ventes.statut', 'annulee'), $compte, 'paiements.mode')
            ->where('ventes.annulee_le', '<=', $au)->when($depuis, fn ($q) => $q->where('ventes.annulee_le', '>', $depuis))
            ->sum('paiements.montant');
        $total += (int) $entre($this->filtrerModes(\App\Models\Acompte::query(), $compte), 'date_versement')->sum('montant');
        $total += (int) $entre($this->filtrerModes(\App\Models\MouvementCarteCadeau::whereNotNull('mode'), $compte), 'date_mouvement')->sum('montant');
        $total -= (int) $entre($this->filtrerModes(Depense::query(), $compte), 'created_at')->sum('montant');
        $total -= (int) $entre($this->filtrerModes(PaiementFournisseur::query(), $compte), 'date_paiement')->sum('montant');   // remboursement : négatif
        // Opérations de trésorerie : entrées sur le compte de destination, sorties (avec frais) du compte source
        $operations = fn () => OperationTresorerie::where('type', '!=', 'constat')->where('date_operation', '<=', $au)
            ->when($apresOperation, fn ($q) => $q->where('id', '>', $apresOperation),
                fn ($q) => $q->when($depuis, fn ($q) => $q->where('date_operation', '>', $depuis)));
        $total += (int) $operations()->where('compte_destination', $compte)->sum('montant');
        $total -= (int) $operations()->where('compte_source', $compte)->sum(DB::raw('montant + COALESCE(frais, 0)'));

        return $total;
    }

    /** Détail des mouvements d'un compte (journal), du plus ancien au plus récent. */
    public function lignes(string $compte, ?Carbon $depuis, Carbon $au, ?int $apresOperation = null): Collection
    {
        $entre = fn ($q, string $col) => $q->where($col, '<=', $au)->when($depuis, fn ($q) => $q->where($col, '>', $depuis));

        $paiements = $entre($this->filtrerModes(Paiement::with('vente:id,numero'), $compte), 'date_paiement')->get()
            ->map(fn (Paiement $p) => ['date' => $p->date_paiement, 'libelle' => ($p->montant < 0 ? 'Remboursement ' : 'Encaissement ').$p->vente?->numero,
                'entree' => max(0, $p->montant), 'sortie' => max(0, -$p->montant)]);
        // Vente annulée : l'argent reçu est rendu au client le jour de l'annulation
        $annulations = $this->filtrerModes(Paiement::join('ventes', 'ventes.id', '=', 'paiements.vente_id')
            ->where('ventes.statut', 'annulee'), $compte, 'paiements.mode')
            ->where('ventes.annulee_le', '<=', $au)->when($depuis, fn ($q) => $q->where('ventes.annulee_le', '>', $depuis))
            ->get(['paiements.montant', 'ventes.annulee_le', 'ventes.numero'])
            ->map(fn ($p) => ['date' => Carbon::parse($p->annulee_le), 'libelle' => 'Annulation '.$p->numero, 'entree' => 0, 'sortie' => (int) $p->montant]);
        // Acomptes sur commandes : l'argent arrive le jour du versement (et repart si la commande est annulée)
        $acomptes = $entre($this->filtrerModes(\App\Models\Acompte::with('devis:id,numero'), $compte), 'date_versement')->get()
            ->map(fn ($a) => ['date' => $a->date_versement, 'libelle' => ($a->montant < 0 ? 'Acompte remboursé ' : 'Acompte ').$a->devis?->numero,
                'entree' => max(0, $a->montant), 'sortie' => max(0, -$a->montant)]);
        // Cartes cadeaux : l'argent arrive le jour où la carte est vendue (et repart si son solde est rendu)
        $cartes = $entre($this->filtrerModes(\App\Models\MouvementCarteCadeau::with('carte:id,code')->whereNotNull('mode'), $compte), 'date_mouvement')->get()
            ->map(fn ($m) => ['date' => $m->date_mouvement, 'libelle' => ($m->montant < 0 ? 'Carte cadeau remboursée ' : 'Carte cadeau vendue ').$m->carte?->codeMasque(),
                'entree' => max(0, $m->montant), 'sortie' => max(0, -$m->montant)]);
        $depenses = $entre($this->filtrerModes(Depense::query(), $compte), 'created_at')->get()
            ->map(fn (Depense $d) => ['date' => $d->created_at, 'libelle' => 'Dépense : '.$d->motif, 'entree' => 0, 'sortie' => $d->montant]);
        $fournisseurs = $entre($this->filtrerModes(PaiementFournisseur::with('fournisseur:id,nom'), $compte), 'date_paiement')->get()
            ->map(fn (PaiementFournisseur $p) => ['date' => $p->date_paiement, 'libelle' => ($p->montant < 0 ? 'Remboursement fournisseur ' : 'Règlement fournisseur ').($p->fournisseur?->nom ?? ''),
                'entree' => max(0, -$p->montant), 'sortie' => max(0, $p->montant)]);   // remboursement après retour : montant négatif
        // Opérations de trésorerie : repérées par leur ordre d'enregistrement (fiable même dans la seconde du constat)
        $operations = OperationTresorerie::where('type', '!=', 'constat')
            ->where(fn ($q) => $q->where('compte_source', $compte)->orWhere('compte_destination', $compte))
            ->where('date_operation', '<=', $au)
            ->when($apresOperation, fn ($q) => $q->where('id', '>', $apresOperation),
                fn ($q) => $q->when($depuis, fn ($q) => $q->where('date_operation', '>', $depuis)))->get()
            ->map(fn (OperationTresorerie $o) => ['date' => $o->date_operation, 'libelle' => $o->libelle().($o->motif ? " ({$o->motif})" : '')
                .($o->frais && $o->compte_source === $compte ? ' — frais '.gnf($o->frais) : ''),
                'entree' => $o->compte_destination === $compte ? $o->montant : 0,
                'sortie' => $o->compte_source === $compte ? $o->montant + $o->frais : 0]);

        return $paiements->concat($annulations)->concat($acomptes)->concat($cartes)->concat($depenses)->concat($fournisseurs)->concat($operations)
            ->sortBy(fn ($l) => $l['date']->timestamp)->values();
    }

    /** Transfert, apport, retrait ou constat de solde. */
    public function enregistrer(array $d, User $auteur): OperationTresorerie
    {
        return DB::transaction(function () use ($d, $auteur) {
            $type = $d['type'];
            $montant = (int) $d['montant'];
            $frais = $type === 'transfert' ? (int) ($d['frais'] ?? 0) : 0;
            $source = in_array($type, ['transfert', 'retrait'], true) ? $d['compte_source'] : null;
            $destination = in_array($type, ['transfert', 'apport', 'constat'], true) ? $d['compte_destination'] : null;

            if ($type === 'transfert' && $source === $destination) {
                throw new OperationRefusee('Choisissez deux comptes différents pour un transfert.');
            }
            if ($type !== 'constat' && $montant <= 0) {
                throw new OperationRefusee('Le montant doit être supérieur à zéro.');
            }
            // L'argent des espèces sort ou entre dans le tiroir du caissier : sa caisse du jour doit être ouverte
            if (in_array('caisse', [$source, $destination], true) && $type !== 'constat') {
                app(CaisseService::class)->verifierOuverte($auteur);
            }
            $soldes = $this->soldes();
            if ($source && $soldes[$source]['solde'] < $montant + $frais) {
                throw new OperationRefusee(OperationTresorerie::libelleCompte($source).' : solde calculé de '.gnf($soldes[$source]['solde'])
                    .', insuffisant pour sortir '.gnf($montant + $frais).'. Si ce solde est faux, faites d\'abord un constat de solde.');
            }

            $o = OperationTresorerie::create([
                'type' => $type, 'compte_source' => $source, 'compte_destination' => $destination,
                'montant' => $montant, 'frais' => $frais,
                'ecart' => $type === 'constat' ? $montant - $soldes[$destination]['solde'] : null,
                'motif' => $d['motif'] ?? null, 'reference' => $d['reference'] ?? null,
                'date_operation' => now(), 'user_id' => $auteur->id,
            ]);
            JournalActivite::noter('tresorerie', $o->libelle().' : '.gnf($montant).($frais ? ' (frais '.gnf($frais).')' : '')
                .($o->ecart ? ' — écart '.($o->ecart > 0 ? '+' : '').gnf($o->ecart) : ''));

            return $o;
        });
    }

    /** Espèces entrées (+) ou sorties (−) du tiroir d'un caissier par des opérations de trésorerie ce jour-là. */
    public function netEspecesCaissier(User $caissier, Carbon $debut, Carbon $fin): int
    {
        return (int) OperationTresorerie::where('user_id', $caissier->id)->where('type', '!=', 'constat')
            ->whereBetween('date_operation', [$debut, $fin])->get()
            ->sum(fn ($o) => ($o->compte_destination === 'caisse' ? $o->montant : 0) - ($o->compte_source === 'caisse' ? $o->montant + $o->frais : 0));
    }
}
