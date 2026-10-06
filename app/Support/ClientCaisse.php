<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Vente;
use Illuminate\Support\Collection;

/**
 * Informations d'un client utiles à la caisse (grossiste, points, avoir, dette, plafond, retard, anniversaire),
 * identiques pour la liste préchargée et pour les clients trouvés par la recherche.
 */
class ClientCaisse
{
    /** Au-delà, la caisse ne précharge qu'une sélection et propose la recherche. */
    public const PRECHARGES_MAX = 300;

    public const COLONNES = ['id', 'nom', 'prenom', 'telephone', 'code', 'grossiste', 'points', 'avoir', 'plafond_credit', 'date_naissance'];

    /** Dette en cours de chaque client (montant, plus ancienne vente, prochaine échéance), indexée par client. */
    public static function dettes(?Collection $clientIds = null): Collection
    {
        return Vente::avecReste()->whereNotNull('client_id')
            ->when($clientIds, fn ($q) => $q->whereIn('client_id', $clientIds))
            ->selectRaw('client_id, SUM(total_ttc - montant_paye) as du, MIN(date_vente) as plus_ancienne, MIN(echeance) as prochaine_echeance')
            ->groupBy('client_id')->get()->keyBy('client_id');
    }

    /** @return array{id:int, libelle:string, grossiste:int, points:int, avoir:int, anniv:int|string, du:int, plafond:int|string, retard:int} */
    public static function donnees(Client $c, $dette): array
    {
        $b = boutique();
        $plafond = $c->plafond_credit ?? $b?->plafond_credit_defaut;
        // Retard : une échéance dépassée, si la règle du délai de crédit est activée dans les paramètres
        $enRetard = $dette && $b?->delai_credit_jours && $dette->prochaine_echeance && $dette->prochaine_echeance < now()->toDateString();

        return [
            'id' => $c->id,
            'libelle' => $c->nomComplet().($c->telephone ? ' — '.$c->telephone : '').($c->grossiste ? ' (grossiste)' : ''),
            'grossiste' => $c->grossiste ? 1 : 0,
            'points' => (int) $c->points,
            'avoir' => (int) $c->avoir,
            'anniv' => $c->joursAvantAnniversaire() ?? '',
            'du' => (int) ($dette->du ?? 0),
            'plafond' => $plafond ?? '',
            'retard' => $enRetard ? 1 : 0,
        ];
    }

    /** Clients préchargés dans la caisse : tous pour une petite boutique, sinon ceux qu'on sert le plus souvent. */
    public static function precharges(?int $choisi = null): array
    {
        $total = Client::count();
        if ($total <= self::PRECHARGES_MAX) {
            $clients = Client::orderBy('nom')->get(self::COLONNES);

            return [$clients, true, $total];
        }
        $dettes = self::dettes();
        $recents = Vente::validees()->whereNotNull('client_id')->where('date_vente', '>=', now()->subDays(90))
            ->distinct()->limit(self::PRECHARGES_MAX)->pluck('client_id');
        $ids = $dettes->keys()->concat($recents)->concat(Client::latest('id')->limit(50)->pluck('id'))
            ->when($choisi, fn ($c) => $c->push($choisi))->unique()->take(self::PRECHARGES_MAX);

        return [Client::whereIn('id', $ids)->orderBy('nom')->get(self::COLONNES), false, $total];
    }
}
