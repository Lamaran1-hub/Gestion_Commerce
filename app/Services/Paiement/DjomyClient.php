<?php

namespace App\Services\Paiement;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client de l'API Djomy.
 *
 * Authentification : X-API-KEY = "<clientId>:<HMAC-SHA256(clientId, clientSecret)>" sur chaque requête,
 * plus un jeton Bearer obtenu par POST /v1/auth (mis en cache).
 * Webhooks : en-tête X-Webhook-Signature = "v1:<HMAC-SHA256(corps brut, clientSecret)>".
 */
class DjomyClient
{
    private const CLE_JETON = 'djomy.jeton.';

    public function estConfigure(): bool
    {
        return config('services.djomy.actif') && config('services.djomy.client_id') && config('services.djomy.client_secret');
    }

    /**
     * Crée un paiement sur la passerelle hébergée : le client paie sur la page Djomy avec
     * l'un des moyens autorisés (Orange Money, MTN MoMo, carte, PayCard, Kulu, Soutra Money).
     *
     * @param  string[]  $moyens  codes Djomy autorisés (tous les moyens actifs, ou celui choisi par le client)
     * @return array{transaction_id:?string, url:?string, statut:?string, brut:array}
     */
    public function creerPaiement(int $montant, string $numeroPayeur, string $reference, string $description, string $urlRetour, string $urlAnnulation, array $metadonnees = [], array $moyens = []): array
    {
        $corps = $this->appeler('post', '/v1/payments/gateway', [
            'amount' => $montant,
            'countryCode' => config('services.djomy.pays'),
            'payerNumber' => self::formatNumero($numeroPayeur),
            'allowedPaymentMethods' => array_values($moyens ?: \App\Support\MoyensDjomy::actifs()),
            'description' => mb_substr($description, 0, 140),
            'merchantPaymentReference' => $reference,
            'returnUrl' => $urlRetour,
            'cancelUrl' => $urlAnnulation,
            'metadata' => $metadonnees,
        ]);
        $d = $corps['data'] ?? $corps;

        return [
            'transaction_id' => $d['transactionId'] ?? $d['transaction_id'] ?? null,
            'url' => $d['redirectUrl'] ?? $d['paymentUrl'] ?? $d['url'] ?? null,
            'statut' => isset($d['status']) ? strtoupper((string) $d['status']) : null,
            'brut' => $corps,
        ];
    }

    /**
     * Statut réel d'un paiement, lu directement chez Djomy (seule source de vérité).
     *
     * @return array{statut:?string, montant:?int, reference:?string, moyen:?string, brut:array}
     */
    public function statut(string $transactionId): array
    {
        $corps = $this->appeler('get', '/v1/payments/'.rawurlencode($transactionId).'/status');
        $d = $corps['data'] ?? $corps;
        $montant = $d['receivedAmount'] ?? $d['paidAmount'] ?? $d['amount'] ?? null;

        return [
            'statut' => isset($d['status']) ? strtoupper((string) $d['status']) : null,
            'montant' => $montant !== null ? (int) round((float) $montant) : null,
            'reference' => $d['merchantPaymentReference'] ?? null,
            // Moyen réellement utilisé par le client (nom de champ variable selon la version de l'API)
            'moyen' => \App\Support\MoyensDjomy::normaliser($d['paymentMethod'] ?? $d['providerCode'] ?? $d['method'] ?? $d['provider'] ?? null),
            'brut' => $corps,
        ];
    }

    /**
     * Numéro payeur au format attendu par Djomy : 00224XXXXXXXXX
     * (format utilisé par la page de paiement officielle de Djomy).
     */
    public static function formatNumero(string $numero): string
    {
        $n = numero_guinee($numero);

        return $n ? '00'.$n : $numero;
    }

    /** Vérifie la signature d'un webhook (comparaison à temps constant). */
    public function signatureValide(string $corpsBrut, ?string $entete): bool
    {
        $secret = (string) config('services.djomy.client_secret');
        if ($secret === '' || ! $entete) {
            return false;
        }
        $fournie = str_contains($entete, ':') ? substr($entete, strpos($entete, ':') + 1) : $entete;

        return hash_equals(hash_hmac('sha256', $corpsBrut, $secret), trim($fournie));
    }

