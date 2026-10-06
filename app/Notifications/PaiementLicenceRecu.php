<?php

namespace App\Notifications;

use App\Models\CommandeLicence;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Au propriétaire : un client a payé sa licence en ligne. */
class PaiementLicenceRecu extends Notification
{
    public function __construct(private CommandeLicence $commande)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function resume(): string
    {
        $c = $this->commande;

        return "{$c->boutique->nom} a payé ".gnf($c->montant_recu ?? $c->montant)." : formule {$c->plan->nom}, {$c->mois} mois.";
    }

    private function consigne(): string
    {
        return match ($this->commande->statut) {
            'payee' => 'La licence a été activée automatiquement.',
            'a_valider' => 'La licence attend votre validation.',
            'anomalie' => 'Montant reçu inférieur au montant dû : vérifiez avant d\'activer.',
            default => 'Statut : '.$this->commande->libelleStatut().'.',
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->commande;

        return (new MailMessage)
            ->subject(($c->statut === 'anomalie' ? '⚠ ' : '').'Paiement de licence reçu — '.$c->boutique->nom)
            ->greeting('Bonjour '.$notifiable->prenom.',')
            ->line($this->resume())
            ->line($this->consigne())
            ->line("Référence : {$c->reference} — transaction Djomy : {$c->transaction_id}")
            ->action('Voir les paiements en ligne', route('admin.commandes.index'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'titre' => $this->commande->statut === 'anomalie' ? 'Paiement en anomalie' : 'Paiement de licence reçu',
            'message' => $this->resume().' '.$this->consigne(),
            'commande' => $this->commande->reference, 'icone' => 'cash-coin',
            'lien' => route('admin.commandes.index'),
        ];
    }
}
