<?php

namespace App\Notifications;

use App\Models\Boutique;
use App\Support\Plateforme;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** À l'administrateur d'une nouvelle boutique : accueil, période d'essai et premiers pas (jamais de mot de passe). */
class Bienvenue extends Notification
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
        $message = (new MailMessage)
            ->subject('Bienvenue sur '.config('app.name').' — votre boutique « '.$b->nom.' » est prête')
            ->greeting('Bienvenue '.($notifiable->prenom ?? '').' !')
            ->line('Votre espace de gestion pour « '.$b->nom.' » est ouvert. Vous pouvez dès maintenant vendre, suivre votre stock, vos clients et vos crédits.');
        if ($b->statut === 'essai' && $b->abonnement_expire_le) {
            $message->line("Votre **période d'essai gratuite** dure jusqu'au **".$b->abonnement_expire_le->format('d/m/Y').'**.');
        }
        $aide = collect([
            Plateforme::get('telephone') ? 'téléphone : '.Plateforme::get('telephone') : null,
            Plateforme::get('whatsapp') ? 'WhatsApp : '.Plateforme::get('whatsapp') : null,
        ])->filter()->implode(' · ');

        return $message
            ->line('Pour bien démarrer :')
            ->line('1. **Paramètres** : votre logo, vos coordonnées et vos règles (remise, crédit, TVA).')
            ->line('2. **Produits** : ajoutez-les un par un ou importez votre catalogue depuis Excel.')
            ->line('3. **Utilisateurs** : créez un compte pour chaque vendeur, avec ses droits.')
            ->action('Ouvrir mon espace', route('login'))
            ->line("Besoin d'aide ? ".($aide ? ucfirst($aide).', ou ' : '').'le menu Aide → Assistance du logiciel.');
    }
}
