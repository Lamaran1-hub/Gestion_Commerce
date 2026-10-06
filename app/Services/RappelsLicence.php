<?php

namespace App\Services;

use App\Models\Annonce;
use App\Models\Boutique;
use App\Models\JournalActivite;
use App\Models\User;
use App\Notifications\RappelLicence;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Relances automatiques avant et après l'échéance d'une licence.
 * Jalons : J-7, J-3, J-1, jour J, puis J+1 (expirée). Chaque jalon n'est envoyé qu'une fois par échéance.
 */
class RappelsLicence
{
    public const JALONS = [7, 3, 1, 0, -1];

    /** @return int nombre de boutiques relancées */
    public function envoyer(): int
    {
        $envoyes = 0;
        $boutiques = Boutique::where('statut', '!=', 'suspendu')->whereNotNull('abonnement_expire_le')
            ->whereDate('abonnement_expire_le', '>=', now()->subDay()->toDateString())
            ->whereDate('abonnement_expire_le', '<=', now()->addDays(max(self::JALONS))->toDateString())
            ->get();

        foreach ($boutiques as $b) {
            $jours = $b->joursRestants();
            if (! in_array($jours, self::JALONS, true)) {
                continue;
            }
            // Clé unique par jalon et par échéance : pas de doublon si la tâche tourne plusieurs fois
            $cle = "Rappel licence J".($jours >= 0 ? '-' : '+').abs($jours).' (échéance '.$b->abonnement_expire_le->toDateString().')';
            if (JournalActivite::where('boutique_id', $b->id)->where('action', 'rappel_licence')->where('description', $cle)->exists()) {
                continue;
            }

            $notification = new RappelLicence($b, $jours);
            $admins = User::where('boutique_id', $b->id)->where('actif', true)->whereHas('role', fn ($q) => $q->where('systeme', true))->get();

            // Dans l'application : annonce ciblée, en haut de chaque page jusqu'à lecture
            Annonce::create([
                'titre' => $notification->titre(),
                'contenu' => $notification->message()."\nRenouvellement : menu Aide → Ma licence.",
                'type' => $jours <= 1 ? 'important' : 'info',
                'boutique_id' => $b->id, 'publiee_le' => now(), 'expire_le' => now()->addDays(8)->toDateString(),
            ]);
            Notification::sendNow($admins, $notification, ['database']);
            \App\Support\Courrier::envoyer($admins, $notification, 'rappel', $b->id); // tracé, jamais bloquant

            JournalActivite::create(['boutique_id' => $b->id, 'user_id' => null, 'action' => 'rappel_licence', 'description' => $cle]);
            $envoyes++;
        }

        return $envoyes;
    }
}
