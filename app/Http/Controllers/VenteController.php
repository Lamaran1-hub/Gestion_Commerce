<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\JournalActivite;
use App\Models\Produit;
use App\Models\VenteEnAttente;
use App\Models\Vente;
use App\Services\VenteService;
use App\Support\Tableau;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VenteController extends Controller
{
    public function __construct(private VenteService $service)
    {
    }

    public function index(Request $request)
    {
        $ventes = $this->filtrer($request)->with(['client', 'vendeur'])->latest('date_vente')->paginate(25)->withQueryString();

        return view('ventes.index', [
            'ventes' => $ventes,
            'totaux' => $this->filtrer($request)->where('statut', 'validee')
                ->selectRaw('COALESCE(SUM(total_ttc),0) as ttc, COALESCE(SUM(montant_paye),0) as paye')->first(),
        ]);
    }

    public function export(Request $request)
    {
        $lignes = $this->filtrer($request)->with(['client', 'vendeur'])->latest('date_vente')->get()->map(fn (Vente $v) => [
            'numero' => $v->numero,
            'date' => $v->date_vente->format('d/m/Y H:i'),
            'client' => $v->client?->nomComplet() ?? 'Client comptoir',
            'total' => $v->total_ttc,
            'paye' => $v->montant_paye,
            'reste' => $v->resteAPayer(),
            'statut' => $v->statut === 'annulee' ? 'Annulée' : 'Validée',
            'vendeur' => $v->vendeur?->nomComplet(),
        ])->all();

        return Tableau::telecharger($request->get('format', 'excel'), 'Ventes', [
            'numero' => 'N°', 'date' => 'Date', 'client' => 'Client', 'total' => 'Total', 'paye' => 'Payé',
            'reste' => 'Reste', 'statut' => 'Statut', 'vendeur' => 'Vendeur',
        ], $lignes, ['total', 'paye', 'reste']);
    }

    /** Écran de caisse (point de vente). */
    public function create()
    {
        $colonnes = ['id', 'categorie_id', 'designation', 'code_barre', 'prix_achat', 'prix_vente', 'taux_tva', 'prix_gros', 'quantite_gros', 'conditionnement', 'qte_conditionnement', 'prix_conditionnement', 'stock', 'unite', 'image'];
        $produits = Produit::where('actif', true)->orderBy('designation')->limit(300)->get($colonnes);
        // Un ticket repris peut contenir des produits hors des 300 premiers : on les ajoute au catalogue de la caisse
        $manquants = collect(old('lignes', []))->pluck('produit_id')->diff($produits->pluck('id'));
        if ($manquants->isNotEmpty()) {
            $produits = $produits->concat(Produit::where('actif', true)->whereIn('id', $manquants)->get($colonnes));
        }

        // Clients : tous pour une petite boutique ; au-delà, ceux qu'on sert le plus souvent, les autres se cherchent
        // Échange en cours (retour converti en bon) ou client choisi d'avance (retour en avoir)
        $echange = app(\App\Services\Echanges::class)->bon((int) (old('echange_id') ?: request()->integer('echange')))?->load('lignes', 'vente');
        $clientChoisi = (int) (old('client_id') ?: request()->integer('client')) ?: null;
        [$clients, $clientsTous, $nbClients] = \App\Support\ClientCaisse::precharges($clientChoisi);

        return view('ventes.caisse', [
            'produits' => $produits,
            'enAttente' => VenteEnAttente::with(['auteur', 'client'])->latest()->get(),
            'clients' => $clients,
            'clientsTous' => $clientsTous,
            'nbClients' => $nbClients,
            'echange' => $echange,
            // Prix convenus des clients préchargés : appliqués dès que le client est choisi
            'tarifsClients' => \App\Models\PrixClient::parClient($clients->pluck('id')),
            'clientChoisi' => $clientChoisi,
            // Dette de chaque client : la caisse prévient avant de dépasser le plafond ou le délai de crédit
            'dettes' => \App\Support\ClientCaisse::dettes($clientsTous ? null : $clients->pluck('id')),
        ]);
    }

    /** Recherche d'un client depuis la caisse (nom, téléphone, code), avec ce que la caisse doit savoir de lui. */
    public function rechercheClients(Request $request)
    {
        $q = trim((string) $request->q);
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $clients = Client::recherche($q)->orderBy('nom')->limit(20)->get(\App\Support\ClientCaisse::COLONNES);
        $dettes = \App\Support\ClientCaisse::dettes($clients->pluck('id'));

        $tarifs = \App\Models\PrixClient::parClient($clients->pluck('id'));

        return response()->json($clients->map(fn ($c) => \App\Support\ClientCaisse::donnees($c, $dettes[$c->id] ?? null) + ['tarifs' => (object) ($tarifs[$c->id] ?? [])])->values());
    }

    public function rechercheProduits(Request $request)
    {
        $promos = app(\App\Services\Promotions::class);

        return Produit::where('actif', true)->recherche($request->q)->orderBy('designation')->limit(30)
            ->get(['id', 'categorie_id', 'designation', 'code_barre', 'prix_achat', 'prix_vente', 'taux_tva', 'prix_gros', 'quantite_gros', 'conditionnement', 'qte_conditionnement', 'prix_conditionnement', 'stock', 'unite', 'image'])
            ->map(fn (Produit $p) => collect($p->only(['id', 'designation', 'code_barre', 'prix_vente', 'prix_gros', 'quantite_gros', 'conditionnement', 'qte_conditionnement', 'prix_conditionnement', 'stock', 'unite']))->put('image', $p->imageUrl())
                ->put('promo', $promos->prix($p))->put('tva', \App\Support\Tva::tauxProduit($p))->put('promo_cond', $p->aConditionnement() ? $promos->prix($p, true) : null));
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.01'],
            'lignes.*.prix_unitaire' => ['nullable'],
            'lignes.*.conditionnement' => ['nullable', 'boolean'],
            'remise' => ['nullable'],
            'montant_recu' => ['nullable'],
            'mode' => ['required', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'utiliser_points' => ['nullable', 'boolean'],
            'utiliser_avoir' => ['nullable', 'boolean'],
            'carte_cadeau' => ['nullable', 'string', 'max:20'],
            'echange_id' => ['nullable', 'integer'],
            'echeance' => ['nullable', 'date'],
            'paiements_autres' => ['nullable', 'array', 'max:5'],
            'paiements_autres.*.mode' => ['required_with:paiements_autres.*.montant', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'paiements_autres.*.montant' => ['nullable'],
            'paiements_autres.*.reference' => ['nullable', 'string', 'max:100'],
        ], ['lignes.required' => 'Ajoutez au moins un produit à la vente.']);
        $d['paiements_autres'] = array_map(fn ($p) => ['montant' => montant_saisi($p['montant'] ?? 0)] + $p, $d['paiements_autres'] ?? []);

        $d['reference'] = reference_paiement();
        $peutRemise = $request->user()->aPermission('ventes.remise');
        $d['remise'] = $peutRemise ? montant_saisi($d['remise'] ?? 0) : 0;
        $d['montant_recu'] = isset($d['montant_recu']) && $d['montant_recu'] !== '' ? montant_saisi($d['montant_recu']) : null;
        $d['lignes'] = array_map(fn ($l) => array_merge($l, isset($l['prix_unitaire']) ? ['prix_unitaire' => montant_saisi($l['prix_unitaire'])] : []), $d['lignes']);

        $vente = $this->service->creer($d, $peutRemise);
        if ($request->filled('attente_id')) {
            VenteEnAttente::whereKey($request->integer('attente_id'))->delete();   // ticket repris puis vendu
        }
        // Échange : le bon valait plus que les nouveaux articles, la différence a été rendue en espèces
        $bon = $request->filled('echange_id') ? \App\Models\Retour::where('echange_vente_id', $vente->id)->first() : null;
        $resteRendu = $bon ? (int) -\App\Models\Paiement::where('vente_id', $bon->vente_id)->where('mode', 'especes')
            ->where('reference', "Reste du bon {$bon->numero} (échange {$vente->numero})")->sum('montant') : 0;

        return redirect()->route('ventes.show', ['vente' => $vente, 'imprimer' => 1])
            ->with('succes', "Vente {$vente->numero} enregistrée.".($bon ? " Échange {$bon->numero} effectué." : '')
                .($resteRendu > 0 ? ' Rendez '.gnf($resteRendu).' au client (reste du bon d\'échange).' : ''));
    }

    /**
     * Écran tourné vers le client (tablette, second moniteur, téléphone posé sur le comptoir) : il suit le ticket
     * de la caisse ouverte dans le même navigateur, sans passer par le serveur (fonctionne aussi hors connexion).
     */
    public function ecranClient(\App\Services\Promotions $promotions)
    {
        $b = boutique();
        $promos = fonction('promotions')
            ? \App\Models\Promotion::enCours()->with('produit')->latest('id')->take(6)->get()
                ->filter(fn ($p) => $p->produit)->map(fn ($p) => ['nom' => $p->produit->designation, 'prix' => \App\Support\Tva::prixClient($p->produit->prix_vente, $p->produit),
                    'promo' => \App\Support\Tva::prixClient($promotions->prix($p->produit), $p->produit), 'img' => $p->produit->imageUrl()])
                ->filter(fn ($p) => $p['promo'] && $p['promo'] < $p['prix'])->values()
            : collect();

        return view('ventes.ecran-client', [
            'b' => $b,
            'promos' => $promos,
            'lienVitrine' => $b->vitrine_active && fonction('vitrine') ? route('vitrine.index', $b->slug) : null,
        ]);
    }

    /** La caisse vérifie que le serveur répond avant d'envoyer une vente (et récupère un jeton CSRF frais). */
    public function ping()
    {
        return response()->json(['jeton' => csrf_token()]);
    }

    /**
     * Envoi des ventes faites hors connexion, dans l'ordre où elles ont eu lieu.
     * - Une vente déjà reçue (même identifiant) n'est jamais enregistrée deux fois.
     * - Elle garde sa date et ses prix réels ; un prix différent du tarif actuel est signalé.
     * - Le stock sort même s'il devient négatif (la marchandise est déjà partie) : alerte d'inventaire.
     * - Une vente refusée (période clôturée, remise hors règles…) reste sur l'appareil avec le motif.
     */
    public function synchroniser(Request $request)
    {
        $modes = array_keys(config('gestion.modes_paiement'));
        $request->validate([
            'ventes' => ['required', 'array', 'max:100'],
            'ventes.*.uuid' => ['required', 'uuid'],
            'ventes.*.cree_le' => ['required', 'date'],
            'ventes.*.client_id' => ['nullable', 'integer'],
            'ventes.*.lignes' => ['required', 'array', 'min:1'],
            'ventes.*.lignes.*.produit_id' => ['required', 'integer'],
            'ventes.*.lignes.*.quantite' => ['required', 'numeric', 'min:0.01'],
            'ventes.*.lignes.*.conditionnement' => ['nullable', 'boolean'],
            'ventes.*.lignes.*.prix_unitaire' => ['required', 'integer', 'min:0'],
            'ventes.*.remise' => ['nullable', 'integer', 'min:0'],
            'ventes.*.mode' => ['required', Rule::in($modes)],
            'ventes.*.montant_recu' => ['nullable', 'integer', 'min:0'],
            'ventes.*.reference' => ['nullable', 'string', 'max:100'],
            'ventes.*.paiements_autres' => ['nullable', 'array', 'max:5'],
            'ventes.*.paiements_autres.*.mode' => ['required', Rule::in($modes)],
            'ventes.*.paiements_autres.*.montant' => ['required', 'integer', 'min:0'],
            'ventes.*.paiements_autres.*.reference' => ['nullable', 'string', 'max:100'],
        ]);
        $peutRemise = $request->user()->aPermission('ventes.remise');
        $resultats = [];

        foreach (collect($request->ventes)->sortBy('cree_le') as $v) {
            $uuid = $v['uuid'];
            if ($deja = Vente::where('uuid_hors_ligne', $uuid)->first()) {
                $resultats[] = ['uuid' => $uuid, 'statut' => 'deja', 'numero' => $deja->numero];

                continue;
            }
            try {
                $date = \Carbon\Carbon::parse($v['cree_le'])->setTimezone(config('app.timezone'));
                if ($date->isAfter(now()->addMinutes(5))) {
                    $date = now(); // horloge de l'appareil en avance
                }
                if ($date->isBefore(now()->subDays(7))) {
                    throw new \App\Exceptions\OperationRefusee('Vente de plus de 7 jours : vérifiez-la puis saisissez-la manuellement.');
                }
                $client = ! empty($v['client_id']) ? Client::find($v['client_id']) : null;
                // Tarif actuel, pour repérer les prix différents de ceux du serveur
                [$reference] = $this->service->detaillerLignes(collect($v['lignes'])->map(fn ($l) => collect($l)->except('prix_unitaire')->all()), $client, false);

                $vente = $this->service->creer([
                    'client_id' => $client?->id, 'lignes' => $v['lignes'], 'remise' => $peutRemise ? (int) ($v['remise'] ?? 0) : 0,
                    'mode' => $v['mode'], 'montant_recu' => $v['montant_recu'] ?? null, 'reference' => $v['reference'] ?? null,
                    'paiements_autres' => $v['paiements_autres'] ?? [],
                    'hors_ligne' => ['uuid' => $uuid, 'date' => $date],
                ], true);

                $alertes = [];
                foreach ($vente->lignes->values() as $i => $l) {
                    $tarif = $reference[$i]['prix'] ?? null;
                    if ($tarif !== null && $l->prix_unitaire !== $tarif) {
                        $alertes[] = "« {$l->designation} » vendu ".gnf($l->prix_unitaire).' (tarif actuel '.gnf($tarif).')';
                    }
                    if ($l->produit && $l->produit->fresh()->stock < 0) {
                        $alertes[] = "Stock négatif pour « {$l->produit->designation} » : faites l'inventaire";
                    }
                }
                JournalActivite::noter('vente', "Vente hors connexion {$vente->numero} du ".$date->format('d/m/Y H:i').' envoyée'
                    .($alertes ? ' — à vérifier : '.implode(' ; ', $alertes) : ''));
                $resultats[] = ['uuid' => $uuid, 'statut' => 'ok', 'numero' => $vente->numero, 'alertes' => $alertes];
            } catch (\App\Exceptions\OperationRefusee $e) {
                $resultats[] = ['uuid' => $uuid, 'statut' => 'erreur', 'message' => $e->getMessage()];
            }
        }

        return response()->json(['resultats' => $resultats]);
    }

    public function show(Vente $vente)
    {
        $vente->load(['lignes.produit', 'lignes.numerosSerie', 'client', 'vendeur', 'paiements.caissier', 'retours.lignes', 'retours.auteur', 'retours.venteEchange']);

        return view('ventes.show', compact('vente'));
    }

    /** Retour partiel de marchandise (avoir). */
    public function retour(Request $request, Vente $vente, \App\Services\RetourService $retours)
    {
        $request->validate([
            'quantites' => ['required', 'array'],
            'quantites.*' => ['nullable', 'numeric', 'min:0'],
            'series_retour' => ['nullable', 'array'],
            'series_retour.*' => ['array'],
            'motif' => ['required', 'string', 'max:150'],
            'motif_autre' => ['nullable', 'string', 'max:150'],
            'mode_remboursement' => ['required', Rule::in([...array_keys(config('gestion.modes_paiement')), \App\Services\Avoirs::MODE, \App\Services\Echanges::MODE])],
        ], ['motif.required' => 'Indiquez le motif du retour.']);

        $retour = $retours->enregistrer($vente, $request->quantites, choix_autre('motif'), $request->mode_remboursement, $request->user(), $request->input('series_retour', []));

        if ($request->mode_remboursement === \App\Services\Echanges::MODE) {
            // Échange : on passe directement en caisse, le bon paie les nouveaux articles
            return redirect()->route('ventes.create', array_filter(['echange' => $retour->echange_restant > 0 ? $retour->id : null, 'client' => $vente->client_id]))
                ->with('succes', "Retour {$retour->numero} enregistré : ".gnf($retour->montant).' de marchandise remise en stock. '
                    .($retour->echange_restant > 0 ? 'Bon d\'échange de '.gnf($retour->echange_restant).' : ajoutez les articles que le client prend à la place.'
                        : 'Rien n\'avait été payé : le montant est déduit de sa dette. Ajoutez les articles que le client prend à la place.'));
        }
        if ($retour->mode_remboursement === \App\Services\Avoirs::MODE) {
            session()->flash('bon_avoir', route('retours.bon-avoir', $retour));
        }

        return back()->with('succes', "Retour {$retour->numero} enregistré : ".gnf($retour->montant).' de marchandise remise en stock.'
            .match (true) {
                $retour->mode_remboursement === \App\Services\Avoirs::MODE => ' '.gnf($retour->rembourse).' crédités en avoir au client (rien à sortir de la caisse).',
                (bool) $retour->rembourse => ' Remboursez '.gnf($retour->rembourse).' au client.',
                default => ' Montant déduit de la vente.',
            });
    }

    public function facture(Vente $vente)
    {
        $vente->load(['lignes.numerosSerie', 'client', 'paiements']);

        return Pdf::loadView('pdf.facture', ['vente' => $vente, 'boutique' => boutique()])
            ->setPaper('a4')->stream("facture-{$vente->numero}.pdf");
    }

    /** Ticket de caisse 80 mm (ancien ETAT_recu_de_commande). */
    public function recu(Vente $vente)
    {
        $vente->load(['lignes.numerosSerie', 'client', 'paiements', 'vendeur']);

        return view('ventes.recu', ['vente' => $vente, 'boutique' => boutique()]);
    }

    public function annuler(Request $request, Vente $vente)
    {
        $request->validate([
            'motif' => ['required', 'string', 'max:250'],
            'motif_autre' => ['nullable', 'string', 'max:200'],
        ], ['motif.required' => "Indiquez le motif de l'annulation."]);
        $this->service->annuler($vente, choix_autre('motif'));

        // L'argent déjà encaissé doit être rendu au client : on le rappelle clairement
        return back()->with('succes', "La vente {$vente->numero} est annulée et le stock a été réintégré."
            .($vente->montant_paye > 0 ? ' Pensez à rembourser '.gnf($vente->montant_paye).' au client.' : ''));
    }

    public function paiement(Request $request, Vente $vente)
    {
        $d = $request->validate([
            'montant' => ['required'],
            'mode' => ['required', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $this->service->encaisser($vente, montant_saisi($d['montant']), $d['mode'], reference_paiement());

        return back()->with('succes', 'Paiement de '.gnf(montant_saisi($d['montant'])).' enregistré.');
    }

    private function filtrer(Request $request)
    {
        return Vente::query()
            ->when($request->du, fn ($q) => $q->whereDate('date_vente', '>=', $request->du))
            ->when($request->au, fn ($q) => $q->whereDate('date_vente', '<=', $request->au))
            ->when($request->client_id, fn ($q) => $q->where('client_id', $request->client_id))
            ->when($request->statut === 'annulee', fn ($q) => $q->where('statut', 'annulee'))
            ->when($request->statut === 'credit', fn ($q) => $q->avecReste())
            ->when($request->statut === 'payee', fn ($q) => $q->validees()->whereColumn('montant_paye', '>=', 'total_ttc'))
            ->when($request->q, fn ($q) => $q->where(fn ($s) => $s->where('numero', 'like', "%{$request->q}%")
                ->orWhereHas('client', fn ($c) => $c->recherche($request->q))));
    }
}
