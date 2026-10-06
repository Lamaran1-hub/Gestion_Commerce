<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\JournalActivite;
use App\Models\Vente;

/**
 * Livraison des ventes chez le client.
 *
 * Règles :
 * - seule une vente validée se livre ; le stock est déjà sorti à la vente (la marchandise est réservée au client) ;
 * - étapes : à livrer → en route (avec le livreur) → livrée (avec le nom de la personne qui a réceptionné) ;
 * - une livraison faite ne se modifie plus ; une vente livrée ne s'annule pas (faire un retour) ;
 * - si le client n'a pas tout payé, le bon de livraison indique le montant à encaisser à la livraison.
 */
class Livraisons
{
    public function programmer(Vente $vente, array $d): void
    {
        $this->verifier($vente);
        $vente->update([
            'livraison' => $vente->livraison === 'en_route' ? 'en_route' : 'a_livrer',
            'livraison_adresse' => $d['livraison_adresse'], 'livraison_contact' => $d['livraison_contact'] ?? null,
            'livraison_prevue_le' => $d['livraison_prevue_le'] ?? null, 'livreur' => $d['livreur'] ?? $vente->livreur,
        ]);
        JournalActivite::noter('livraison', "Livraison de {$vente->numero} programmée : {$vente->livraison_adresse}"
            .($vente->livraison_prevue_le ? ' le '.$vente->livraison_prevue_le->format('d/m/Y') : ''));
    }

    public function partir(Vente $vente, string $livreur): void
    {
        $this->verifier($vente);
        if (! $vente->livraison) {
            throw new OperationRefusee('Indiquez d\'abord l\'adresse de livraison.');
        }
        $vente->update(['livraison' => 'en_route', 'livreur' => $livreur]);
        JournalActivite::noter('livraison', "Livraison de {$vente->numero} partie avec {$livreur}");
    }

    public function livrer(Vente $vente, string $recuPar): void
    {
        $this->verifier($vente);
        if (! $vente->livraison) {
            throw new OperationRefusee('Cette vente n\'est pas à livrer.');
        }
        $vente->update(['livraison' => 'livree', 'livree_le' => now(), 'livree_a' => $recuPar]);
        JournalActivite::noter('livraison', "Vente {$vente->numero} livrée, reçue par {$recuPar}"
            .($vente->resteAPayer() ? ' — reste à encaisser : '.gnf($vente->resteAPayer()) : ''));
    }

    /** Vente livrée à emporter finalement (le client est passé la prendre) : on retire la livraison. */
    public function retirer(Vente $vente): void
    {
        $this->verifier($vente);
        $vente->update(['livraison' => null, 'livraison_prevue_le' => null, 'livreur' => null]);
    }

    private function verifier(Vente $vente): void
    {
        if ($vente->statut !== 'validee') {
            throw new OperationRefusee('Cette vente est annulée : elle ne se livre pas.');
        }
        if ($vente->livraison === 'livree') {
            throw new OperationRefusee('Cette vente a déjà été livrée le '.$vente->livree_le->format('d/m/Y à H:i').'.');
        }
    }
}
