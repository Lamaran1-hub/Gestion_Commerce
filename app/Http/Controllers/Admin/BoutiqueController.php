<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\JournalActivite;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Services\BoutiqueService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Clients du logiciel (boutiques) : fiche, licence, utilisateurs. Réservé au propriétaire. */
class BoutiqueController extends Controller
{
    public function index(Request $request)
    {
        $boutiques = Boutique::with('plan')->withCount('utilisateurs')
            ->when($request->q, fn ($q) => $q->where(fn ($s) => $s->where('nom', 'like', "%{$request->q}%")
                ->orWhere('telephone', 'like', "%{$request->q}%")->orWhere('responsable_nom', 'like', "%{$request->q}%")
                ->orWhere('ville', 'like', "%{$request->q}%")))
            ->when($request->etat === 'essai', fn ($q) => $q->where('statut', 'essai'))
            ->when($request->etat === 'actif', fn ($q) => $q->where('statut', 'actif')
                ->where(fn ($s) => $s->whereNull('abonnement_expire_le')->orWhereDate('abonnement_expire_le', '>=', now()->toDateString())))
            ->when($request->etat === 'expire', fn ($q) => $q->where('statut', '!=', 'suspendu')->whereDate('abonnement_expire_le', '<', now()->toDateString()))
            ->when($request->etat === 'bientot', fn ($q) => $q->where('statut', '!=', 'suspendu')
                ->whereDate('abonnement_expire_le', '>=', now()->toDateString())->whereDate('abonnement_expire_le', '<=', now()->addDays(7)->toDateString()))
            ->when($request->etat === 'suspendu', fn ($q) => $q->where('statut', 'suspendu'))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.boutiques.index', compact('boutiques'));
    }

    public function create()
    {
        return view('admin.boutiques.create', ['plans' => Plan::where('actif', true)->orderBy('prix_mensuel')->get()]);
    }

    public function store(Request $request, BoutiqueService $service)
    {
        $d = $request->validate($this->reglesFiche() + [
            'statut' => ['required', Rule::in(['essai', 'actif'])],
            'abonnement_expire_le' => ['required', 'date', 'after_or_equal:today'],
            'admin_prenom' => ['required', 'string', 'max:80'],
            'admin_nom' => ['required', 'string', 'max:80'],
            'admin_email' => ['required', 'email', 'unique:users,email'],
            'admin_telephone' => ['nullable', 'string', 'max:30'],
        ]);
        $motDePasse = Str::password(10, symbols: false);

        $boutique = $service->creer(
            collect($d)->only(array_merge(array_keys($this->reglesFiche()), ['statut', 'abonnement_expire_le']))->all(),
            ['prenom' => $d['admin_prenom'], 'nom' => $d['admin_nom'], 'email' => $d['admin_email'],
                'telephone' => $d['admin_telephone'] ?? null, 'password' => $motDePasse],
            doitChangerMotDePasse: true,
        );

        JournalActivite::noterPour($boutique->id, 'licence', 'Client enregistré par le propriétaire ('.$boutique->libelleStatut().')');

        return redirect()->route('admin.boutiques.show', $boutique)
            ->with('identifiants', ['email' => $d['admin_email'], 'mot_de_passe' => $motDePasse, 'nom' => $d['admin_prenom'],
                'telephone' => $d['admin_telephone'] ?? $d['responsable_telephone'] ?? $d['telephone'] ?? null])
            ->with('succes', 'Client enregistré. Transmettez-lui ses identifiants (affichés ci-dessous une seule fois).');
    }

    public function show(Boutique $boutique)
    {
        $sansFiltre = fn ($modele) => $modele::withoutGlobalScope('boutique')->where('boutique_id', $boutique->id);

        return view('admin.boutiques.show', [
            'boutique' => $boutique->load('plan'),
            'plans' => Plan::orderBy('prix_mensuel')->get(),
            'utilisateurs' => User::where('boutique_id', $boutique->id)->with('role')->orderByDesc('actif')->orderBy('prenom')->get(),
            'paiements' => $boutique->paiementsLicence()->with('auteur')->limit(20)->get(),
            'totalPaye' => (int) $boutique->paiementsLicence()->reels()->sum('montant'), // hors paiements de test
            'demandes' => $boutique->demandes()->latest('dernier_message_le')->limit(5)->get(),
            'journal' => JournalActivite::where('boutique_id', $boutique->id)->with('user')->latest('id')->limit(15)->get(),
            'stats' => [
                'produits' => $sansFiltre(Produit::class)->count(),
                'ventes' => $sansFiltre(Vente::class)->where('statut', 'validee')->count(),
                'ca_mois' => (int) $sansFiltre(Vente::class)->where('statut', 'validee')->where('date_vente', '>=', now()->startOfMonth())->sum('total_ttc'),
                'derniere_vente' => $sansFiltre(Vente::class)->max('date_vente'),
                'derniere_connexion' => User::where('boutique_id', $boutique->id)->max('derniere_connexion'),
            ],
        ]);
    }

