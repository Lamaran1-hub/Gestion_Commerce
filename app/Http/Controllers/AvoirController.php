<?php

namespace App\Http\Controllers;

use App\Models\Avoir;
use App\Models\Client;
use App\Models\Retour;

/**
 * Bon d'avoir imprimable (ticket 80 mm) : remis au client lors d'un retour rendu en avoir,
 * ou réimprimé depuis sa fiche avec le solde à jour. Le code de contrôle, signé avec la clé
 * de l'application, permet de reconnaître un bon authentique (un bon recopié à la main ne concorde pas).
 */
class AvoirController extends Controller
{
    public function bonRetour(Retour $retour)
    {
        $retour->load(['vente.client', 'lignes', 'auteur']);
        $client = $retour->vente?->client;
        $credite = (int) Avoir::where('retour_id', $retour->id)->where('montant', '>', 0)->sum('montant');
        abort_unless($client && $credite > 0, 404);

        return $this->bon($client, $credite, $retour->numero, $retour->created_at, $retour);
    }

    public function bonClient(Client $client)
    {
        abort_unless($client->avoir > 0, 404);

        return $this->bon($client, null, 'SOLDE-'.$client->code, now(), null);
    }

    /** Code de contrôle : 8 caractères signés (client, référence, montant, solde). */
    public static function code(Client $client, string $reference, int $montant): string
    {
        $h = strtoupper(substr(hash_hmac('sha256', "avoir|{$client->boutique_id}|{$client->id}|{$reference}|{$montant}", config('app.key')), 0, 8));

        return substr($h, 0, 4).'-'.substr($h, 4);
    }

    private function bon(Client $client, ?int $credite, string $reference, $date, ?Retour $retour)
    {
        $b = boutique();
        $solde = (int) $client->fresh()->avoir;
        $montantSigne = $credite ?? $solde;
        $message = 'Bonjour '.($client->prenom ?: $client->nomComplet()).",\n"
            .($credite ? "Suite à votre retour ({$reference}), un avoir de ".gnf($credite)." vous a été accordé chez {$b->nom}.\n" : '')
            .'Votre avoir disponible : '.gnf($solde).".\nIl se déduit de vos prochains achats : donnez simplement votre nom à la caisse.\n"
            .'Code : '.self::code($client, $reference, $montantSigne)."\n{$b->nom}".($b->telephone ? " — {$b->telephone}" : '');

        return view('avoirs.bon', [
            'boutique' => $b, 'client' => $client, 'retour' => $retour, 'reference' => $reference, 'date' => $date,
            'credite' => $credite, 'solde' => $solde, 'code' => self::code($client, $reference, $montantSigne),
            'whatsapp' => lien_whatsapp($client->telephone, $message),
        ]);
    }
}
