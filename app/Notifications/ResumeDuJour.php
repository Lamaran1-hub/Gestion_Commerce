<?php

namespace App\Notifications;

use App\Models\Boutique;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Résumé du matin : activité de la veille et points d'attention. */
class ResumeDuJour extends Notification
{
    public function __construct(private Boutique $boutique, private array $r)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function titre(): string
    {
        return 'Résumé du '.Carbon::parse($this->r['jour'])->translatedFormat('l d F');
    }

    /** @return string[] lignes du résumé */
    private function lignes(): array
    {
        $r = $this->r;
        $lignes = [
            "Ventes : {$r['nb_ventes']} pour ".gnf($r['ca']).' · encaissé : '.gnf($r['encaisse']).'.',
        ];
        if ($r['annulations']) {
            $lignes[] = "⚠ {$r['annulations']} vente(s) annulée(s).";
        }
        if ($r['caisses_non_cloturees']) {
            $lignes[] = '⚠ Caisse non clôturée : '.implode(', ', $r['caisses_non_cloturees']).'.';
        }
        if ((int) $r['ecarts_caisse'] !== 0) {
            $lignes[] = '⚠ Écarts de caisse : '.((int) $r['ecarts_caisse'] > 0 ? '+' : '−').' '.gnf(abs((int) $r['ecarts_caisse'])).'.';
        }
        if ($r['nb_stock_bas']) {
            $lignes[] = "Stock à réapprovisionner : {$r['nb_stock_bas']} produit(s)".($r['stock_bas'] ? ' ('.implode(', ', $r['stock_bas']).($r['nb_stock_bas'] > 5 ? '…' : '').')' : '').'.';
        }
        if ($n = $r['peremptions']['perimes'] ?? 0) {
            $lignes[] = "⚠ {$n} lot(s) périmé(s) encore en stock : à retirer de la vente.";
        }
        if ($n = $r['peremptions']['bientot'] ?? 0) {
            $lignes[] = "{$n} lot(s) à vendre en priorité (péremption proche).";
        }
        if ($n = $r['livraisons_retard'] ?? 0) {
            $lignes[] = "⚠ {$n} livraison(s) client en retard : prévenez les clients et replanifiez.";
        }
        if ($n = $r['commandes_fournisseur_retard'] ?? 0) {
            $lignes[] = "{$n} commande(s) fournisseur non livrée(s) à la date prévue : relancez le fournisseur.";
        }
        if ($m = $r['acomptes_expires'] ?? 0) {
            $lignes[] = '⚠ Acomptes clients bloqués sur des devis expirés : '.gnf($m).'. Recréez les devis au prix du jour ou remboursez.';
        }
        if ($n = $r['series_manquantes'] ?? 0) {
            $lignes[] = "{$n} numéro(s) de série non noté(s) sur les ventes de la semaine : la garantie de ces appareils ne peut pas être vérifiée.";
        }
        if ($r['dettes_fournisseurs_retard'] ?? 0) {
            $lignes[] = '⚠ Dettes fournisseurs en retard de paiement : '.gnf($r['dettes_fournisseurs_retard']).'.';
        }
        if ($r['credits_retard']) {
            $lignes[] = 'Crédits clients en retard (échéance dépassée) : '.gnf($r['credits_retard']).'.';
        }
        if ($r['credits_echeance_semaine'] ?? 0) {
            $lignes[] = 'Crédits à encaisser dans les 7 prochains jours : '.gnf($r['credits_echeance_semaine']).'.';
        }

        return $lignes;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->titre().' — '.$this->boutique->nom)->greeting('Bonjour '.$notifiable->prenom.',');
        foreach ($this->lignes() as $l) {
            $mail->line($l);
        }

        return $mail->action('Ouvrir le tableau de bord', route('dashboard'))
            ->line('Vous pouvez désactiver ce résumé dans Paramètres → Règles de gestion.');
    }

    public function toArray(object $notifiable): array
    {
        return ['titre' => $this->titre(), 'message' => implode("\n", $this->lignes()), 'lien' => route('dashboard'), 'icone' => 'sunrise', 'boutique_id' => $this->boutique->id];
    }
}
