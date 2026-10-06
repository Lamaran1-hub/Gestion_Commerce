<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Approvisionnement;
use App\Models\JournalActivite;
use App\Models\LigneApprovisionnement;
use App\Models\PaiementFournisseur;
use App\Models\RetourFournisseur;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Retour de marchandise au fournisseur.
 *
 * Règles :
 * - le retour porte sur une réception rattachée à un fournisseur ; on ne renvoie pas plus que ce qui a été reçu
 *   (moins ce qui a déjà été renvoyé), ni plus que ce qui reste en stock ;
 * - la valeur est celle du prix d'achat payé sur cette réception ;
 * - elle se déduit d'abord de ce que la boutique doit encore sur la réception ;
 * - si la réception était déjà payée, le surplus est rendu par le fournisseur : en argent (règlement négatif,
 *   qui rentre dans la caisse ou sur le compte choisi) ou en avoir, à utiliser pour régler une prochaine livraison ;
 * - les produits sortent du stock (mouvement « Retour fournisseur »).
 */
class RetourFournisseurService
{
    public function __construct(private StockService $stock, private NumeroService $numeros, private CaisseService $caisse)
    {
    }

    /**
     * @param  array<int|string, float|string>  $quantites  ligne_approvisionnement_id => quantité renvoyée (unité de la réception)
     */
    public function enregistrer(Approvisionnement $appro, array $quantites, string $motif, string $modeRemboursement, ?string $note, User $auteur): RetourFournisseur
    {
        $quantites = array_filter(array_map(fn ($q) => round((float) str_replace([',', ' '], ['.', ''], (string) $q), 2), $quantites), fn ($q) => $q > 0);
        if (! $quantites) {
            throw new OperationRefusee('Indiquez au moins une quantité à renvoyer au fournisseur.');
        }

        return DB::transaction(function () use ($appro, $quantites, $motif, $modeRemboursement, $note, $auteur) {
            $a = Approvisionnement::whereKey($appro->id)->lockForUpdate()->firstOrFail();
            if (! $a->fournisseur_id) {
                throw new OperationRefusee('Cette réception n\'est rattachée à aucun fournisseur. Pour un produit abîmé ou perdu, faites plutôt un ajustement de stock.');
            }
            boutique()?->verifierPeriodeOuverte(now(), 'enregistrer un retour fournisseur');

            $lignes = LigneApprovisionnement::where('approvisionnement_id', $a->id)->whereIn('id', array_keys($quantites))
                ->lockForUpdate()->get()->keyBy('id');
            if ($lignes->count() !== count($quantites)) {
                throw new OperationRefusee('Une ligne du retour n\'appartient pas à cette réception.');
            }

            $valeur = 0;
            foreach ($quantites as $id => $q) {
                $l = $lignes[$id];
                if ($q > $l->quantiteRetournable() + 0.001) {
                    throw new OperationRefusee("« {$l->designation} » : ".qte($q).' à renvoyer, mais seulement '.qte($l->quantiteRetournable()).' reçu(s) et pas encore renvoyé(s).');
                }
                $valeur += (int) round($l->prix_achat_unitaire * $q);
            }
            $valeur = min($valeur, $a->netAPayer());   // arrondis : jamais plus que la réception elle-même

            $deduit = min($valeur, $a->resteAPayer());
            $aRendre = $valeur - $deduit;              // déjà payé : le fournisseur doit le rendre
            $avoir = $modeRemboursement === PaiementFournisseur::MODE_AVOIR;
            if ($aRendre > 0 && ! $avoir && $modeRemboursement === 'especes') {
                $this->caisse->verifierOuverte($auteur); // l'argent rentre dans le tiroir : la caisse doit être ouverte
            }

            $retour = RetourFournisseur::create([
                'numero' => $this->numeros->suivant('retour_fournisseur', 'RF'),
                'approvisionnement_id' => $a->id, 'fournisseur_id' => $a->fournisseur_id,
                'montant' => $valeur, 'deduit' => $deduit, 'rembourse' => $aRendre,
                'mode_remboursement' => $aRendre > 0 ? $modeRemboursement : null,
                'motif' => $motif, 'note' => $note, 'user_id' => $auteur->id,
            ]);

            foreach ($quantites as $id => $q) {
                $l = $lignes[$id];
                $facteur = $l->facteur ?: 1;
                $retour->lignes()->create([
                    'ligne_approvisionnement_id' => $l->id, 'produit_id' => $l->produit_id, 'designation' => $l->designation,
                    'quantite' => $q, 'facteur' => $facteur, 'unite' => $l->unite,
                    'prix_achat_unitaire' => $l->prix_achat_unitaire, 'total' => (int) round($l->prix_achat_unitaire * $q),
                ]);
                $l->update(['quantite_retournee' => round($l->quantite_retournee + $q, 2)]);
                if ($l->produit) {
                    // Un carton renvoyé retire ses 12 unités du stock ; refusé si elles ne sont plus en rayon
                    $this->stock->mouvement($l->produit, 'retour_fournisseur', -round($q * $facteur, 2), $retour, "Retour fournisseur {$retour->numero} ({$a->numero})");
                }
            }

            if ($aRendre > 0) {
                PaiementFournisseur::create([
                    'fournisseur_id' => $a->fournisseur_id, 'approvisionnement_id' => $a->id, 'montant' => -$aRendre,
                    'mode' => $modeRemboursement, 'reference' => "Retour {$retour->numero}", 'date_paiement' => now(), 'user_id' => $auteur->id,
                ]);
            }
            $a->update(['montant_retourne' => $a->montant_retourne + $valeur, 'montant_paye' => $a->montant_paye - $aRendre]);

            JournalActivite::noter('fournisseur', "Retour fournisseur {$retour->numero} ({$a->numero}, {$a->fournisseur?->nom}) : ".gnf($valeur)
                ." — {$motif} — ".$retour->libelleReglement());

            return $retour->load('lignes');
        });
    }
}
