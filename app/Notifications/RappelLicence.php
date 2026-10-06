<?php

namespace App\Notifications;

use App\Models\Boutique;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** À l'administrateur d'une boutique : l'échéance de sa licence approche (ou est passée). */
class RappelLicence extends Notification
{
    public function __construct(private Boutique $boutique, private int $jours)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function titre(): string
    {
        return match (true) {
            $this->jours < 0 => 'Votre licence a expiré',
            $this->jours === 0 => "Votre licence expire aujourd'hui",
            default => "Votre licence expire dans {$this->jours} jour".($this->jours > 1 ? 's' : ''),
        };
    }

    public function message(): string
    {
        $b = $this->boutique;
        $date = $b->abonnement_expire_le->format('d/m/Y');
        $coupure = $b->dateCoupure()?->format('d/m/Y');

        return match (true) {
            $this->jours < 0 => "La licence de {$b->nom} a expiré le {$date}."
                .($b->estActive() ? " Délai de grâce : l'accès sera coupé après le {$coupure}." : ' Les ventes sont bloquées ; vos données sont conservées.'),
            default => "La licence de {$b->nom} arrive à échéance le {$date}. Renouvelez-la pour continuer à travailler sans interruption.",
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->titre().' — '.config('app.name'))
            ->greeting('Bonjour '.$notifiable->prenom.',')
            ->line($this->message())
            ->action('Renouveler ma licence', route('abonnement'))
            ->line('Paiement possible en ligne (Orange Money, MTN MoMo, carte, PayCard, Kulu, Soutra Money) depuis la page « Ma licence ».');
    }

    public function toArray(object $notifiable): array
    {
        return ['titre' => $this->titre(), 'message' => $this->message(), 'lien' => route('abonnement'), 'icone' => 'alarm', 'boutique_id' => $this->boutique->id];
    }
}
