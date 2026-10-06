<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Client;
use App\Models\Paiement;
use App\Models\PointFidelite;
use App\Models\Vente;

/**
 * Programme de fidélité.
 *
 * - Gain : taux % de chaque somme réellement payée par un client identifié (comptant ou remboursement
 *   de crédit) ; une vente à crédit ne rapporte des points qu'au fur et à mesure qu'elle est payée.
 * - Utilisation : 1 point = 1 GNF, en paiement à la caisse, à partir du minimum fixé par la boutique.
 * - Un remboursement (retour) reprend les points gagnés sur la somme rendue ; une annulation
 *   reprend les points gagnés et rend les points utilisés.
 * - Les points payés ne rapportent pas de points et ne sont pas de l'argent (hors trésorerie).
 */
class Fidelite
{
    public const MODE = 'fidelite';

    public function actif(): bool
    {
        return (float) (boutique()?->fidelite_taux ?? 0) > 0 && fonction('fidelite');
    }

    public function surPaiement(Paiement $p): void
    {
        if (! $this->actif() || ! $p->client_id || in_array($p->mode, [self::MODE, Avoirs::MODE], true)) {
            return;
        }
        $points = (int) floor(abs($p->montant) * (float) boutique()->fidelite_taux / 100) * ($p->montant < 0 ? -1 : 1);
        if ($points !== 0) {
            $this->mouvement($p->client_id, $p->vente_id, $points, $p->montant < 0 ? 'Remboursement : points repris' : 'Points gagnés');
        }
    }

    /** Points qu'un client peut utiliser sur un montant donné (0 si sous le minimum). */
    public function utilisables(?Client $client, int $montant): int
    {
        if (! $this->actif() || ! $client || $client->points <= 0 || $client->points < (int) boutique()->fidelite_minimum) {
            return 0;
        }

        return max(0, min($client->points, $montant));
    }

    public function utiliser(Client $client, Vente $vente, int $points): void
    {
        $client = Client::whereKey($client->id)->lockForUpdate()->first();
        if ($points > $client->points) {
            throw new OperationRefusee("{$client->nomComplet()} n'a que ".number_format($client->points, 0, ',', ' ').' points.');
        }
        $this->mouvement($client->id, $vente->id, -$points, 'Points utilisés');
    }

    /** Points utilisés sur une vente rendus au client (retour de marchandise). */
    public function rendre(Vente $vente, int $points, string $motif): void
    {
        if ($points > 0 && $vente->client_id) {
            $this->mouvement($vente->client_id, $vente->id, $points, $motif);
        }
    }

    /** Vente annulée : on reprend ce qu'elle a rapporté et on rend ce qui a été utilisé. */
    public function annulerVente(Vente $vente): void
    {
        $solde = (int) PointFidelite::where('vente_id', $vente->id)->sum('points');
        if ($solde !== 0 && $vente->client_id) {
            $this->mouvement($vente->client_id, $vente->id, -$solde, 'Annulation de la vente '.$vente->numero);
        }
    }

    private function mouvement(int $clientId, ?int $venteId, int $points, string $motif): void
    {
        PointFidelite::create(['client_id' => $clientId, 'vente_id' => $venteId, 'points' => $points, 'motif' => $motif, 'user_id' => auth()->id()]);
        Client::whereKey($clientId)->increment('points', $points);
    }
}
