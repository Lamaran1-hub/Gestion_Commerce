<?php

namespace App\Http\Controllers;

use App\Models\Boutique;
use App\Models\JournalActivite;
use App\Services\Reseau;
use Illuminate\Http\Request;

/** Mes boutiques : vue d'ensemble du réseau, changement de boutique, nouveau point de vente (administrateur). */
class ReseauController extends Controller
{
    private function verifierAdministrateur(Request $request): void
    {
        abort_unless($request->user()->role?->systeme, 403, 'Réservé à l\'administrateur de la boutique.');
    }

    public function index(Request $request, Reseau $reseau)
    {
        $this->verifierAdministrateur($request);
        $boutiques = $request->user()->boutiquesAccessibles();
        $chiffres = $reseau->vueEnsemble($boutiques);

        $origine = $request->user()->boutique;
        try {
            $reseau->verifierAjout($origine);
            $blocage = null;
        } catch (\App\Exceptions\OperationRefusee $e) {
            $blocage = $e->getMessage();
        }

        return view('reseau.index', [
            'chiffres' => $chiffres,
            'max' => $origine->maxBoutiques(),
            'formule' => $origine->principale()->plan?->nom,
            'blocage' => $blocage,
            'totaux' => collect(['ca_jour', 'nb_jour', 'ca_mois', 'credits', 'stock', 'transferts_a_recevoir'])
                ->mapWithKeys(fn ($k) => [$k => $chiffres->sum($k)]),
        ]);
    }

    public function store(Request $request, Reseau $reseau)
    {
        $this->verifierAdministrateur($request);
        $d = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'ville' => ['nullable', 'string', 'max:80'],
            'adresse' => ['nullable', 'string', 'max:200'],
            'telephone' => ['nullable', 'string', 'max:60'],
            'copier_catalogue' => ['nullable', 'boolean'],
        ]);
        $origine = $request->user()->boutique; // le réseau se rattache toujours à la boutique de l'administrateur
        $b = $reseau->creerPointDeVente($origine, $d, $request->boolean('copier_catalogue'), $request->user());
        $request->session()->put('boutique_active', $b->id);

        return redirect()->route('dashboard')->with('succes', "Point de vente « {$b->nom} » créé. Vous travaillez maintenant dans cette boutique"
            .($request->boolean('copier_catalogue') ? ' : le catalogue a été copié, faites-y un transfert ou une réception pour le stock.' : '.')
            .' Sa licence est en période d\'essai.');
    }

    /** Passer à une autre boutique du réseau (l'accès est revérifié à chaque requête). */
    public function activer(Request $request, Boutique $boutique)
    {
        abort_unless($request->user()->boutiquesAccessibles()->contains('id', $boutique->id), 403);
        $request->session()->put('boutique_active', $boutique->id);
        JournalActivite::noterPour($boutique->id, 'reseau', $request->user()->nomComplet().' travaille dans cette boutique');

        return redirect()->route($request->user()->aPermission('dashboard.voir') ? 'dashboard' : 'ventes.create')
            ->with('succes', "Vous travaillez maintenant dans « {$boutique->nom} ».");
    }
}
