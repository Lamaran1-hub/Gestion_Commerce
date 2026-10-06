<?php

namespace App\Notifications;

use App\Models\Devis;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Au commerçant : une commande est arrivée depuis sa vitrine en ligne. */
class CommandeVitrine extends Notification
{
    public function __construct(private Devis $commande)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->commande;

        return (new MailMessage)
            ->subject("Nouvelle commande en ligne {$c->numero} : ".gnf($c->total_ttc))
            ->greeting('Bonjour '.($notifiable->prenom ?? '').',')
            ->line("{$c->client_nom} ({$c->client_telephone}) a commandé depuis votre vitrine en ligne pour **".gnf($c->total_ttc).'**.')
            ->line('Rien n\'est encore sorti du stock : confirmez avec le client, puis transformez la commande en vente.')
            ->action('Voir la commande', route('devis.show', $c));
    }

    public function toArray(object $notifiable): array
    {
        $c = $this->commande;

        return ['titre' => 'Nouvelle commande en ligne', 'icone' => 'bag-check',
            'message' => "{$c->client_nom} ({$c->client_telephone}) : ".gnf($c->total_ttc)." — {$c->numero}", 'lien' => route('devis.show', $c), 'boutique_id' => $c->boutique_id];
    }
}
