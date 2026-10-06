<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Acompte;
use App\Models\Devis;
use App\Models\JournalActivite;
use App\Models\Paiement;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Support\Facades\DB;

/**
 * Acomptes sur devis et commandes (vitrine comprise).
 *
 * Règles :
 * - un acompte se verse sur un devis en cours et non expiré, au nom d'un client identifié (fiche ou nom) ;
 * - le total des acomptes ne dépasse jamais le montant du devis ;
 * - l'argent est encaissé le jour du versement : il compte dans la caisse de ce jour-là (espèces) et en trésorerie ;
 * - à la livraison, la vente est réglée d'abord par l'acompte (mode « acompte », qui n'est pas un nouvel encaissement),
 *   le client ne paie que le reste ;
 * - devis annulé : l'acompte est remboursé (acompte négatif, qui sort de la caisse ou du compte choisi) ;
 * - devis recréé au prix du jour : l'acompte passe sur le nouveau devis ;
 * - vente annulée : la commande redevient « en cours » avec son acompte, que l'on peut livrer à nouveau ou rembourser.
 */
class Acomptes
{
    public const MODE = 'acompte';

    public function __construct(private CaisseService $caisse)
    {
    }

    public function verser(Devis $devis, int $montant, string $mode, ?string $reference, User $auteur): Acompte
    {
        return DB::transaction(function () use ($devis, $montant, $mode, $reference, $auteur) {
            $d = Devis::whereKey($devis->id)->lockForUpdate()->firstOrFail();
            if ($d->statut !== 'en_cours' || $d->estExpire()) {
                throw new OperationRefusee('Un acompte se verse seulement sur un devis en cours et encore valable.');
            }
            if (! $d->client_id && trim((string) $d->client_nom) === '') {
                throw new OperationRefusee('Indiquez d\'abord le client : un acompte doit pouvoir être rendu à la bonne personne.');
            }
            $maximum = $d->resteAPayer();
            if ($montant <= 0 || $montant > $maximum) {
                throw new OperationRefusee('L\'acompte doit être compris entre 1 et '.gnf($maximum).' (reste à payer sur ce devis).');
            }
            if ($mode === 'especes') {
                $this->caisse->verifierOuverte($auteur);
            }
            $a = Acompte::create(['devis_id' => $d->id, 'montant' => $montant, 'mode' => $mode, 'reference' => $reference,
                'date_versement' => now(), 'user_id' => $auteur->id]);
            $d->increment('acompte', $montant);
            JournalActivite::noter('devis', 'Acompte de '.gnf($montant)." sur {$d->numero} (".libelle_mode($mode).') — '.$d->nomClient());

            return $a;
        });
    }

    /** Rend tout l'acompte au client. @return int montant rendu */
    public function rembourser(Devis $d, string $mode, User $auteur): int
    {
        $montant = (int) $d->acompte;
        if ($montant <= 0) {
            return 0;
        }
        if ($mode === 'especes') {
            $this->caisse->verifierOuverte($auteur);
        }
        Acompte::create(['devis_id' => $d->id, 'montant' => -$montant, 'mode' => $mode, 'reference' => 'Remboursement',
            'date_versement' => now(), 'user_id' => $auteur->id]);
        $d->update(['acompte' => 0]);
        JournalActivite::noter('devis', 'Acompte de '.gnf($montant)." remboursé sur {$d->numero} (".libelle_mode($mode).')');

        return $montant;
    }

    /** Le devis est recréé au prix du jour : son acompte le suit. */
    public function transferer(Devis $ancien, Devis $nouveau): void
    {
        if ($ancien->acompte <= 0) {
            return;
        }
        if ($ancien->acompte > $nouveau->total_ttc) {
            throw new OperationRefusee('L\'acompte versé ('.gnf($ancien->acompte).') dépasse le nouveau total ('.gnf($nouveau->total_ttc)
                .') : remboursez d\'abord la différence, ou annulez le devis en remboursant l\'acompte.');
        }
        Acompte::where('devis_id', $ancien->id)->update(['devis_id' => $nouveau->id]);
        $nouveau->update(['acompte' => $ancien->acompte]);
        $ancien->update(['acompte' => 0]);
    }

    /** Vente livrée sur commande puis annulée : la commande et son acompte reviennent « en cours ». */
    public function annulerVente(Vente $vente): void
    {
        if (! Paiement::where('vente_id', $vente->id)->where('mode', self::MODE)->exists()) {
            return;
        }
        Devis::where('vente_id', $vente->id)->where('statut', 'converti')->update(['statut' => 'en_cours', 'vente_id' => null]);
    }
}
