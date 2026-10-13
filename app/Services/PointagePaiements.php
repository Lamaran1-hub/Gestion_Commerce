<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\JournalActivite;
use App\Models\Paiement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Paiements électroniques (mobile money, carte, virement, chèque) : l'argent n'est pas dans le tiroir,
 * il faut le retrouver sur les SMS ou le relevé.
 *
 * - Un numéro de transaction déjà encaissé (même moyen de paiement) est refusé : un même SMS ne paie pas deux ventes.
 * - Le responsable pointe les paiements retrouvés ; ceux qu'il ne retrouve pas sont mis en litige.
 * - Au-delà de 2 jours sans pointage, le tableau de bord le rappelle.
 */
class PointagePaiements
{
    public const MODES = ['orange_money', 'mtn_momo', 'paycard', 'kulu', 'soutra_money', 'carte', 'virement', 'cheque'];

    public const JOURS_RAPPEL = 2;

    private const SQL_REFERENCE = "UPPER(REPLACE(REPLACE(reference, ' ', ''), '-', ''))";

    public static function normaliser(?string $reference): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim((string) $reference)));
    }

    /**
     * Refuse un numéro de transaction déjà encaissé (ou saisi deux fois dans la même vente).
     *
     * @param  array<string,bool>  $vus  numéros déjà vus dans la même opération
     */
    public function verifierReference(string $mode, ?string $reference, array &$vus = []): void
    {
        $ref = self::normaliser($reference);
        if (! in_array($mode, self::MODES, true) || mb_strlen($ref) < 4) {
            return;
        }
        $libelle = libelle_mode($mode);
        if (isset($vus[$mode.'|'.$ref])) {
            throw new OperationRefusee("Le numéro de transaction {$reference} ({$libelle}) est saisi deux fois.");
        }
        $vus[$mode.'|'.$ref] = true;
        $deja = Paiement::with('vente')->where('mode', $mode)->where('montant', '>', 0)
            ->whereRaw(self::SQL_REFERENCE.' = ?', [$ref])->first();
        if ($deja) {
            throw new OperationRefusee("Le numéro de transaction {$reference} ({$libelle}) a déjà été encaissé le "
                .$deja->date_paiement->format('d/m/Y à H:i').($deja->vente ? ' sur la vente '.$deja->vente->numero : '')
                .' : vérifiez le SMS du client, un même paiement ne règle pas deux ventes.');
        }
    }

    public function requete(string $du, string $au, ?string $mode = null, string $etat = 'a_pointer'): Builder
    {
        return Paiement::with(['vente', 'client', 'caissier', 'pointeur'])
            ->whereIn('mode', $mode && in_array($mode, self::MODES, true) ? [$mode] : self::MODES)
            ->where('montant', '!=', 0)
            ->whereDate('date_paiement', '>=', $du)->whereDate('date_paiement', '<=', $au)
            ->when($etat === 'a_pointer', fn ($q) => $q->whereNull('pointe_le')->whereNull('litige'))
            ->when($etat === 'pointes', fn ($q) => $q->whereNotNull('pointe_le'))
            ->when($etat === 'litiges', fn ($q) => $q->whereNotNull('litige'))
            ->orderBy('date_paiement');
    }

    /** Par moyen de paiement : encaissé, pointé, à pointer, en litige. */
    public function resume(string $du, string $au): Collection
    {
        return $this->requete($du, $au, null, 'tous')->get()->groupBy('mode')->map(fn (Collection $l, $mode) => [
            'mode' => $mode,
            'encaisse' => (int) $l->sum('montant'),
            'pointe' => (int) $l->whereNotNull('pointe_le')->sum('montant'),
            'a_pointer' => (int) $l->whereNull('pointe_le')->whereNull('litige')->sum('montant'),
            'nb_a_pointer' => $l->whereNull('pointe_le')->whereNull('litige')->count(),
            'litiges' => (int) $l->whereNotNull('litige')->sum('montant'),
            'nb_litiges' => $l->whereNotNull('litige')->count(),
        ])->sortBy(fn ($r) => array_search($r['mode'], self::MODES, true))->values();
    }

    /** @param  int[]  $ids */
    public function pointer(array $ids, User $auteur): int
    {
        $paiements = Paiement::whereIn('id', $ids)->whereIn('mode', self::MODES)->whereNull('pointe_le')->get();
        foreach ($paiements as $p) {
            $p->forceFill(['pointe_le' => now(), 'pointe_par' => $auteur->id, 'litige' => null])->save();
        }
        if ($paiements->isNotEmpty()) {
            JournalActivite::noter('paiement', $paiements->count().' paiement(s) électronique(s) pointé(s) pour '.gnf((int) $paiements->sum('montant')));
        }

        return $paiements->count();
    }

    public function litige(Paiement $p, string $note, User $auteur): void
    {
        $this->verifierMode($p);
        $p->forceFill(['litige' => $note, 'pointe_le' => null, 'pointe_par' => $auteur->id])->save();
        JournalActivite::noter('paiement', "Paiement introuvable : {$p->libelleMode()} de ".gnf($p->montant)
            .($p->vente ? " sur {$p->vente->numero}" : '').($p->reference ? " (n° {$p->reference})" : '')." — {$note}");
    }

    /** Erreur de pointage : le paiement redevient « à pointer ». */
    public function annuler(Paiement $p, User $auteur): void
    {
        $this->verifierMode($p);
        $p->forceFill(['litige' => null, 'pointe_le' => null, 'pointe_par' => null])->save();
        JournalActivite::noter('paiement', "Pointage annulé : {$p->libelleMode()} de ".gnf($p->montant).($p->vente ? " sur {$p->vente->numero}" : ''));
    }

    /** Rappel du tableau de bord : non pointés depuis plus de 2 jours, et litiges ouverts. */
    public function rappel(): array
    {
        $base = fn () => Paiement::whereIn('mode', self::MODES)->where('montant', '!=', 0);
        $retard = $base()->whereNull('pointe_le')->whereNull('litige')->where('date_paiement', '<', now()->subDays(self::JOURS_RAPPEL)->startOfDay());

        return [
            'nb' => (clone $retard)->count(), 'montant' => (int) (clone $retard)->sum('montant'), 'depuis' => (clone $retard)->min('date_paiement'),
            'nb_litiges' => $base()->whereNotNull('litige')->count(), 'litiges' => (int) $base()->whereNotNull('litige')->sum('montant'),
        ];
    }

    private function verifierMode(Paiement $p): void
    {
        if (! in_array($p->mode, self::MODES, true)) {
            throw new OperationRefusee('Seuls les paiements électroniques se pointent.');
        }
    }
}
