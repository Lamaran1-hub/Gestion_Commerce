<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Changement sensible sur un compte (mot de passe, adresse e-mail) : le titulaire est prévenu, pour réagir si ce n'est pas lui. */
class AlerteSecuriteCompte extends Notification
{
    public function __construct(private string $prenom, private string $evenement, private string $appareil)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Sécurité de votre compte — '.config('app.name'))
            ->greeting('Bonjour '.$this->prenom.',')
            ->line($this->evenement.' le '.now()->format('d/m/Y à H:i').' depuis : '.$this->appareil.'.')
            ->line('Si c\'est bien vous, il n\'y a rien à faire.')
            ->line('Si ce n\'est pas vous, utilisez tout de suite « Mot de passe oublié » sur la page de connexion, puis prévenez l\'administrateur de votre boutique.')
            ->action('Aller à la page de connexion', route('login'));
    }
}
