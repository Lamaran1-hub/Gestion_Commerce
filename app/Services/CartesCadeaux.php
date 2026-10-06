<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\CarteCadeau;
use App\Models\Client;
use App\Models\JournalActivite;
use App\Models\MouvementCarteCadeau;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cartes cadeaux (bons d'achat prépayés).
 *
 * Règles :
 * - la carte est payée le jour de sa vente : l'argent entre dans la caisse (ou le compte choisi) ce jour-là ;
 *   ce n'est pas encore une vente (pas de chiffre d'affaires ni de TVA) mais une avance due au porteur ;
 * - la carte n'est pas nominative : celui qui présente le code la dépense, en une ou plusieurs fois,
 *   jamais au-delà de son solde ni après sa date de validité ;
 * - payer avec une carte n'est pas un nouvel encaissement (mode « carte_cadeau ») : rien n'entre dans le tiroir ;
 * - vente annulée ou articles rapportés : ce qui avait été payé avec la carte retourne sur la carte ;
 * - une carte peut être annulée par un responsable : son solde restant est rendu (sortie de caisse) ;
 * - pas de vente de carte hors connexion : le code doit exister sur le serveur pour être dépensé ailleurs.
 */
class CartesCadeaux
{
    public const MODE = 'carte_cadeau';

    public const MINIMUM = 1000;

    public function __construct(private CaisseService $caisse)
    {
    }

    /** @param array{montant:int, mode:string, reference?:?string, client_id?:?int, acheteur?:?string, beneficiaire?:?string, telephone?:?string, message?:?string, expire_le?:?string} $d */
    public function emettre(array $d, User $auteur): CarteCadeau
    {
        $montant = (int) $d['montant'];
        if ($montant < self::MINIMUM) {
            throw new OperationRefusee('Une carte cadeau vaut au moins '.gnf(self::MINIMUM).'.');
        }
        $expire = ! empty($d['expire_le']) ? Carbon::parse($d['expire_le']) : null;
        if ($expire && $expire->isPast() && ! $expire->isToday()) {
            throw new OperationRefusee('La date de validité est déjà passée.');
        }
        $this->caisse->verifierOuverte($auteur);   // de l'argent entre dans la caisse du vendeur

        return DB::transaction(function () use ($d, $montant, $expire, $auteur) {
            $client = ! empty($d['client_id']) ? Client::find($d['client_id']) : null;
            $carte = CarteCadeau::create([
                'code' => $this->nouveauCode(), 'montant' => $montant, 'solde' => $montant,
                'client_id' => $client?->id, 'acheteur' => $this->texte($d['acheteur'] ?? null) ?? $client?->nomComplet(),
                'beneficiaire' => $this->texte($d['beneficiaire'] ?? null), 'telephone' => $this->texte($d['telephone'] ?? null),
                'message' => $this->texte($d['message'] ?? null), 'expire_le' => $expire, 'user_id' => $auteur->id,
            ]);
            $this->mouvement($carte, 'emission', $montant, null, $d['mode'], $d['reference'] ?? null, 'Vente de la carte', $auteur);
            JournalActivite::noter('carte_cadeau', 'Carte cadeau '.$carte->codeMasque().' vendue '.gnf($montant).' ('.libelle_mode($d['mode']).')');

            return $carte;
        });
    }

    /** Retrouve une carte par son code, saisi avec ou sans tirets, espaces ou préfixe CC. */
    public function trouver(?string $saisie): ?CarteCadeau
    {
        $code = self::normaliser($saisie);

        return strlen($code) === 8 ? CarteCadeau::where('code', $code)->first() : null;
    }

