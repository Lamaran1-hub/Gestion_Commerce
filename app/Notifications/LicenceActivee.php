<?php

namespace App\Notifications;

use App\Models\PaiementLicence;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Au client : paiement reçu, licence activée ou prolongée (en ligne ou au guichet), reçu PDF joint. */
class LicenceActivee extends Notification
{
    public function __construct(private PaiementLicence $paiement)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function echeance(): string
    {
        $b = $this->paiement->boutique;

        return $b?->abonnement_expire_le ? "jusqu'au ".$b->abonnement_expire_le->format('d/m/Y') : 'sans échéance';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->paiement->loadMissing(['boutique', 'plan', 'auteur']);
        $pdf = Pdf::loadView('pdf.recu-licence', ['p' => $p])->setPaper('a5')->output();

        return (new MailMessage)
            ->subject('Paiement reçu : votre licence est active '.$this->echeance())
            ->greeting('Bonjour '.($notifiable->prenom ?? '').',')
            ->line('Nous avons bien reçu votre paiement de **'.gnf($p->montant).'** pour « '.$p->boutique?->nom.' ».')
            ->line('Formule **'.($p->plan?->nom ?? '—').'**, licence active **'.$this->echeance().'** ('.$p->libellePeriode().').')
            ->line("Votre reçu n° {$p->numero} est joint à cet e-mail.")
            ->action('Voir ma licence', route('abonnement'))
            ->line('Merci de votre confiance.')
            ->attachData($pdf, "recu-{$p->numero}.pdf", ['mime' => 'application/pdf']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'titre' => 'Licence activée', 'icone' => 'patch-check',
            'message' => 'Paiement de '.gnf($this->paiement->montant).' reçu. Licence active '.$this->echeance().'.',
            'lien' => route('abonnement'), 'boutique_id' => $this->paiement->boutique_id,
        ];
    }
}
