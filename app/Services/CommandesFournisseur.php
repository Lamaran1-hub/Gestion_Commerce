<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Approvisionnement;
use App\Models\CommandeFournisseur;
use App\Models\JournalActivite;
use App\Models\LigneCommandeFournisseur;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Commandes fournisseurs.
 *
 * Règles :
 * - une commande ne bouge ni le stock ni l'argent : seule la réception le fait ;
 * - les quantités sont tenues en unités de base (un carton de 12 reçu = 12 unités) ;
 * - chaque réception rattachée augmente les quantités reçues ; la commande passe « reçue en partie » puis « reçue » ;
 * - un reliquat qui n'arrivera pas se « solde » ; une commande sans aucune réception peut s'annuler ;
 * - « À commander » déduit ce qui est déjà commandé et pas encore arrivé.
 */
class CommandesFournisseur
{
    public function __construct(private NumeroService $numeros)
    {
    }

    /** @param  array<int|string, float|string>  $quantites  produit_id => quantité (unités de base) */
    public function creer(?int $fournisseurId, array $quantites, ?string $livraisonPrevue, ?string $note, User $auteur): CommandeFournisseur
    {
        $quantites = array_filter(array_map(fn ($q) => round((float) str_replace([',', ' '], ['.', ''], (string) $q), 2), $quantites), fn ($q) => $q > 0);
        if (! $quantites) {
            throw new OperationRefusee('Indiquez au moins une quantité à commander.');
        }

        return DB::transaction(function () use ($fournisseurId, $quantites, $livraisonPrevue, $note, $auteur) {
            $produits = Produit::whereIn('id', array_keys($quantites))->get()->keyBy('id');
            if ($produits->count() !== count($quantites)) {
                throw new OperationRefusee('Un produit de la commande est introuvable.');
            }
            $c = CommandeFournisseur::create([
                'numero' => $this->numeros->suivant('commande_fourn', 'CF'),
                'fournisseur_id' => $fournisseurId, 'date_commande' => now()->toDateString(),
                'livraison_prevue_le' => $livraisonPrevue, 'note' => $note, 'user_id' => $auteur->id,
            ]);
            $total = 0;
            foreach ($quantites as $id => $q) {
                $p = $produits[$id];
                $c->lignes()->create(['produit_id' => $p->id, 'designation' => $p->designation, 'quantite' => $q, 'prix_achat_estime' => (int) $p->prix_achat]);
                $total += (int) round($q * $p->prix_achat);
            }
            $c->update(['total_estime' => $total]);
            JournalActivite::noter('commande_fournisseur', "Commande {$c->numero} à ".($c->fournisseur?->nom ?? 'fournisseur non précisé').' : '.gnf($total).' estimés');

            return $c->load('lignes');
        });
    }

    /** Réception rattachée : les quantités reçues (unités de base) sont imputées sur la commande. */
    public function imputerReception(CommandeFournisseur $commande, Approvisionnement $appro): void
    {
        $c = CommandeFournisseur::whereKey($commande->id)->lockForUpdate()->firstOrFail();
        if (! $c->estEnAttente()) {
            throw new OperationRefusee("La commande {$c->numero} est {$c->libelleEtat()} : on ne peut plus y rattacher de réception.");
        }
        if ($c->fournisseur_id && $appro->fournisseur_id && $c->fournisseur_id !== (int) $appro->fournisseur_id) {
            throw new OperationRefusee("La commande {$c->numero} a été passée à un autre fournisseur.");
        }
        $recu = $appro->lignes->groupBy('produit_id')->map(fn ($ls) => $ls->sum(fn ($l) => $l->quantite * ($l->facteur ?: 1)));
        foreach ($c->lignes as $l) {
            if (isset($recu[$l->produit_id])) {
                $l->update(['quantite_recue' => round($l->quantite_recue + $recu[$l->produit_id], 2)]);
            }
        }
        $toutRecu = $c->lignes->every(fn (LigneCommandeFournisseur $l) => $l->fresh()->reste() <= 0);
        $c->update(['statut' => $toutRecu ? 'recue' : 'partielle']);
    }

    /** Le reliquat n'arrivera pas : la commande est close. */
    public function solder(CommandeFournisseur $c): void
    {
        if (! $c->estEnAttente()) {
            throw new OperationRefusee("Cette commande est déjà {$c->libelleEtat()}.");
        }
        $c->update(['statut' => $c->receptions()->exists() ? 'soldee' : 'annulee']);
        JournalActivite::noter('commande_fournisseur', "Commande {$c->numero} ".($c->statut === 'soldee' ? 'soldée (reliquat abandonné)' : 'annulée'));
    }

    /** Quantités commandées et pas encore reçues, par produit (unités de base). @return Collection<int, float> */
    public function enCommande(): Collection
    {
        return LigneCommandeFournisseur::whereHas('commande', fn ($q) => $q->enAttente())
            ->get()->groupBy('produit_id')->map(fn ($ls) => (float) $ls->sum(fn ($l) => $l->reste()))->filter(fn ($q) => $q > 0);
    }
}
