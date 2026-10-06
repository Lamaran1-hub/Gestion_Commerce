<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\CommissionVersee;
use App\Models\Depense;
use App\Models\JournalActivite;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Commissions des vendeurs, mois par mois.
 *
 * - Un mois n'est payable qu'une fois terminé : le mois en cours reste provisoire.
 * - Au versement, le chiffre d'affaires HT, le taux et le montant sont figés (un changement de taux ne réécrit pas le passé).
 * - Le versement crée une dépense « Salaires » : le bénéfice en tient compte et, payé en espèces, il sort de la caisse du jour.
 * - Un versement ne peut être annulé que par l'administrateur, tant que la caisse et la période le permettent.
 */
class Commissions
{
    public function __construct(private Objectifs $objectifs, private CaisseService $caisse)
    {
    }

    public static function estTermine(Carbon $mois): bool
    {
        return $mois->copy()->endOfMonth()->isPast();
    }

    /** Vendeurs du mois : calcul (ou montant figé s'il est déjà versé). */
    public function releve(Carbon $mois): Collection
    {
        $mois = $mois->copy()->startOfMonth();
        $versees = CommissionVersee::whereDate('mois', $mois->toDateString())->with('auteur')->get()->keyBy('user_id');

        // Vendeurs actifs avec une commission, plus ceux déjà payés pour ce mois (même partis depuis)
        return User::where('boutique_id', boutique()->id)
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('actif', true)->whereNotNull('commission_pct')->where('commission_pct', '>', 0))
                ->orWhereIn('id', $versees->keys()))
            ->orderBy('prenom')->get()
            ->map(function (User $u) use ($mois, $versees) {
                $v = $versees->get($u->id);
                $calcul = $this->objectifs->vendeur($u, $mois);

                return [
                    'user' => $u,
                    'ca_ht' => $v?->ca_ht ?? $calcul['ca_ht'],
                    'taux' => $v?->taux ?? (float) $u->commission_pct,
                    'montant' => $v?->montant ?? $calcul['commission'],
                    'nb_ventes' => $calcul['nb_ventes'],
                    'versement' => $v,
                ];
            });
    }

    public function verser(User $vendeur, Carbon $mois, string $mode, ?string $reference, User $auteur): CommissionVersee
    {
        $mois = $mois->copy()->startOfMonth();
        if (! self::estTermine($mois)) {
            throw new OperationRefusee('Le mois de '.$mois->translatedFormat('F Y').' n\'est pas terminé : sa commission est encore provisoire.');
        }

        return DB::transaction(function () use ($vendeur, $mois, $mode, $reference, $auteur) {
            if (CommissionVersee::where('user_id', $vendeur->id)->whereDate('mois', $mois->toDateString())->lockForUpdate()->exists()) {
                throw new OperationRefusee("La commission de {$vendeur->nomComplet()} pour ".$mois->translatedFormat('F Y').' est déjà versée.');
            }
            $ligne = $this->releve($mois)->firstWhere('user.id', $vendeur->id);
            if (! $ligne || $ligne['montant'] <= 0) {
                throw new OperationRefusee("Aucune commission à verser à {$vendeur->nomComplet()} pour ce mois.");
            }
            // La dépense est datée du jour du versement ; en espèces, elle sort de la caisse de celui qui paie
            boutique()->verifierPeriodeOuverte(now(), 'verser une commission');
            if ($mode === 'especes') {
                $this->caisse->verifierOuverte($auteur);
            }
            $libelleMois = $mois->translatedFormat('F Y');
            $depense = Depense::create([
                'motif' => "Commission {$vendeur->nomComplet()} — {$libelleMois}", 'categorie' => 'Salaires',
                'montant' => $ligne['montant'], 'mode' => $mode, 'date_depense' => now()->toDateString(),
                'note' => 'Commission de '.rtrim(rtrim(number_format($ligne['taux'], 2, ',', ''), '0'), ',').' % sur '.gnf($ligne['ca_ht']).' HT'.($reference ? " — réf. {$reference}" : ''),
                'user_id' => $auteur->id,
            ]);
            $versement = CommissionVersee::create([
                'user_id' => $vendeur->id, 'mois' => $mois->toDateString(), 'ca_ht' => $ligne['ca_ht'], 'taux' => $ligne['taux'],
                'montant' => $ligne['montant'], 'mode' => $mode, 'reference' => $reference, 'depense_id' => $depense->id, 'verse_par' => $auteur->id,
            ]);
            JournalActivite::noter('commission', "Commission de {$libelleMois} versée à {$vendeur->nomComplet()} : ".gnf($ligne['montant']));

            return $versement;
        });
    }

    public function annuler(CommissionVersee $versement, User $auteur): void
    {
        if (! $auteur->role?->systeme) {
            throw new OperationRefusee('Seul l\'administrateur de la boutique peut annuler un versement de commission.');
        }
        DB::transaction(function () use ($versement) {
            $depense = $versement->depense;
            if ($depense) {
                boutique()->verifierPeriodeOuverte($depense->date_depense, 'annuler ce versement');
                if ($depense->mode === 'especes' && $depense->auteur && $this->caisse->estCloturee($depense->auteur, $depense->created_at)) {
                    throw new OperationRefusee('Ce versement en espèces appartient à une caisse déjà clôturée : il ne peut plus être annulé.');
                }
            }
            $versement->delete();
            $depense?->delete();
            JournalActivite::noter('commission', "Versement de commission annulé ({$versement->vendeur?->nomComplet()}, ".$versement->mois->translatedFormat('F Y').')');
        });
    }
}
