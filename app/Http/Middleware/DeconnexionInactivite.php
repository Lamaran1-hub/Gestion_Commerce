<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une fois connecté, on navigue librement ; après N minutes sans activité
 * (config gestion.inactivite_minutes), la session est fermée et le mot de passe redemandé.
 */
class DeconnexionInactivite
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $derniere = $request->session()->get('derniere_activite');
            $limite = config('gestion.inactivite_minutes') * 60;

            if ($derniere && now()->timestamp - $derniere > $limite) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $message = 'Vous avez été déconnecté après '.config('gestion.inactivite_minutes').' minutes d\'inactivité. Reconnectez-vous pour continuer.';

                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 401);
                }

                return redirect()->route('login')->with('info', $message)->with('afficher_logo', true);
            }
            $request->session()->put('derniere_activite', now()->timestamp);
        }

        return $next($request);
    }
}
