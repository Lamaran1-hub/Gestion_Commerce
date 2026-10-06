<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Demande;
use App\Models\PaiementLicence;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Support\Facades\Hash;

/** Tableau de bord du propriétaire : clients, licences, encaissements, demandes. */
class DashboardController extends Controller
{
    public function __invoke()
    {
        $boutiques = Boutique::with('plan')->get();
        $actives = $boutiques->filter(fn (Boutique $b) => $b->licencePayee());

        return view('admin.dashboard', [
            // Le compte garde-t-il le mot de passe par défaut du .env ?
            'motDePasseParDefaut' => Hash::check((string) config('gestion.super_admin.password'), auth()->user()->password),
            // Contrôle de la configuration (en ligne : tous les points ; en local : seulement les critiques)
            'securite' => array_values(array_filter(\App\Support\VerificationSecurite::aCorriger(),
                fn ($c) => $c['niveau'] === 'critique' || app()->isProduction())),
            'nb' => [
                'total' => $boutiques->count(),
                'actives' => $actives->count(),
                'essai' => $boutiques->filter(fn ($b) => $b->estActive() && $b->statut === 'essai')->count(),
                'inactives' => $boutiques->reject(fn ($b) => $b->estActive())->count(),
                'utilisateurs' => User::whereNotNull('boutique_id')->where('actif', true)->count(),
                'demandes' => Demande::where('lue_proprietaire', false)->where('statut', '!=', 'fermee')->count(),
            ],
            // Encaissements réels : les paiements de test (sandbox) ne comptent pas
            'encaisseMois' => (int) PaiementLicence::reels()->where('paye_le', '>=', now()->startOfMonth()->toDateString())->sum('montant'),
            'encaisseAnnee' => (int) PaiementLicence::reels()->where('paye_le', '>=', now()->startOfYear()->toDateString())->sum('montant'),
            'revenuMensuel' => $actives->sum(fn ($b) => $b->plan?->prix_mensuel ?? 0),
            'expirentBientot' => $boutiques->filter(fn ($b) => $b->statut !== 'suspendu' && $b->joursRestants() !== null && $b->joursRestants() <= 7)
                ->sortBy(fn ($b) => $b->joursRestants()),
            'derniersPaiements' => PaiementLicence::with('boutique')->latest('paye_le')->latest('id')->limit(6)->get(),
            'demandes' => Demande::with('boutique')->where('statut', '!=', 'fermee')->orderBy('lue_proprietaire')->latest('dernier_message_le')->limit(5)->get(),
            'recentes' => Boutique::with('plan')->latest()->limit(6)->get(),
            'ventesPlateforme' => Vente::withoutGlobalScope('boutique')->where('statut', 'validee')->where('date_vente', '>=', now()->startOfMonth())->count(),
        ]);
    }
}
