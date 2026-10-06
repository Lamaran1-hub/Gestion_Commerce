<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\Categorie;
use App\Models\Entreprise;
use App\Models\JournalActivite;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\Role;
use App\Models\User;
use App\Support\BoutiqueCourante;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Réseau de boutiques d'un même commerçant.
 * - Chaque point de vente a son catalogue, son stock, sa caisse, ses employés et sa licence.
 * - L'administrateur passe de l'un à l'autre et voit l'ensemble ; les employés restent dans leur boutique.
 */
class Reseau
{
    /** Réglages repris de la boutique d'origine pour un nouveau point de vente. */
    private const REGLAGES = ['email', 'rccm', 'nif', 'secteur', 'responsable_nom', 'responsable_telephone', 'tva_active', 'tva_taux', 'prix_ttc',
        'remise_max_pct', 'vente_a_perte', 'plafond_credit_defaut', 'delai_credit_jours', 'delai_annulation_heures', 'delai_retour_jours',
        'validite_devis_jours', 'couverture_stock_jours', 'alerte_peremption_jours', 'fidelite_taux', 'fidelite_minimum', 'methode_cout',
        'resume_quotidien', 'pied_facture', 'couleur', 'couleur_2', 'couleur_3', 'derogations'];

    /**
     * Peut-on ajouter un point de vente ? Limite de la formule de la boutique principale,
     * et boutique principale en règle (licence active).
     */
    public function verifierAjout(Boutique $origine): void
    {
        $principale = $origine->principale();
        if (! $principale->estActive()) {
            throw new \App\Exceptions\OperationRefusee("La licence de « {$principale->nom} » n'est plus active : renouvelez-la avant d'ouvrir un nouveau point de vente.");
        }
        $max = $origine->maxBoutiques();
        $nb = $origine->reseau()->count();
        if ($max && $nb >= $max) {
            throw new \App\Exceptions\OperationRefusee("Votre formule « {$principale->plan?->nom} » permet {$max} boutique(s) et votre réseau en compte déjà {$nb}. "
                .'Passez à une formule supérieure (menu Abonnement) pour ouvrir un nouveau point de vente.');
        }
    }

    public function creerPointDeVente(Boutique $origine, array $d, bool $copierCatalogue, User $admin): Boutique
    {
        $this->verifierAjout($origine);

        $contexte = app(BoutiqueCourante::class);
        $precedente = $contexte->get();

        try {
            return DB::transaction(function () use ($origine, $d, $copierCatalogue, $admin, $contexte) {
                if (! $origine->entreprise_id) {
                    $origine->update(['entreprise_id' => Entreprise::create(['nom' => $origine->nom])->id]);
                }
                $b = Boutique::create(collect($origine->only(self::REGLAGES))->all() + [
                    'entreprise_id' => $origine->entreprise_id,
                    'nom' => $d['nom'], 'ville' => $d['ville'] ?? $origine->ville, 'adresse' => $d['adresse'] ?? null,
                    'telephone' => $d['telephone'] ?? $origine->telephone,
                    // Nouvelle licence : période d'essai, comme toute nouvelle boutique
                    'statut' => 'essai', 'plan_id' => $origine->plan_id ?? Plan::where('actif', true)->orderBy('prix_mensuel')->value('id'),
                    'abonnement_expire_le' => now()->addDays(config('gestion.jours_essai'))->toDateString(),
                ]);
                // Le logo est copié (chaque boutique peut ensuite changer le sien sans toucher aux autres)
                if ($origine->logo && Storage::disk('public')->exists($origine->logo)) {
                    $chemin = 'boutiques/'.$b->id.'/'.basename($origine->logo);
                    Storage::disk('public')->copy($origine->logo, $chemin);
                    $b->update(['logo' => $chemin]);
                }

                $contexte->definir($b);
                Role::creerPourBoutique($b);
                if ($copierCatalogue) {
                    $this->copierCatalogue($origine, $b);
                }
                JournalActivite::noterPour($b->id, 'reseau', "Point de vente créé par {$admin->nomComplet()} depuis « {$origine->nom} »"
                    .($copierCatalogue ? ' (catalogue copié, stock à zéro)' : ''));
                JournalActivite::noterPour($origine->id, 'reseau', "Nouveau point de vente « {$b->nom} » ajouté au réseau");

                return $b;
            });
        } finally {
            $contexte->definir($precedente);
        }
    }

