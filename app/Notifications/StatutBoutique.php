<?php

namespace App\Notifications;

use App\Models\Boutique;
use App\Support\Plateforme;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Au client : sa boutique est suspendue ou réactivée par l'éditeur. */
class StatutBoutique extends Notification
{
    public function __construct(private Boutique $boutique)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $b = $this->boutique;
        $message = (new MailMessage)->greeting('Bonjour '.($notifiable->prenom ?? '').',');

        if ($b->statut === 'suspendu') {
            return $message->level('error')
                ->subject('Votre boutique « '.$b->nom.' » est suspendue')
                ->line("L'accès à « ".$b->nom." » est suspendu. **Vos données sont conservées** : rien n'est perdu.")
                ->line("Pour rétablir l'accès, contactez-nous".(Plateforme::get('telephone') ? ' au '.Plateforme::get('telephone') : '').'.')
                ->action('Voir ma licence', route('abonnement'));
        }

        return $message
            ->subject('Votre boutique « '.$b->nom.' » est de nouveau active')
            ->line("Bonne nouvelle : l'accès à « ".$b->nom.' » est rétabli. Vous et vos vendeurs pouvez de nouveau travailler.')
            ->action('Ouvrir mon espace', route('login'));
    }
}
