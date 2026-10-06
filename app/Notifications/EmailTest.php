<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail de test envoyé depuis la configuration de l'envoi. */
class EmailTest extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Test d'envoi — ".config('app.name'))
            ->greeting('Bonjour,')
            ->line("Cet e-mail confirme que l'envoi des e-mails de votre logiciel fonctionne.")
            ->line("Vos clients recevront ainsi les confirmations de paiement, les rappels d'échéance et les nouveautés avec cette présentation.")
            ->action('Ouvrir le logiciel', route('login'));
    }
}