    /**
     * Conditions particulières d'un client : limites différentes de sa formule (vide = formule, 0 = illimité)
     * et fonctions accordées en plus. Tracé dans le journal de la boutique.
     */
    public function derogations(Request $request, Boutique $boutique)
    {
        $colonnes = collect(config('gestion.limites'))->pluck(0)->all();
        $d = $request->validate(collect($colonnes)->mapWithKeys(fn ($c) => [$c => ['nullable', 'integer', 'min:0', 'max:100000']])->all() + [
            'fonctions' => ['nullable', 'array'],
            'fonctions.*' => [\Illuminate\Validation\Rule::in(array_keys(config('gestion.fonctions')))],
            'motif' => ['nullable', 'string', 'max:200'],
        ]);
        $derogations = collect($colonnes)->mapWithKeys(fn ($c) => [$c => isset($d[$c]) ? (int) $d[$c] : null])->filter(fn ($v) => $v !== null)->all();
        // On n'accorde que ce que la formule n'inclut pas déjà
        $fonctions = array_values(array_filter($d['fonctions'] ?? [], fn ($f) => ! ($boutique->plan?->inclut($f) ?? true)));
        if ($fonctions) {
            $derogations['fonctions'] = $fonctions;
        }
        $boutique->update(['derogations' => $derogations ?: null]);

        $resume = collect($derogations)->except('fonctions')->map(fn ($v, $c) => $c.' = '.($v === 0 ? 'illimité' : $v))->values()
            ->merge(array_map(fn ($f) => '+ '.config("gestion.fonctions.{$f}.0"), $fonctions))->implode(', ');
        JournalActivite::noterPour($boutique->id, 'licence', "Conditions de la formule modifiées par l'éditeur : "
            .($resume ?: 'retour aux conditions standard').($d['motif'] ?? null ? " ({$d['motif']})" : ''));

        return back()->with('succes', 'Conditions enregistrées pour « '.$boutique->nom.' ».');
    }

    public function edit(Boutique $boutique)
    {
        return view('admin.boutiques.edit', ['boutique' => $boutique, 'plans' => Plan::orderBy('prix_mensuel')->get()]);
    }

    public function update(Request $request, Boutique $boutique)
    {
        $avant = [$boutique->statut, $boutique->abonnement_expire_le?->toDateString()];
        $boutique->update($request->validate($this->reglesFiche() + [
            'statut' => ['required', Rule::in(['essai', 'actif', 'suspendu'])],
            'abonnement_expire_le' => ['nullable', 'date'],
            'notes_internes' => ['nullable', 'string', 'max:2000'],
        ]));
        // Une correction manuelle de la licence est tracée (elle ne passe pas par un paiement)
        if ($avant !== [$boutique->statut, $boutique->abonnement_expire_le?->toDateString()]) {
            JournalActivite::noterPour($boutique->id, 'licence', 'Licence corrigée manuellement : '.$boutique->libelleStatut()
                .', échéance '.($boutique->abonnement_expire_le?->format('d/m/Y') ?? 'aucune'));
        }

        return redirect()->route('admin.boutiques.show', $boutique)->with('succes', 'Fiche client mise à jour.');
    }

    /** Prolongation rapide : enregistre un paiement au tarif de la formule. */
    public function prolonger(Request $request, Boutique $boutique)
    {
        $d = $request->validate(['mois' => ['required', 'integer', 'min:1', 'max:36']]);
        $boutique->enregistrerPaiementLicence([
            'mois' => $d['mois'], 'montant' => ($boutique->plan?->prix_mensuel ?? 0) * $d['mois'], 'mode' => 'especes',
        ], $request->user());

        return back()->with('succes', "Licence prolongée jusqu'au ".$boutique->fresh()->abonnement_expire_le->format('d/m/Y').'.');
    }

    public function statut(Boutique $boutique)
    {
        $boutique->update(['statut' => $boutique->statut === 'suspendu' ? 'actif' : 'suspendu']);
        JournalActivite::noterPour($boutique->id, 'licence', $boutique->statut === 'suspendu' ? 'Boutique suspendue par le propriétaire' : 'Boutique réactivée par le propriétaire');
        // Le client est prévenu par e-mail (ses données restent conservées en cas de suspension)
        \App\Support\Courrier::envoyer(User::where('boutique_id', $boutique->id)->where('actif', true)->whereHas('role', fn ($q) => $q->where('systeme', true))->get(),
            new \App\Notifications\StatutBoutique($boutique), 'compte', $boutique->id);

        return back()->with('succes', $boutique->statut === 'suspendu'
            ? 'Boutique suspendue : ses utilisateurs ne peuvent plus travailler (les données sont conservées).'
            : 'Boutique réactivée.');
    }

    /** Informations sur le client qui achète le logiciel. */
    private function reglesFiche(): array
    {
        return [
            'nom' => ['required', 'string', 'max:120'],
            'responsable_nom' => ['nullable', 'string', 'max:120'],
            'responsable_telephone' => ['nullable', 'string', 'max:60'],
            'secteur' => ['nullable', 'string', 'max:80'],
            'telephone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:150'],
            'adresse' => ['nullable', 'string', 'max:200'],
            'ville' => ['nullable', 'string', 'max:80'],
            'rccm' => ['nullable', 'string', 'max:60'],
            'nif' => ['nullable', 'string', 'max:60'],
            'plan_id' => ['nullable', 'exists:plans,id'],
        ];
    }
}