    /** Obtient un nouveau jeton (sert au diagnostic : prouve que les identifiants sont acceptés). */
    public function testerAuthentification(): void
    {
        if (! config('services.djomy.client_id') || ! config('services.djomy.client_secret')) {
            throw new ErreurPaiement('DJOMY_CLIENT_ID ou DJOMY_CLIENT_SECRET manquant dans le fichier .env.');
        }
        try {
            $this->jeton(true);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new ErreurPaiement('Djomy injoignable ('.config('services.djomy.url').') : '.$e->getMessage());
        }
    }

    private function cleApi(): string
    {
        $id = (string) config('services.djomy.client_id');

        return $id.':'.hash_hmac('sha256', $id, (string) config('services.djomy.client_secret'));
    }

    private function requete(): PendingRequest
    {
        return Http::baseUrl(config('services.djomy.url'))
            ->timeout(config('services.djomy.delai'))
            ->acceptJson()
            ->withHeaders(['X-API-KEY' => $this->cleApi()]);
    }

    private function jeton(bool $renouveler = false): string
    {
        if ($renouveler) {
            Cache::forget(self::CLE_JETON.config('services.djomy.mode'));
        }

        // Un jeton par environnement : un jeton de test ne sert jamais en production.
        // Il est chiffré dans le cache (fichiers ou base) : lu tel quel, il ne permettrait pas d'appeler Djomy.
        $chiffre = Cache::remember(self::CLE_JETON.config('services.djomy.mode'), now()->addMinutes(10), function () {
            $reponse = $this->requete()->post('/v1/auth');
            $corps = $reponse->json() ?? [];
            if ($reponse->failed() || ($corps['success'] ?? null) === false) {
                Log::warning('Authentification Djomy refusée', ['http' => $reponse->status(), 'reponse' => $corps]);
                throw new ErreurPaiement('Identifiants Djomy refusés ('.config('services.djomy.mode').')'
                    .(isset($corps['errors'][0]) ? ' : '.$corps['errors'][0] : '').'. Vérifiez DJOMY_CLIENT_ID et DJOMY_CLIENT_SECRET.');
            }
            $jeton =$corps['access_token'] ?? $corps['token'] ?? $corps['data']['accessToken'] ?? $corps['data']['access_token'] ?? $corps['data']['token'] ?? null;
            if (! is_string($jeton) || $jeton === '') {
                throw new ErreurPaiement("Djomy n'a pas renvoyé de jeton d'accès. Vérifiez DJOMY_CLIENT_ID et DJOMY_CLIENT_SECRET.");
            }

            return \Illuminate\Support\Facades\Crypt::encryptString($jeton);
        });
        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($chiffre);
        } catch (\Throwable) {
            // ancien jeton en clair ou clé d'application changée : on en redemande un
            return $renouveler ? throw new ErreurPaiement('Jeton Djomy illisible.') : $this->jeton(true);
        }
    }

    /** Appel authentifié ; un jeton expiré est renouvelé une fois. */
    private function appeler(string $methode, string $chemin, array $donnees = []): array
    {
        if (! $this->estConfigure()) {
            throw new ErreurPaiement("Le paiement en ligne n'est pas encore configuré.");
        }

        try {
            $reponse = $this->envoyer($methode, $chemin, $donnees, $this->jeton());
            if ($reponse->status() === 401) {
                $reponse = $this->envoyer($methode, $chemin, $donnees, $this->jeton(true));
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('Djomy injoignable', ['chemin' => $chemin, 'erreur' => $e->getMessage()]);
            throw new ErreurPaiement('Le service de paiement est momentanément injoignable. Réessayez dans quelques minutes.');
        }

        $corps = $reponse->json() ?? [];
        if ($reponse->failed() || ($corps['success'] ?? null) === false) {
            Log::warning('Djomy a refusé la requête', ['chemin' => $chemin, 'http' => $reponse->status(), 'reponse' => $corps]);
            throw new ErreurPaiement('Le service de paiement a refusé la demande'
                .(isset($corps['message']) ? ' : '.$corps['message'] : '.'));
        }

        return $corps;
    }

    private function envoyer(string $methode, string $chemin, array $donnees, string $jeton): Response
    {
        $requete = $this->requete()->withToken($jeton);

        return $methode === 'get' ? $requete->get($chemin, $donnees) : $requete->post($chemin, $donnees);
    }
}