    /** Catégories et produits (prix, codes-barres, conditionnements), sans stock : il arrivera par transfert ou réception. */
    private function copierCatalogue(Boutique $origine, Boutique $cible): void
    {
        $categories = [];
        foreach (Categorie::withoutGlobalScope('boutique')->where('boutique_id', $origine->id)->get() as $c) {
            $categories[$c->id] = Categorie::create(['nom' => $c->nom])->id;
        }
        $champs = ['designation', 'code_barre', 'unite', 'conditionnement', 'qte_conditionnement', 'prix_conditionnement',
            'prix_achat', 'prix_vente', 'prix_gros', 'quantite_gros', 'seuil_alerte', 'actif'];
        Produit::withoutGlobalScope('boutique')->where('boutique_id', $origine->id)->where('actif', true)->orderBy('id')
            ->chunk(200, function ($produits) use ($categories, $champs) {
                foreach ($produits as $p) {
                    Produit::create($p->only($champs) + ['categorie_id' => $categories[$p->categorie_id] ?? null]);
                }
            });
    }

    /** Chiffres clés de chaque boutique du réseau (aujourd'hui, ce mois, crédits, stock, licence). */
    public function vueEnsemble(Collection $boutiques): Collection
    {
        $ids = $boutiques->pluck('id');
        $jour = [now()->startOfDay(), now()->endOfDay()];
        $mois = [now()->startOfMonth(), now()->endOfDay()];
        $ventes = fn () => DB::table('ventes')->whereIn('boutique_id', $ids)->where('statut', 'validee')->groupBy('boutique_id');

        $caJour = $ventes()->whereBetween('date_vente', $jour)->selectRaw('boutique_id, SUM(total_ttc) as ca, COUNT(*) as nb')->get()->keyBy('boutique_id');
        $caMois = $ventes()->whereBetween('date_vente', $mois)->pluck(DB::raw('SUM(total_ttc)'), 'boutique_id');
        $credits = $ventes()->pluck(DB::raw('SUM(total_ttc - montant_paye)'), 'boutique_id');
        $stock = DB::table('produits')->whereIn('boutique_id', $ids)->whereNull('deleted_at')->where('stock', '>', 0)
            ->groupBy('boutique_id')->pluck(DB::raw('SUM(stock * prix_achat)'), 'boutique_id');
        $ruptures = DB::table('produits')->whereIn('boutique_id', $ids)->whereNull('deleted_at')->where('actif', true)->where('stock', '<=', 0)
            ->groupBy('boutique_id')->pluck(DB::raw('COUNT(*)'), 'boutique_id');
        $enRoute = DB::table('transferts')->whereIn('boutique_destination_id', $ids)->where('statut', 'envoye')
            ->groupBy('boutique_destination_id')->pluck(DB::raw('COUNT(*)'), 'boutique_destination_id');

        return $boutiques->map(fn (Boutique $b) => [
            'boutique' => $b,
            'ca_jour' => (int) ($caJour[$b->id]->ca ?? 0), 'nb_jour' => (int) ($caJour[$b->id]->nb ?? 0),
            'ca_mois' => (int) ($caMois[$b->id] ?? 0), 'credits' => (int) ($credits[$b->id] ?? 0),
            'stock' => (int) round($stock[$b->id] ?? 0), 'ruptures' => (int) ($ruptures[$b->id] ?? 0),
            'transferts_a_recevoir' => (int) ($enRoute[$b->id] ?? 0),
        ]);
    }
}
