<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\ClotureCaisse;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Notifications\ResumeDuJour;
use App\Support\BoutiqueCourante;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Résumé du matin pour les gérants : activité de la veille et points d'attention.
 * Envoyé seulement s'il y a quelque chose à dire (activité ou alerte).
 */
class ResumeQuotidien
{
    /** @return array|null contenu du résumé, null s'il n'y a rien à signaler */
    public function calculer(Boutique $b, Carbon $jour): ?array
    {
        $contexte = app(BoutiqueCourante::class);
        $precedente = $contexte->get();
        $contexte->definir($b);

        try {
            [$debut, $fin] = [$jour->copy()->startOfDay(), $jour->copy()->endOfDay()];
            $ventes = Vente::validees()->whereBetween('date_vente', [$debut, $fin]);
            $delai = $b->delai_credit_jours ?: 30;

            $r = [
                'jour' => $jour->toDateString(),
                'nb_ventes' => (clone $ventes)->count(),
                'ca' => (int) (clone $ventes)->sum('total_ttc'),
                'encaisse' => (int) Paiement::whereBetween('date_paiement', [$debut, $fin])->sum('montant'),
                'annulations' => Vente::where('statut', 'annulee')->whereBetween('annulee_le', [$debut, $fin])->count(),
                'stock_bas' => Produit::where('actif', true)->enAlerte()->orderBy('stock')->limit(5)->pluck('designation')->all(),
                'nb_stock_bas' => Produit::where('actif', true)->enAlerte()->count(),
                // Crédits dont la date de paiement promise est dépassée
                'credits_retard' => (int) Vente::enRetard()->selectRaw('COALESCE(SUM(total_ttc - montant_paye), 0) as du')->value('du'),
                'credits_echeance_semaine' => (int) Vente::avecReste()->whereDate('echeance', '>=', now()->toDateString())
                    ->whereDate('echeance', '<=', now()->addDays(7)->toDateString())->selectRaw('COALESCE(SUM(total_ttc - montant_paye), 0) as du')->value('du'),
                'delai_credit' => $delai,
                'ecarts_caisse' => ClotureCaisse::whereDate('jour', $jour->toDateString())->where('ecart', '!=', 0)->sum('ecart'),
                'caisses_non_cloturees' => $this->caissesNonCloturees($b, $jour),
                'dettes_fournisseurs_retard' => (int) \App\Models\Approvisionnement::avecReste()->whereNotNull('echeance')
                    ->whereDate('echeance', '<', now()->toDateString())->selectRaw('COALESCE(SUM(total - montant_retourne - montant_paye), 0) as du')->value('du'),
                // Nouveaux modules : ce qui attend une action
                'livraisons_retard' => Vente::aLivrer()->whereNotNull('livraison_prevue_le')->whereDate('livraison_prevue_le', '<', now()->toDateString())->count(),
                'commandes_fournisseur_retard' => \App\Models\CommandeFournisseur::enAttente()->whereNotNull('livraison_prevue_le')
                    ->whereDate('livraison_prevue_le', '<', now()->toDateString())->count(),
                'acomptes_expires' => (int) \App\Models\Devis::where('statut', 'en_cours')->where('acompte', '>', 0)
                    ->whereDate('valable_jusqu_au', '<', now()->toDateString())->sum('acompte'),
                'series_manquantes' => $this->seriesManquantes(),
                'peremptions' => ! fonction('peremptions') ? [] : app(Peremption::class)->lots()->groupBy(fn ($l) => $l['jours'] < 0 ? 'perimes' : 'bientot')->map->count()->all(),
            ];
        } finally {
            $contexte->definir($precedente);
        }

        $rien = $r['nb_ventes'] === 0 && $r['annulations'] === 0 && $r['nb_stock_bas'] === 0 && $r['credits_retard'] === 0
            && (int) $r['ecarts_caisse'] === 0 && ! $r['caisses_non_cloturees'] && $r['dettes_fournisseurs_retard'] === 0 && ! $r['peremptions']
            && ! $r['livraisons_retard'] && ! $r['commandes_fournisseur_retard'] && ! $r['acomptes_expires'] && ! $r['series_manquantes'];

        return $rien ? null : $r;
    }

    /** Caissiers ayant encaissé la veille sans clôturer leur caisse. */
    private function caissesNonCloturees(Boutique $b, Carbon $jour): array
    {
        $ontEncaisse = Paiement::whereDate('date_paiement', $jour->toDateString())->distinct()->pluck('user_id')->filter();
        $clotures = ClotureCaisse::whereDate('jour', $jour->toDateString())->pluck('user_id');

        return User::whereIn('id', $ontEncaisse->diff($clotures))->get()->map->nomComplet()->all();
    }

    /** @return int nombre de boutiques ayant reçu un résumé */
    public function envoyer(?Carbon $jour = null): int
    {
        $jour ??= now()->subDay();
        $envoyes = 0;
        foreach (Boutique::where('resume_quotidien', true)->where('statut', '!=', 'suspendu')->get() as $b) {
            if (! $b->estActive() || ! ($contenu = $this->calculer($b, $jour))) {
                continue;
            }
            $destinataires = User::where('boutique_id', $b->id)->where('actif', true)
                ->whereHas('role', fn ($q) => $q->where('systeme', true))->get();
            if ($destinataires->isEmpty()) {
                continue;
            }
            $notification = new ResumeDuJour($b, $contenu);
            Notification::sendNow($destinataires, $notification, ['database']);
            \App\Support\Courrier::envoyer($destinataires, $notification, 'resume', $b->id); // tracé, jamais bloquant
            $envoyes++;
        }

        return $envoyes;
    }

    /** Appareils vendus ces 7 derniers jours dont le numéro de série n'a pas été noté (garantie impossible à suivre). */
    private function seriesManquantes(): int
    {
        return (int) \App\Models\LigneVente::whereHas('produit', fn ($q) => $q->where('suivi_serie', true))
            ->whereHas('vente', fn ($q) => $q->validees()->where('date_vente', '>=', now()->subDays(7)))
            ->with(['produit', 'numerosSerie'])->get()
            ->sum(fn ($l) => max(0, $l->nombreSeriesAttendues() - $l->numerosSerie->count()));
    }
}
