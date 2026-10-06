<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Lien de réinitialisation du mot de passe (usage unique, durée limitée). */
class ReinitialisationMotDePasse extends Notification
{
    public function __construct(private string $jeton)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire', 60);
        $lien = route('password.reset', ['token' => $this->jeton, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe — '.config('app.name'))
            ->greeting('Bonjour '.$notifiable->prenom.',')
            ->line('Vous avez demandé à changer votre mot de passe. Cliquez sur le bouton ci-dessous pour en choisir un nouveau.')
            ->action('Choisir un nouveau mot de passe', $lien)
            ->line("Ce lien est valable {$minutes} minutes et ne fonctionne qu'une seule fois.")
            ->line("Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.");
    }
}
