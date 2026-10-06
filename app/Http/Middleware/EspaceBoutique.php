<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Réservé aux utilisateurs d'une boutique active, avec un abonnement valide. */
class EspaceBoutique
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->est_super_admin) {
            return redirect()->route('admin.dashboard');
        }
        if (! $user->actif || ! $user->boutique) {
            Auth::logout();

            return redirect()->route('login')->with('erreur', 'Votre compte est désactivé. Contactez l\'administrateur de votre boutique.');
        }
        if (! boutique()->estActive() && ! $request->routeIs('abonnement', 'profil.*', 'assistance.*', 'nouveautes.*', 'licence.*', 'reseau.*')) {
            return redirect()->route('abonnement');
        }

        return $next($request);
    }
}
