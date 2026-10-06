<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Approvisionnement;
use App\Models\JournalActivite;
use App\Models\Produit;
use Illuminate\Support\Facades\DB;

class ApprovisionnementService
{
    public function __construct(private StockService $stock, private NumeroService $numeros)
    {
    }

    /** Réception de marchandise : augmente le stock et met à jour le prix d'achat (« Nouveau PAU »). */
    public function creer(array $donnees): Approvisionnement
    {
        return DB::transaction(function () use ($donnees) {
            $lignes = collect($donnees['lignes'] ?? [])->filter(fn ($l) => ($l['quantite'] ?? 0) > 0);
            if ($lignes->isEmpty()) {
                throw new OperationRefusee('Ajoutez au moins un produit reçu.');
            }
            boutique()?->verifierPeriodeOuverte($donnees['date_appro'] ?? now(), 'enregistrer une réception à cette date');

            $appro = Approvisionnement::create([
                'numero' => $this->numeros->suivant('appro', 'AP'),
                'fournisseur_id' => $donnees['fournisseur_id'] ?? null,
                'date_appro' => $donnees['date_appro'] ?? now()->toDateString(),
                'note' => $donnees['note'] ?? null,
                'user_id' => auth()->id(),
            ]);

            $total = 0;
            foreach ($lignes as $l) {
                $produit = Produit::findOrFail($l['produit_id']);
                // Reçu à l'unité ou par conditionnement (10 cartons de 12 = 120 unités en stock)
                $parCond = ! empty($l['conditionnement']) && $produit->aConditionnement();
                $facteur = $parCond ? (float) $produit->qte_conditionnement : 1.0;
                $prixLigne = (int) $l['prix_achat_unitaire'];                  // prix de l'unité reçue (unité ou carton)
                $quantiteLigne = round((float) $l['quantite'], 2);
                $ligneTotal = (int) round($prixLigne * $quantiteLigne);
                $total += $ligneTotal;

                $appro->lignes()->create([
                    'produit_id' => $produit->id,
                    'designation' => $produit->designation.($parCond ? ' ('.$produit->libelleConditionnement().')' : ''),
                    'quantite' => $quantiteLigne,
                    'facteur' => $facteur,
                    'unite' => $parCond ? $produit->conditionnement : $produit->unite,
                    'prix_achat_unitaire' => $prixLigne,
                    'total' => $ligneTotal,
                    'date_peremption' => $l['date_peremption'] ?? null,
                ]);

                // Le coût et le stock sont tenus à l'unité de base
                $quantite = round($quantiteLigne * $facteur, 2);
                $pau = (int) round($prixLigne / $facteur);
                $stockAvant = max(0.0, (float) $produit->stock);
                $prixAchatAvant = (int) $produit->prix_achat;
                $this->stock->mouvement($produit, 'approvisionnement', $quantite, $appro, 'Approvisionnement '.$appro->numero);

                // Coût d'achat : dernier prix payé, ou coût moyen pondéré (stock existant + quantité reçue)
                $nouveauCout = $prixAchatAvant;
                if ($pau > 0) {
                    $nouveauCout = boutique()?->methode_cout === 'cmp' && $stockAvant > 0 && $prixAchatAvant > 0
                        ? (int) round(($stockAvant * $prixAchatAvant + $quantite * $pau) / ($stockAvant + $quantite))
                        : $pau;
                }
                $prixVente = ! empty($l['prix_vente']) ? (int) $l['prix_vente'] : (int) $produit->prix_vente;
                if (! boutique()?->vente_a_perte && $prixVente < $nouveauCout) {
                    throw new OperationRefusee("« {$produit->designation} » : le coût d'achat (".gnf($nouveauCout).') dépasse le prix de vente ('
                        .gnf($prixVente).'). Indiquez un nouveau prix de vente pour ce produit.');
                }
                if (! boutique()?->vente_a_perte && $produit->prix_gros && $produit->prix_gros < $nouveauCout) {
                    throw new OperationRefusee("« {$produit->designation} » : le coût d'achat (".gnf($nouveauCout).') dépasse le prix de gros ('
                        .gnf($produit->prix_gros).'). Mettez à jour le prix de gros du produit avant cette réception.');
                }
                \App\Models\HistoriquePrix::depuis('Réception '.$appro->numero,
                    fn () => $produit->update(['prix_achat' => $nouveauCout, 'prix_vente' => $prixVente]));
            }

            $appro->update(['total' => $total]);
            // Réception d'une commande fournisseur : les quantités reçues sont imputées sur la commande
            if (! empty($donnees['commande_fournisseur_id'])) {
                $commande = \App\Models\CommandeFournisseur::findOrFail($donnees['commande_fournisseur_id']);
                $appro->update(['commande_fournisseur_id' => $commande->id]);
                app(CommandesFournisseur::class)->imputerReception($commande, $appro->load('lignes'));
            }
            // Règlement : comptant (par défaut), en partie ou à crédit
            $reglement = $donnees['reglement'] ?? 'comptant';
            $paye = match ($reglement) {
                'credit' => 0,
                'partiel' => min(max(0, (int) ($donnees['montant_paye'] ?? 0)), $total),
                default => $total,
            };
            if ($paye < $total && ! $appro->fournisseur_id) {
                throw new OperationRefusee('Une réception non payée (ou payée en partie) doit être rattachée à un fournisseur : choisissez-le.');
            }
            if ($paye < $total) {
                $echeance = $donnees['echeance'] ?? $appro->date_appro->copy()->addDays(DetteFournisseurService::ECHEANCE_PAR_DEFAUT_JOURS)->toDateString();
                $appro->update(['echeance' => $echeance]);
            }
            app(DetteFournisseurService::class)->payerReception($appro->refresh(), $paye, $donnees['mode_reglement'] ?? 'especes',
                $donnees['reference_reglement'] ?? null, auth()->user());

            JournalActivite::noter('approvisionnement', "Approvisionnement {$appro->numero} de ".gnf($total)
                .($paye < $total ? ' — reste dû au fournisseur : '.gnf($total - $paye) : ''));

            return $appro->fresh('lignes');
        });
    }
}
