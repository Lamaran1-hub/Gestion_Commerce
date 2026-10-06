<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Boutique;
use App\Models\Categorie;
use App\Models\JournalActivite;
use App\Models\LigneTransfert;
use App\Models\Produit;
use App\Models\Transfert;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Transferts de marchandise entre boutiques d'un même réseau.
 *
 * 1. Envoi (boutique de départ) : le stock sort aussitôt, la marchandise est « en route ».
 * 2. Réception (boutique d'arrivée) : on compte ce qui est réellement arrivé. Le stock entre ;
 *    un manque est enregistré comme perte en transit (visible dans le rapport des pertes).
 *    Le produit est retrouvé par code-barres ou désignation, sinon créé avec les mêmes prix.
 * 3. Annulation (boutique de départ, avant réception) : la marchandise revient en stock.
 */
class TransfertService
{
    public function __construct(private StockService $stock, private NumeroService $numeros)
    {
    }

    /** @param array<int, array{produit_id:int, quantite:float}> $lignes */
    public function envoyer(Boutique $destination, array $lignes, ?string $note, User $auteur): Transfert
    {
        $source = boutique();
        if (! $source->entreprise_id || $destination->entreprise_id !== $source->entreprise_id || $destination->id === $source->id) {
            throw new OperationRefusee('Choisissez une autre boutique de votre réseau.');
        }
        if ($destination->statut === 'suspendu') {
            throw new OperationRefusee("La boutique « {$destination->nom} » est suspendue : elle ne peut pas recevoir de marchandise.");
        }
        $lignes = collect($lignes)->filter(fn ($l) => (float) ($l['quantite'] ?? 0) > 0);
        if ($lignes->isEmpty()) {
            throw new OperationRefusee('Ajoutez au moins un produit à transférer.');
        }

        return DB::transaction(function () use ($source, $destination, $lignes, $note, $auteur) {
            $t = Transfert::create([
                'entreprise_id' => $source->entreprise_id, 'numero' => $this->numeros->suivant('transfert', 'TR'),
                'boutique_source_id' => $source->id, 'boutique_destination_id' => $destination->id,
                'statut' => 'envoye', 'note' => $note, 'envoye_par' => $auteur->id, 'envoye_le' => now(),
            ]);
            $valeur = 0;
            foreach ($lignes->groupBy('produit_id') as $produitId => $groupe) {
                $p = Produit::findOrFail($produitId); // produit de la boutique de départ (portée de la boutique)
                $q = round((float) $groupe->sum('quantite'), 2);
                // Refusé si le stock ne suffit pas : on n'envoie pas ce qu'on n'a pas
                $this->stock->mouvement($p, 'transfert_sortie', -$q, $t, "Transfert {$t->numero} vers {$destination->nom}");
                $t->lignes()->create([
                    'produit_source_id' => $p->id, 'designation' => $p->designation, 'code_barre' => $p->code_barre,
                    'unite' => $p->unite, 'quantite' => $q, 'cout_unitaire' => (int) $p->prix_achat,
                ]);
                $valeur += (int) round($q * $p->prix_achat);
            }
            $t->update(['valeur' => $valeur]);
            JournalActivite::noter('transfert', "Transfert {$t->numero} envoyé à « {$destination->nom} » : ".gnf($valeur));
            JournalActivite::noterPour($destination->id, 'transfert', "Transfert {$t->numero} en route depuis « {$source->nom} » : à réceptionner");

            return $t;
        });
    }

    /** @param array<int, float> $quantitesRecues ligne_id => quantité arrivée */
    public function recevoir(Transfert $transfert, array $quantitesRecues, ?string $note, User $auteur): Transfert
    {
        $destination = boutique();

        return DB::transaction(function () use ($transfert, $quantitesRecues, $note, $auteur, $destination) {
            $t = Transfert::whereKey($transfert->id)->lockForUpdate()->firstOrFail();
            if ($t->boutique_destination_id !== $destination->id) {
                throw new OperationRefusee('Ce transfert doit être réceptionné par la boutique « '.$t->destination->nom.' ».');
            }
            if ($t->statut !== 'envoye') {
                throw new OperationRefusee('Ce transfert est déjà '.mb_strtolower($t->libelleStatut()).'.');
            }
            $manques = [];
            foreach ($t->lignes as $l) {
                $recu = round((float) ($quantitesRecues[$l->id] ?? $l->quantite), 2);
                if ($recu < 0 || $recu > $l->quantite) {
                    throw new OperationRefusee("« {$l->designation} » : entre 0 et ".qte($l->quantite).' (quantité envoyée).');
                }
                $produit = $this->produitDestination($l);
                $stockAvant = max(0.0, (float) $produit->stock);
                // Tout ce qui est parti entre en stock, puis le manque ressort en perte en transit (traçabilité complète)
                $this->stock->mouvement($produit, 'transfert_entree', $l->quantite, $t, "Transfert {$t->numero} depuis {$t->source->nom}");
                if ($recu < $l->quantite) {
                    $this->stock->mouvement($produit, 'perte_transit', -round($l->quantite - $recu, 2), $t, "Manque à la réception du transfert {$t->numero}");
                    $manques[] = $l->designation.' : '.qte($l->quantite - $recu);
                }
                if ($recu > 0 && $l->cout_unitaire > 0) {
                    $cout = $destination->methode_cout === 'cmp' && $stockAvant > 0 && $produit->prix_achat > 0
                        ? (int) round(($stockAvant * $produit->prix_achat + $recu * $l->cout_unitaire) / ($stockAvant + $recu))
                        : $l->cout_unitaire;
                    \App\Models\HistoriquePrix::depuis('Transfert '.$t->numero, fn () => $produit->update(['prix_achat' => $cout]));
                }
                $l->update(['produit_destination_id' => $produit->id, 'quantite_recue' => $recu]);
            }
            $t->update(['statut' => 'recu', 'recu_par' => $auteur->id, 'recu_le' => now(), 'note_reception' => $note]);
            $resume = "Transfert {$t->numero} reçu".($manques ? ' avec manques ('.implode(', ', $manques).')' : ' complet');
            JournalActivite::noter('transfert', $resume);
            JournalActivite::noterPour($t->boutique_source_id, 'transfert', $resume." par « {$destination->nom} »");

            return $t->fresh('lignes');
        });
    }

    public function annuler(Transfert $transfert, User $auteur): Transfert
    {
        return DB::transaction(function () use ($transfert, $auteur) {
            $t = Transfert::whereKey($transfert->id)->lockForUpdate()->firstOrFail();
            if ($t->boutique_source_id !== boutique()->id) {
                throw new OperationRefusee('Seule la boutique qui a envoyé le transfert peut l\'annuler.');
            }
            if ($t->statut !== 'envoye') {
                throw new OperationRefusee('Ce transfert est déjà '.mb_strtolower($t->libelleStatut()).' : il ne peut plus être annulé.');
            }
            foreach ($t->lignes as $l) {
                if ($p = Produit::withTrashed()->find($l->produit_source_id)) {
                    $this->stock->mouvement($p, 'transfert_annule', $l->quantite, $t, "Annulation du transfert {$t->numero}");
                }
            }
            $t->update(['statut' => 'annule']);
            JournalActivite::noter('transfert', "Transfert {$t->numero} annulé par {$auteur->nomComplet()} : marchandise remise en stock");
            JournalActivite::noterPour($t->boutique_destination_id, 'transfert', "Transfert {$t->numero} annulé par la boutique de départ");

            return $t;
        });
    }

    /** Produit correspondant dans la boutique d'arrivée : même code-barres, sinon même désignation, sinon créé. */
    private function produitDestination(LigneTransfert $l): Produit
    {
        $p = ($l->code_barre ? Produit::where('code_barre', $l->code_barre)->first() : null)
            ?? Produit::whereRaw('LOWER(designation) = ?', [mb_strtolower($l->designation)])->first();
        if ($p) {
            return $p;
        }
        // Nouveau produit dans la boutique d'arrivée : il compte dans la limite de sa formule
        boutique()->verifierLimite('produits');
        $modele = Produit::withoutGlobalScope('boutique')->withTrashed()->find($l->produit_source_id);
        $categorie = $modele?->categorie_id
            ? Categorie::withoutGlobalScope('boutique')->find($modele->categorie_id)?->nom : null;

        return Produit::create(($modele ? $modele->only(['unite', 'conditionnement', 'qte_conditionnement', 'prix_conditionnement',
            'prix_vente', 'prix_gros', 'quantite_gros', 'seuil_alerte']) : ['unite' => $l->unite, 'prix_vente' => $l->cout_unitaire]) + [
            'designation' => $l->designation, 'code_barre' => $l->code_barre, 'prix_achat' => $l->cout_unitaire, 'actif' => true,
            'categorie_id' => $categorie ? Categorie::firstOrCreate(['nom' => $categorie])->id : null,
        ]);
    }
}
