<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Avoir;
use App\Models\Client;
use App\Models\Paiement;
use App\Models\Retour;
use App\Models\Vente;

/**
 * Avoirs clients (bons d'achat).
 *
 * - Un retour peut être rendu en avoir au lieu d'espèces : l'argent reste dans la boutique,
 *   le client est crédité et revient dépenser son avoir. Il faut donc un client identifié.
 * - L'avoir se dépense à la caisse comme un moyen de paiement (mode « avoir »), jamais au-delà du solde.
 * - Ce n'est pas de l'argent encaissé : il n'entre ni dans le tiroir, ni en trésorerie, ni dans les points de fidélité.
 * - Une vente annulée rend au client l'avoir qu'il avait dépensé dessus.
 */
class Avoirs
{
    public const MODE = 'avoir';

    public function crediter(Client $client, int $montant, Vente $vente, Retour $retour): void
    {
        if ($montant > 0) {
            $this->mouvement($client->id, $vente->id, $retour->id, $montant, "Retour {$retour->numero} ({$vente->numero})");
        }
    }

    /** Montant d'avoir utilisable sur un achat (plafonné au solde et au montant dû). */
    public function utilisable(?Client $client, int $montant): int
    {
        return $client && $client->avoir > 0 ? max(0, min((int) $client->avoir, $montant)) : 0;
    }

    public function utiliser(Client $client, Vente $vente, int $montant): void
    {
        $client = Client::whereKey($client->id)->lockForUpdate()->first();
        if ($montant > $client->avoir) {
            throw new OperationRefusee("{$client->nomComplet()} n'a que ".gnf($client->avoir).' d\'avoir.');
        }
        $this->mouvement($client->id, $vente->id, null, -$montant, "Utilisé sur la vente {$vente->numero}");
    }

    /** Vente annulée : l'avoir dépensé dessus est rendu au client. */
    public function annulerVente(Vente $vente): void
    {
        $utilise = (int) Paiement::where('vente_id', $vente->id)->where('mode', self::MODE)->where('montant', '>', 0)->sum('montant');
        if ($utilise > 0 && $vente->client_id) {
            $this->mouvement($vente->client_id, $vente->id, null, $utilise, "Annulation de la vente {$vente->numero}");
        }
    }

    /** Des articles de la vente ont été repris en avoir (le client garde cet avoir). */
    public function avoirEmisSur(Vente $vente): bool
    {
        return Avoir::where('vente_id', $vente->id)->whereNotNull('retour_id')->exists();
    }

    private function mouvement(int $clientId, ?int $venteId, ?int $retourId, int $montant, string $motif): void
    {
        Avoir::create(['client_id' => $clientId, 'vente_id' => $venteId, 'retour_id' => $retourId, 'montant' => $montant, 'motif' => $motif, 'user_id' => auth()->id()]);
        Client::whereKey($clientId)->increment('avoir', $montant);
    }
}
