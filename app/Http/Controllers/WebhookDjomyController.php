<?php

namespace App\Http\Controllers;

use App\Models\CommandeLicence;
use App\Services\Paiement\DjomyClient;
use App\Services\Paiement\LicenceEnLigneService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Djomy.
 * - signature HMAC obligatoire (X-Webhook-Signature), sinon 401 ;
 * - chaque événement n'est traité qu'une fois (eventId unique) ;
 * - le contenu du webhook n'est qu'un signal : le statut est relu chez Djomy avant toute activation.
 */
class WebhookDjomyController extends Controller
{
    public function __invoke(Request $request, DjomyClient $djomy, LicenceEnLigneService $service)
    {
        $brut = $request->getContent();
        $signatureValide = $djomy->signatureValide($brut, $request->header('X-Webhook-Signature'));
        $contenu = json_decode($brut, true);

        if (! $signatureValide || ! is_array($contenu)) {
            Log::warning('Webhook Djomy rejeté', ['signature' => $signatureValide, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Signature invalide'], 401);
        }

        $paiement = $contenu['data']['payment'] ?? $contenu['data'] ?? [];
        $reference = $paiement['merchantPaymentReference'] ?? $contenu['merchantPaymentReference'] ?? null;
        $transaction = $paiement['transactionId'] ?? $paiement['id'] ?? null;
        $evenementId = $contenu['eventId'] ?? hash('sha256', $brut);

        try {
            $evenement = DB::table('evenements_paiement')->insertGetId([
                'fournisseur' => 'djomy', 'evenement_id' => $evenementId, 'type' => $contenu['eventType'] ?? null,
                'reference' => $reference ?? $transaction, 'signature_valide' => true, 'contenu' => $brut,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Déjà traité']); // Djomy peut renvoyer le même événement
        }

        $commande = match (true) {
            (bool) $reference => CommandeLicence::where('reference', $reference)->first(),
            (bool) $transaction => CommandeLicence::where('transaction_id', $transaction)->first(),
            default => null,
        };
        if ($commande) {
            if (! $commande->transaction_id && $transaction) {
                $commande->update(['transaction_id' => $transaction]);
            }
            $service->synchroniser($commande);
        } else {
            Log::notice('Webhook Djomy sans commande correspondante', ['reference' => $reference, 'transaction' => $transaction]);
        }
        DB::table('evenements_paiement')->where('id', $evenement)->update(['traite_le' => now()]);

        return response()->json(['message' => 'OK']);
    }
}
