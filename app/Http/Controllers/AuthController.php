<?php

namespace App\Http\Controllers;

use App\Models\JournalActivite;
use App\Services\SecuriteConnexion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const ESSAIS_MAX = 5;

    private const BLOCAGE_SECONDES = 900;

    public function create()
    {
        return view('auth.connexion');
    }

    public function store(Request $request)
    {
        $identifiants = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Anti-essais en série : 5 mots de passe faux pour un même compte depuis un même appareil bloquent 15 minutes
        // (la route limite aussi le nombre total de tentatives par adresse IP)
        $cle = 'connexion|'.Str::lower($identifiants['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($cle, self::ESSAIS_MAX)) {
            $minutes = (int) ceil(RateLimiter::availableIn($cle) / 60);
            throw ValidationException::withMessages(['email' => "Trop de mots de passe incorrects. Réessayez dans {$minutes} minute(s) ou utilisez « Mot de passe oublié »."]);
        }

        // Pas de « rester connecté » : la session doit expirer après la période d'inactivité
        if (! Auth::attempt($identifiants)) {
            RateLimiter::hit($cle, self::BLOCAGE_SECONDES);
            Log::warning('Connexion refusée', ['email' => Str::lower($identifiants['email']), 'ip' => $request->ip(), 'essais' => RateLimiter::attempts($cle)]);
            // Historique du compte visé ; au moment où il se bloque, son titulaire est prévenu
            app(SecuriteConnexion::class)->echec($identifiants['email'], $request, RateLimiter::attempts($cle) === self::ESSAIS_MAX);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        RateLimiter::clear($cle);

        $user = Auth::user();
        if (! $user->actif) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'Ce compte est désactivé.']);
        }

        $request->session()->regenerate();
        $request->session()->put('derniere_activite', now()->timestamp);
        $request->session()->forget('boutique_active');
        $user->forceFill(['derniere_connexion' => now()])->save();
        app(SecuriteConnexion::class)->succes($user, $request);   // nouvel appareil : le titulaire est prévenu
        JournalActivite::noter('connexion', $user->nomComplet().' s\'est connecté(e)');

        // L'appareil retient la boutique : son logo s'affichera à la prochaine connexion
        if ($user->boutique) {
            Cookie::queue('gn_boutique', $user->boutique->slug, 60 * 24 * 365);
        }

        if ($user->est_super_admin) {
            return redirect()->intended(route('admin.dashboard'));
        }

        return redirect()->intended($user->aPermission('dashboard.voir') ? route('dashboard') : route('ventes.create'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Le logo de l'entreprise s'affiche avant le formulaire de connexion
        return redirect()->route('login')->with('afficher_logo', true);
    }
}