    public static function normaliser(?string $saisie): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $saisie));

        return str_starts_with($code, 'CC') && strlen($code) === 10 ? substr($code, 2) : $code;
    }

    /** Pourquoi la carte ne peut pas servir (null si elle est utilisable). */
    public function refus(?CarteCadeau $carte): ?string
    {
        return match ($carte?->etat()) {
            null => 'Carte cadeau introuvable : vérifiez le code.',
            'annulee' => 'Cette carte cadeau a été annulée.',
            'epuisee' => 'Cette carte cadeau a déjà été entièrement utilisée.',
            'expiree' => 'Cette carte cadeau a expiré le '.$carte->expire_le->format('d/m/Y').'.',
            default => null,
        };
    }

    /** Montant payable avec la carte sur un achat : plafonné au solde et au montant dû. */
    public function utilisable(?CarteCadeau $carte, int $du): int
    {
        return $this->refus($carte) === null ? max(0, min($carte->solde, $du)) : 0;
    }

    public function utiliser(CarteCadeau $carte, Vente $vente, int $montant): void
    {
        $c = CarteCadeau::whereKey($carte->id)->lockForUpdate()->firstOrFail();
        if ($refus = $this->refus($c)) {
            throw new OperationRefusee($refus);
        }
        if ($montant > $c->solde) {
            throw new OperationRefusee('Il ne reste que '.gnf($c->solde).' sur cette carte cadeau.');
        }
        $this->mouvement($c, 'utilisation', -$montant, $vente, null, null, "Vente {$vente->numero}");
    }

    /**
     * Rend sur les cartes ce qui avait été payé avec elles sur une vente (vente annulée, articles rapportés).
     * Plusieurs cartes : chacune récupère au plus ce qu'elle avait payé.
     *
     * @return int montant recrédité
     */
    public function rendreSurVente(Vente $vente, int $montant, string $motif): int
    {
        $reste = $montant;
        $parCarte = MouvementCarteCadeau::where('vente_id', $vente->id)->selectRaw('carte_cadeau_id, -SUM(montant) as net')
            ->groupBy('carte_cadeau_id')->orderBy('carte_cadeau_id')->pluck('net', 'carte_cadeau_id');
        foreach ($parCarte as $carteId => $net) {
            $part = min($reste, max(0, (int) $net));
            if ($part > 0 && ($carte = CarteCadeau::lockForUpdate()->find($carteId))) {
                $this->mouvement($carte, 'recredit', $part, $vente, null, null, $motif);
                $reste -= $part;
            }
        }

        return $montant - $reste;
    }

    /** Vente annulée : tout ce qui avait été payé par carte cadeau y retourne. */
    public function annulerVente(Vente $vente): void
    {
        $paye = (int) \App\Models\Paiement::where('vente_id', $vente->id)->where('mode', self::MODE)->sum('montant');
        if ($paye > 0) {
            $this->rendreSurVente($vente, $paye, "Annulation de la vente {$vente->numero}");
        }
    }

    /** Annule la carte et rend son solde au porteur (sortie d'argent). @return int montant rendu */
    public function annuler(CarteCadeau $carte, string $mode, string $motif, User $auteur): int
    {
        return DB::transaction(function () use ($carte, $mode, $motif, $auteur) {
            $c = CarteCadeau::whereKey($carte->id)->lockForUpdate()->firstOrFail();
            if ($c->statut === 'annulee') {
                throw new OperationRefusee('Cette carte cadeau est déjà annulée.');
            }
            $rendu = $c->solde;
            if ($rendu > 0) {
                $this->caisse->verifierOuverte($auteur);
                $this->mouvement($c, 'remboursement', -$rendu, null, $mode, null, $motif, $auteur);
            }
            $c->update(['statut' => 'annulee', 'annulee_le' => now(), 'motif_annulation' => $motif]);
            JournalActivite::noter('carte_cadeau', 'Carte cadeau '.$c->codeMasque().' annulée'.($rendu ? ', '.gnf($rendu).' rendus ('.libelle_mode($mode).')' : '')." : {$motif}");

            return $rendu;
        });
    }

    public function prolonger(CarteCadeau $carte, Carbon $jusquau): void
    {
        if ($carte->statut === 'annulee') {
            throw new OperationRefusee('Une carte annulée ne peut pas être prolongée.');
        }
        if ($jusquau->isPast() && ! $jusquau->isToday()) {
            throw new OperationRefusee('Choisissez une date à venir.');
        }
        $carte->update(['expire_le' => $jusquau]);
        JournalActivite::noter('carte_cadeau', 'Carte cadeau '.$carte->codeMasque().' prolongée jusqu\'au '.$jusquau->format('d/m/Y'));
    }

    private function mouvement(CarteCadeau $carte, string $type, int $montant, ?Vente $vente, ?string $mode, ?string $reference, ?string $motif, ?User $auteur = null): void
    {
        MouvementCarteCadeau::create(['carte_cadeau_id' => $carte->id, 'vente_id' => $vente?->id, 'type' => $type, 'montant' => $montant,
            'mode' => $mode, 'reference' => $reference !== null ? mb_substr($reference, 0, 120) : null, 'motif' => $motif, 'user_id' => $auteur?->id ?? auth()->id(), 'date_mouvement' => now()]);
        if ($type !== 'emission') {
            $carte->increment('solde', $montant);
        }
    }

    /** 8 caractères sans les signes qui se confondent (0/O, 1/I/L) : faciles à dicter, impossibles à deviner. */
    private function nouveauCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (CarteCadeau::withoutGlobalScopes()->where('boutique_id', boutique()->id)->where('code', $code)->exists());

        return $code;
    }

    private function texte(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
