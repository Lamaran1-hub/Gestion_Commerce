<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Depense;
use App\Models\LigneVente;
use App\Models\MouvementStock;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Support\Tableau;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RapportController extends Controller
{
    public const RAPPORTS = [
        'ventes' => ['Journal des ventes', 'Toutes les ventes de la période, avec paiements et reste à payer.'],
        'produits-vendus' => ['Ventes par produit', 'Quantités vendues, chiffre d\'affaires et marge par produit.'],
        'paiements' => ['Journal des encaissements', 'Tous les paiements reçus, par mode (espèces, Orange Money, MTN...).'],
        'credits' => ['Crédits clients', 'Clients qui doivent encore de l\'argent à la boutique.'],
        'stock' => ['État du stock', 'Quantités, alertes et valeur du stock au prix d\'achat.'],
        'depenses' => ['Journal des dépenses', 'Dépenses de la période par catégorie.'],
        'resultat' => ['Compte de résultat simplifié', 'Chiffre d\'affaires, marge, pertes de stock, dépenses et bénéfice de la période.'],
        'vendeurs' => ['Ventes par vendeur', 'Nombre de ventes, chiffre d\'affaires, marge, remises accordées, annulations et retours de chaque vendeur.'],
        'categories' => ['Ventes par catégorie', 'Chiffre d\'affaires, marge et part de chaque catégorie de produits.'],
        'prix' => ['Changements de prix', 'Chaque prix modifié pendant la période : avant, après, variation, origine (fiche, réception, import…) et auteur.'],
        'pertes' => ['Pertes et écarts de stock', 'Casse, vol, périmés et écarts d\'inventaire, chiffrés au coût d\'achat.'],
        'tva' => ['TVA collectée', 'Base hors taxe et TVA des ventes, mois par mois, pour la déclaration.'],
        'comptable' => ['Écritures comptables (SYSCOHADA)', 'Journal en partie double pour votre expert-comptable : ventes, encaissements, achats, règlements fournisseurs et dépenses.'],
    ];

    public function index(Request $request)
    {
        return view('rapports.index', [
            'rapports' => self::RAPPORTS,
            'du' => $request->du ?: now()->startOfMonth()->toDateString(),
            'au' => $request->au ?: now()->toDateString(),
        ]);
    }

    /** Rapports réservés aux formules qui incluent les rapports avancés. */
    public const AVANCES = ['vendeurs', 'categories', 'pertes', 'tva', 'comptable'];

    public function export(Request $request, string $rapport, string $format)
    {
        abort_unless(isset(self::RAPPORTS[$rapport]), 404);
        abort_if(in_array($rapport, self::REPORTS_COUTS, true) && ! $request->user()->aPermission('produits.prix_achat'), 403,
            'Ce rapport montre les coûts d\'achat : il faut le droit « Voir les prix d\'achat et les marges ».');
        if (in_array($rapport, self::AVANCES, true) && ! fonction('rapports_avances')) {
            return \App\Http\Middleware\ExigerFonction::refus($request, 'rapports_avances');
        }
        $du = $request->date('du') ?? now()->startOfMonth();
        $au = $request->date('au') ?? now();
        $debut = $du->copy()->startOfDay();
        $fin = $au->copy()->endOfDay();
        $periode = 'Du '.$du->format('d/m/Y').' au '.$au->format('d/m/Y');
        $titre = self::RAPPORTS[$rapport][0];

        [$colonnes, $lignes, $montants, $totaux, $sousTitre] = match ($rapport) {
            'ventes' => $this->ventes($debut, $fin) + [4 => $periode],
            'produits-vendus' => $this->produitsVendus($debut, $fin) + [4 => $periode],
            'paiements' => $this->paiements($debut, $fin) + [4 => $periode],
            'credits' => $this->credits() + [4 => 'Situation au '.now()->format('d/m/Y')],
            'stock' => $this->stock() + [4 => 'Situation au '.now()->format('d/m/Y')],
            'depenses' => $this->depenses($du, $au) + [4 => $periode],
            'resultat' => $this->resultat($debut, $fin) + [4 => $periode],
            'vendeurs' => $this->vendeurs($debut, $fin) + [4 => $periode],
            'categories' => $this->categories($debut, $fin) + [4 => $periode],
            'pertes' => $this->pertes($debut, $fin) + [4 => $periode],
            'prix' => $this->changementsPrix($debut, $fin, $request->user()->aPermission('produits.prix_achat')) + [4 => $periode],
            'tva' => $this->tva($debut, $fin) + [4 => $periode],
            'comptable' => $this->comptable($debut, $fin) + [4 => $periode.' · journal SYSCOHADA'],
        };

        // Sans le droit « prix d'achat et marges » : les colonnes de coût disparaissent (on en déduirait les prix d'achat)
        if (! $request->user()->aPermission('produits.prix_achat')) {
            [$colonnes, $lignes, $montants, $totaux] = self::sansCouts($colonnes, $lignes, $montants, $totaux);
        }

        return Tableau::telecharger($format, $titre, $colonnes, $lignes, $montants, $sousTitre, $totaux);
    }

    /** Rapports entièrement fondés sur les coûts d'achat : réservés au droit « prix d'achat et marges ». */
    public const REPORTS_COUTS = ['resultat', 'pertes'];

    /** Colonnes qui révèlent un coût d'achat (directement ou par différence avec le prix de vente). */
    public const COLONNES_COUTS = ['marge', 'taux', 'prix_achat', 'valeur', 'cout'];

    public static function sansCouts(array $colonnes, array $lignes, array $montants, array $totaux): array
    {
        $retirer = array_flip(self::COLONNES_COUTS);

        return [array_diff_key($colonnes, $retirer), array_map(fn ($l) => array_diff_key((array) $l, $retirer), $lignes),
            array_values(array_diff($montants, self::COLONNES_COUTS)), array_diff_key($totaux, $retirer)];
    }

    private function ventes($du, $au): array
    {
        $lignes = Vente::with('client')->validees()->whereBetween('date_vente', [$du, $au])->orderBy('date_vente')->get()
            ->map(fn ($v) => ['numero' => $v->numero, 'date' => $v->date_vente->format('d/m/Y H:i'), 'client' => $v->client?->nomComplet() ?? 'Comptoir',
                'total' => $v->total_ttc, 'paye' => $v->montant_paye, 'reste' => $v->resteAPayer()])->all();

        return [['numero' => 'N°', 'date' => 'Date', 'client' => 'Client', 'total' => 'Total', 'paye' => 'Payé', 'reste' => 'Reste'],
            $lignes, ['total', 'paye', 'reste'], $this->somme($lignes, ['total', 'paye', 'reste'])];
    }

    private function produitsVendus($du, $au): array
    {
        $lignes = LigneVente::join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('ventes.boutique_id', boutique()->id)->where('ventes.statut', 'validee')->whereBetween('ventes.date_vente', [$du, $au])
            ->groupBy('lignes_vente.designation')
            ->select('lignes_vente.designation', DB::raw('SUM(lignes_vente.quantite) as quantite'), DB::raw('SUM('.\App\Support\Tva::sqlHt('lignes_vente.total').') as ca'),
                // Hors taxe : en prix TTC, la TVA comprise dans les lignes est retirée
                DB::raw('SUM('.\App\Support\Tva::sqlHt('lignes_vente.prix_unitaire * lignes_vente.quantite').' - lignes_vente.prix_achat * lignes_vente.quantite) as marge'))
            ->orderByDesc('ca')->get()
            ->map(fn ($l) => ['designation' => $l->designation, 'quantite' => (float) $l->quantite, 'ca' => (int) $l->ca, 'marge' => (int) $l->marge])->all();

        return [['designation' => 'Produit', 'quantite' => 'Quantité', 'ca' => "Chiffre d'affaires", 'marge' => 'Marge'],
            $lignes, ['ca', 'marge'], $this->somme($lignes, ['ca', 'marge'])];
    }

    private function paiements($du, $au): array
    {
        $modes = config('gestion.modes_paiement') + ['fidelite' => 'Points de fidélité', 'avoir' => 'Avoir client', 'acompte' => 'Acompte déjà versé', 'carte_cadeau' => 'Carte cadeau'];
        $lignes = Paiement::with(['vente', 'client'])->whereBetween('date_paiement', [$du, $au])
            ->whereHas('vente', fn ($q) => $q->where('statut', 'validee'))->orderBy('date_paiement')->get()
            ->map(fn ($p) => ['date' => $p->date_paiement->format('d/m/Y H:i'), 'vente' => $p->vente?->numero, 'client' => $p->client?->nomComplet() ?? 'Comptoir',
                'mode' => $modes[$p->mode] ?? $p->mode, 'reference' => $p->reference, 'montant' => $p->montant])->all();

        return [['date' => 'Date', 'vente' => 'Vente', 'client' => 'Client', 'mode' => 'Mode', 'reference' => 'Référence', 'montant' => 'Montant'],
            $lignes, ['montant'], $this->somme($lignes, ['montant'])];
    }

    private function credits(): array
    {
        // Une seule requête groupée (et non une par client) : le rapport reste rapide avec des milliers de clients
        $dettes = Vente::avecReste()->whereNotNull('client_id')
            ->select('client_id', DB::raw('SUM(total_ttc - montant_paye) as du'), DB::raw('MIN(echeance) as echeance'))
            ->selectRaw('SUM(CASE WHEN echeance < ? THEN total_ttc - montant_paye ELSE 0 END) as en_retard', [now()->toDateString()])
            ->groupBy('client_id')->get()->keyBy('client_id');
        $lignes = Client::whereIn('id', $dettes->keys())->get(['id', 'code', 'nom', 'prenom', 'telephone'])
            ->map(fn ($c) => ['code' => $c->code, 'client' => $c->nomComplet(), 'telephone' => numero_affiche($c->telephone),
                'echeance' => $dettes[$c->id]->echeance ? \Carbon\Carbon::parse($dettes[$c->id]->echeance)->format('d/m/Y') : '',
                'du' => (int) $dettes[$c->id]->du, 'en_retard' => (int) $dettes[$c->id]->en_retard])
            ->sortByDesc('du')->values()->all();

        return [['code' => 'Code', 'client' => 'Client', 'telephone' => 'Téléphone', 'echeance' => 'À payer avant le', 'du' => 'Reste à payer', 'en_retard' => 'Dont en retard'],
            $lignes, ['du', 'en_retard'], $this->somme($lignes, ['du', 'en_retard'])];
    }

    private function stock(): array
    {
        $lignes = Produit::with('categorie')->orderBy('designation')->get()->map(fn ($p) => [
            'designation' => $p->designation, 'categorie' => $p->categorie?->nom, 'stock' => $p->stock, 'seuil' => $p->seuil_alerte,
            'etat' => ['rupture' => 'Rupture', 'alerte' => 'À commander', 'ok' => 'OK'][$p->etatStock()],
            'prix_achat' => $p->prix_achat, 'prix_vente' => $p->prix_vente, 'valeur' => $p->valeurStock()])->all();

        return [['designation' => 'Produit', 'categorie' => 'Catégorie', 'stock' => 'Stock', 'seuil' => 'Seuil', 'etat' => 'État',
            'prix_achat' => "Prix d'achat", 'prix_vente' => 'Prix de vente', 'valeur' => 'Valeur'], $lignes, ['prix_achat', 'prix_vente', 'valeur'], $this->somme($lignes, ['valeur'])];
    }

    private function depenses($du, $au): array
    {
        $lignes = Depense::whereDate('date_depense', '>=', $du->toDateString())->whereDate('date_depense', '<=', $au->toDateString())->orderBy('date_depense')->get()
            ->map(fn ($d) => ['date' => $d->date_depense->format('d/m/Y'), 'categorie' => $d->categorie, 'motif' => $d->motif, 'montant' => $d->montant])->all();

        return [['date' => 'Date', 'categorie' => 'Catégorie', 'motif' => 'Motif', 'montant' => 'Montant'], $lignes, ['montant'], $this->somme($lignes, ['montant'])];
    }

    private function resultat($du, $au): array
    {
        $ventes = Vente::validees()->whereBetween('date_vente', [$du, $au]);
        $ca = (int) (clone $ventes)->sum('total_ttc');
        $tva = (int) (clone $ventes)->sum('total_tva');
        $cout = (int) LigneVente::join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')->where('ventes.boutique_id', boutique()->id)
            ->where('ventes.statut', 'validee')->whereBetween('ventes.date_vente', [$du, $au])->sum(DB::raw('lignes_vente.prix_achat * lignes_vente.quantite'));
        $depenses = (int) Depense::whereDate('date_depense', '>=', $du->toDateString())->whereDate('date_depense', '<=', $au->toDateString())->sum('montant');
        $marge = $ca - $tva - $cout;
        $pertes = $this->valeurPertes($du, $au);
        $points = (int) Paiement::where('mode', 'fidelite')->whereBetween('date_paiement', [$du, $au])
            ->whereHas('vente', fn ($q) => $q->where('statut', 'validee'))->sum('montant');
        $frais = (int) \App\Models\OperationTresorerie::whereBetween('date_operation', [$du, $au])->sum('frais');

        $lignes = [
            ['poste' => "Chiffre d'affaires TTC", 'montant' => $ca],
            ['poste' => 'TVA collectée', 'montant' => -$tva],
            ['poste' => 'Coût d\'achat des marchandises vendues', 'montant' => -$cout],
            ['poste' => 'Marge brute', 'montant' => $marge],
            ['poste' => 'Pertes et écarts de stock (casse, vol, périmés, inventaire)', 'montant' => -$pertes],
            ['poste' => 'Dépenses', 'montant' => -$depenses],
            ['poste' => 'Frais financiers (retraits, transferts mobile money et bancaires)', 'montant' => -$frais],
            ['poste' => 'Points de fidélité utilisés par les clients', 'montant' => -$points],
            ['poste' => 'Bénéfice net', 'montant' => $marge - $pertes - $depenses - $frais - $points],
        ];

        return [['poste' => 'Poste', 'montant' => 'Montant'], $lignes, ['montant'], []];
    }

    /** Mouvements de stock hors ventes et réceptions : sorties = pertes, surplus d'inventaire = gains. */
    private function mouvementsPertes($du, $au)
    {
        return MouvementStock::with('produit')->whereIn('type', ['ajustement', 'peremption', 'perte_transit'])->whereBetween('created_at', [$du, $au]);
    }

    private function valeurMouvement(MouvementStock $m): int
    {
        return (int) round($m->quantite * ($m->cout_unitaire ?? $m->produit?->prix_achat ?? 0));
    }

    private function valeurPertes($du, $au): int
    {
        return -$this->mouvementsPertes($du, $au)->get()->sum(fn ($m) => $this->valeurMouvement($m));
    }

    private function pertes($du, $au): array
    {
        $lignes = $this->mouvementsPertes($du, $au)->orderBy('created_at')->get()->map(fn (MouvementStock $m) => [
            'date' => $m->created_at->format('d/m/Y'), 'produit' => $m->produit?->designation, 'type' => $m->libelle(), 'motif' => $m->motif,
            'quantite' => $m->quantite, 'valeur' => $this->valeurMouvement($m),
        ])->all();

        return [['date' => 'Date', 'produit' => 'Produit', 'type' => 'Type', 'motif' => 'Motif', 'quantite' => 'Quantité', 'valeur' => 'Valeur (− = perte)'],
            $lignes, ['valeur'], $this->somme($lignes, ['valeur'])];
    }

    private function vendeurs($du, $au): array
    {
        $ventes = Vente::validees()->whereBetween('date_vente', [$du, $au])->get(['id', 'user_id', 'total_ttc', 'total_tva', 'remise', 'montant_retourne']);
        $couts = LigneVente::whereIn('vente_id', $ventes->pluck('id'))->selectRaw('vente_id, SUM(prix_achat * quantite) as cout')
            ->groupBy('vente_id')->pluck('cout', 'vente_id');
        $annulations = Vente::where('statut', 'annulee')->whereBetween('date_vente', [$du, $au])
            ->selectRaw('user_id, COUNT(*) as nb')->groupBy('user_id')->pluck('nb', 'user_id');
        $ids = $ventes->pluck('user_id')->merge($annulations->keys())->unique();
        $noms = User::whereIn('id', $ids->filter())->get()->keyBy('id');
        $parVendeur = $ventes->groupBy('user_id');

        $lignes = $ids->map(function ($id) use ($parVendeur, $couts, $annulations, $noms) {
            $v = $parVendeur->get($id, collect());
            $cout = $v->sum(fn ($x) => (int) ($couts[$x->id] ?? 0));

            return ['vendeur' => $noms->get($id)?->nomComplet() ?? '—', 'nb' => $v->count(), 'ca' => (int) $v->sum('total_ttc'),
                'marge' => (int) ($v->sum('total_ttc') - $v->sum('total_tva') - $cout), 'remises' => (int) $v->sum('remise'),
                'retours' => (int) $v->sum('montant_retourne'), 'annulations' => (int) ($annulations[$id] ?? 0)];
        })->sortByDesc('ca')->values()->all();

        return [['vendeur' => 'Vendeur', 'nb' => 'Ventes', 'ca' => "Chiffre d'affaires", 'marge' => 'Marge', 'remises' => 'Remises accordées',
            'retours' => 'Retours', 'annulations' => 'Annulations'], $lignes, ['ca', 'marge', 'remises', 'retours'],
            $this->somme($lignes, ['nb', 'ca', 'marge', 'remises', 'retours', 'annulations'])];
    }

    private function categories($du, $au): array
    {
        $lignes = LigneVente::join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->leftJoin('produits', 'produits.id', '=', 'lignes_vente.produit_id')
            ->leftJoin('categories', 'categories.id', '=', 'produits.categorie_id')
            ->where('ventes.boutique_id', boutique()->id)->where('ventes.statut', 'validee')->whereBetween('ventes.date_vente', [$du, $au])
            ->groupBy('categories.nom')
            ->select(DB::raw("COALESCE(categories.nom, 'Sans catégorie') as categorie"), DB::raw('SUM('.\App\Support\Tva::sqlHt('lignes_vente.total').') as ca'),
                DB::raw('SUM('.\App\Support\Tva::sqlHt('lignes_vente.total').' - lignes_vente.prix_achat * lignes_vente.quantite) as marge'))
            ->orderByDesc('ca')->get();
        $total = max(1, (int) $lignes->sum('ca'));
        $lignes = $lignes->map(fn ($l) => ['categorie' => $l->categorie, 'ca' => (int) $l->ca, 'marge' => (int) $l->marge,
            'taux' => $l->ca ? number_format($l->marge * 100 / $l->ca, 1, ',', '').' %' : '—',
            'part' => number_format($l->ca * 100 / $total, 1, ',', '').' %'])->all();

        return [['categorie' => 'Catégorie', 'ca' => 'Ventes HT (avant remise globale)', 'marge' => 'Marge', 'taux' => 'Taux de marge', 'part' => 'Part des ventes'],
            $lignes, ['ca', 'marge'], $this->somme($lignes, ['ca', 'marge'])];
    }

    private function tva($du, $au): array
    {
        $lignes = Vente::validees()->whereBetween('date_vente', [$du, $au])->orderBy('date_vente')->get(['date_vente', 'total_ttc', 'total_tva'])
            ->groupBy(fn ($v) => $v->date_vente->format('Y-m'))
            ->map(fn ($g, $mois) => ['mois' => ucfirst(Carbon::parse($mois.'-01')->locale('fr')->translatedFormat('F Y')),
                'base' => (int) ($g->sum('total_ttc') - $g->sum('total_tva')), 'tva' => (int) $g->sum('total_tva'), 'ttc' => (int) $g->sum('total_ttc')])
            ->values()->all();

        return [['mois' => 'Mois', 'base' => 'Base hors taxe', 'tva' => 'TVA collectée', 'ttc' => 'Total TTC'], $lignes, ['base', 'tva', 'ttc'],
            $this->somme($lignes, ['base', 'tva', 'ttc'])];
    }

    /**
     * Écritures comptables en partie double (plan SYSCOHADA, comptes réglables dans config/gestion.php) :
     * VT ventes (411 / 701 + 4431), TR encaissements et remboursements (trésorerie / 411),
     * AC achats (601 / 401) et règlements fournisseurs (401 / trésorerie), OD dépenses (6xx / trésorerie).
     * Les ventes annulées et leurs paiements sont exclus. Total débit = total crédit.
     */
    private function comptable($du, $au): array
    {
        $c = config('gestion.comptabilite');
        $tresorerie = fn (?string $mode) => $c['tresorerie'][$mode] ?? $c['tresorerie']['autre'];
        $lignes = [];
        $ecrire = function (string $date, string $journal, string $piece, string $compte, string $libelle, int $debit, int $credit) use (&$lignes) {
            if ($debit || $credit) {
                $lignes[] = ['date' => $date, 'journal' => $journal, 'piece' => $piece, 'compte' => $compte, 'libelle' => $libelle, 'debit' => $debit, 'credit' => $credit];
            }
        };

        foreach (Vente::validees()->with('client')->whereBetween('date_vente', [$du, $au])->orderBy('date_vente')->get() as $v) {
            $d = $v->date_vente->format('d/m/Y');
            $client = 'Vente '.$v->numero.' '.($v->client?->nomComplet() ?? 'comptoir');
            $ecrire($d, 'VT', $v->numero, $c['clients'], $client, (int) $v->total_ttc, 0);
            $ecrire($d, 'VT', $v->numero, $c['ventes'], $client, 0, (int) ($v->total_ttc - $v->total_tva));
            $ecrire($d, 'VT', $v->numero, $c['tva_collectee'], 'TVA '.$v->numero, 0, (int) $v->total_tva);
        }
        foreach (Paiement::with('vente:id,numero,statut')->whereBetween('date_paiement', [$du, $au])->whereHas('vente', fn ($q) => $q->where('statut', 'validee'))->orderBy('date_paiement')->get() as $p) {
            $d = $p->date_paiement->format('d/m/Y');
            $piece = $p->vente?->numero ?? '';
            $compte = match ($p->mode) { 'fidelite' => $c['remises_accordees'], 'avoir' => $c['avoirs_clients'] ?? '4191', 'acompte' => $c['acomptes_clients'] ?? '4191', 'carte_cadeau' => $c['cartes_cadeaux'] ?? '4191', default => $tresorerie($p->mode) };
            $libelle = ($p->montant < 0 ? 'Remboursement ' : 'Encaissement ').$piece.' ('.libelle_mode($p->mode).')';
            $m = abs((int) $p->montant);
            $p->montant >= 0
                ? [$ecrire($d, 'TR', $piece, $compte, $libelle, $m, 0), $ecrire($d, 'TR', $piece, $c['clients'], $libelle, 0, $m)]
                : [$ecrire($d, 'TR', $piece, $c['clients'], $libelle, $m, 0), $ecrire($d, 'TR', $piece, $compte, $libelle, 0, $m)];
        }
        foreach (\App\Models\Approvisionnement::with('fournisseur')->whereDate('date_appro', '>=', $du->toDateString())->whereDate('date_appro', '<=', $au->toDateString())->orderBy('date_appro')->get() as $a) {
            $libelle = 'Achat '.$a->numero.' '.($a->fournisseur?->nom ?? '');
            $ecrire($a->date_appro->format('d/m/Y'), 'AC', $a->numero, $c['achats'], $libelle, (int) $a->total, 0);
            $ecrire($a->date_appro->format('d/m/Y'), 'AC', $a->numero, $c['fournisseurs'], $libelle, 0, (int) $a->total);
        }
        // Acomptes reçus sur commandes (et remboursés) : trésorerie ↔ clients, avances reçues
        foreach (\App\Models\Acompte::with('devis:id,numero')->whereBetween('date_versement', [$du, $au])->orderBy('date_versement')->get() as $ac) {
            $d = $ac->date_versement->format('d/m/Y');
            $piece = $ac->devis?->numero ?? '';
            $libelle = ($ac->montant < 0 ? 'Acompte remboursé ' : 'Acompte reçu ').$piece.' ('.libelle_mode($ac->mode).')';
            $m = abs((int) $ac->montant);
            $avances = $c['acomptes_clients'] ?? '4191';
            $ac->montant >= 0
                ? [$ecrire($d, 'TR', $piece, $tresorerie($ac->mode), $libelle, $m, 0), $ecrire($d, 'TR', $piece, $avances, $libelle, 0, $m)]
                : [$ecrire($d, 'TR', $piece, $avances, $libelle, $m, 0), $ecrire($d, 'TR', $piece, $tresorerie($ac->mode), $libelle, 0, $m)];
        }
        // Cartes cadeaux vendues (et soldes rendus) : trésorerie ↔ avances reçues ; le chiffre d'affaires vient quand la carte est dépensée
        foreach (\App\Models\MouvementCarteCadeau::with('carte:id,code')->whereNotNull('mode')->whereBetween('date_mouvement', [$du, $au])->orderBy('date_mouvement')->get() as $mc) {
            $d = $mc->date_mouvement->format('d/m/Y');
            $piece = 'CC'.$mc->carte_cadeau_id;
            $libelle = ($mc->montant < 0 ? 'Carte cadeau remboursée ' : 'Carte cadeau vendue ').$mc->carte?->codeMasque().' ('.libelle_mode($mc->mode).')';
            $m = abs((int) $mc->montant);
            $avances = $c['cartes_cadeaux'] ?? '4191';
            $mc->montant >= 0
                ? [$ecrire($d, 'TR', $piece, $tresorerie($mc->mode), $libelle, $m, 0), $ecrire($d, 'TR', $piece, $avances, $libelle, 0, $m)]
                : [$ecrire($d, 'TR', $piece, $avances, $libelle, $m, 0), $ecrire($d, 'TR', $piece, $tresorerie($mc->mode), $libelle, 0, $m)];
        }
        // Avoir fournisseur : aucun argent ne bouge, le compte fournisseur a déjà été soldé par l'écriture du retour
        foreach (\App\Models\PaiementFournisseur::with('fournisseur')->where('mode', '!=', \App\Models\PaiementFournisseur::MODE_AVOIR)
            ->whereBetween('date_paiement', [$du, $au])->orderBy('date_paiement')->get() as $pf) {
            $libelle = ($pf->montant < 0 ? 'Remboursement fournisseur ' : 'Règlement fournisseur ').($pf->fournisseur?->nom ?? '');
            $m = abs((int) $pf->montant);
            $pf->montant >= 0
                ? [$ecrire($pf->date_paiement->format('d/m/Y'), 'TR', 'RF'.$pf->id, $c['fournisseurs'], $libelle, $m, 0), $ecrire($pf->date_paiement->format('d/m/Y'), 'TR', 'RF'.$pf->id, $tresorerie($pf->mode), $libelle, 0, $m)]
                : [$ecrire($pf->date_paiement->format('d/m/Y'), 'TR', 'RF'.$pf->id, $tresorerie($pf->mode), $libelle, $m, 0), $ecrire($pf->date_paiement->format('d/m/Y'), 'TR', 'RF'.$pf->id, $c['fournisseurs'], $libelle, 0, $m)];
        }
        // Retour de marchandise au fournisseur : annule l'achat à hauteur de la valeur renvoyée
        foreach (\App\Models\RetourFournisseur::with('fournisseur')->whereBetween('created_at', [$du, $au])->orderBy('created_at')->get() as $rf) {
            $libelle = 'Retour fournisseur '.$rf->numero.' '.($rf->fournisseur?->nom ?? '');
            $ecrire($rf->created_at->format('d/m/Y'), 'AC', $rf->numero, $c['fournisseurs'], $libelle, (int) $rf->montant, 0);
            $ecrire($rf->created_at->format('d/m/Y'), 'AC', $rf->numero, $c['achats'], $libelle, 0, (int) $rf->montant);
        }
        foreach (Depense::whereDate('date_depense', '>=', $du->toDateString())->whereDate('date_depense', '<=', $au->toDateString())->orderBy('date_depense')->get() as $dep) {
            $libelle = 'Dépense : '.$dep->motif;
            $ecrire($dep->date_depense->format('d/m/Y'), 'OD', 'DP'.$dep->id, $c['charges'][$dep->categorie] ?? $c['charges_defaut'], $libelle, (int) $dep->montant, 0);
            $ecrire($dep->date_depense->format('d/m/Y'), 'OD', 'DP'.$dep->id, $tresorerie($dep->mode), $libelle, 0, (int) $dep->montant);
        }

        return [['date' => 'Date', 'journal' => 'Journal', 'piece' => 'Pièce', 'compte' => 'Compte', 'libelle' => 'Libellé', 'debit' => 'Débit', 'credit' => 'Crédit'],
            $lignes, ['debit', 'credit'], $this->somme($lignes, ['debit', 'credit'])];
    }

    /** Changements de prix de la période (le prix d'achat seulement avec le droit de le voir). */
    private function changementsPrix($du, $au, bool $voirAchat): array
    {
        $lignes = \App\Models\HistoriquePrix::with(['produit' => fn ($q) => $q->withTrashed(), 'auteur'])->whereBetween('created_at', [$du, $au])
            ->when(! $voirAchat, fn ($q) => $q->where('champ', '!=', 'prix_achat'))->orderBy('created_at')->get()
            ->map(fn ($h) => ['date' => $h->created_at->format('d/m/Y H:i'), 'produit' => $h->produit?->designation, 'champ' => $h->libelleChamp(),
                'avant' => $h->ancien, 'apres' => $h->nouveau, 'variation' => $h->variation() !== null ? number_format($h->variation(), 1, ',', ' ').' %' : '',
                'origine' => $h->origine, 'par' => $h->auteur?->nomComplet()])->all();

        return [['date' => 'Date', 'produit' => 'Produit', 'champ' => 'Prix', 'avant' => 'Avant', 'apres' => 'Après', 'variation' => 'Variation',
            'origine' => 'Origine', 'par' => 'Par'], $lignes, ['avant', 'apres'], []];
    }

    private function somme(array $lignes, array $cles): array
    {
        return collect($cles)->mapWithKeys(fn ($k) => [$k => array_sum(array_column($lignes, $k))])->all();
    }
}
