<?php

namespace App\Notifications;

use App\Models\Annonce;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** Annonce de l'éditeur (nouveautés, mises à jour, informations) envoyée aussi par e-mail. */
class Nouveaute extends Notification
{
    public function __construct(private Annonce $annonce)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(($this->annonce->type === 'important' ? 'Important : ' : '').$this->annonce->titre)
            ->greeting('Bonjour '.($notifiable->prenom ?? '').',');
        foreach (preg_split('/\R{2,}/', trim($this->annonce->contenu)) as $paragraphe) {
            $message->line($paragraphe);
        }
        $message->action('Ouvrir le logiciel', route('login'));
        // Lien de désabonnement signé : un clic, sans avoir à se connecter
        if (isset($notifiable->id)) {
            $message->line('Vous ne souhaitez plus recevoir les nouveautés ? [Se désabonner]('
                .URL::signedRoute('emails.desabonner', ['user' => $notifiable->id]).')');
        }

        return $message;
    }
}
