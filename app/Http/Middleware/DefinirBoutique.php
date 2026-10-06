<?php

namespace App\Http\Middleware;

use App\Support\BoutiqueCourante;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Active l'isolation des données sur la boutique de l'utilisateur connecté,
 * ou sur la boutique du réseau qu'il a choisie (administrateur d'un réseau de boutiques).
 */
class DefinirBoutique
{
    public function __construct(private BoutiqueCourante $courante)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->boutique_id) {
            $boutique = $user->boutique;
            $choisie = $request->hasSession() ? $request->session()->get('boutique_active') : null;
            if ($choisie && $choisie !== $boutique->id) {
                // Toujours revérifié : l'accès peut avoir été retiré depuis le choix
                $autre = $user->boutiquesAccessibles()->firstWhere('id', $choisie);
                $autre ? $boutique = $autre : $request->session()->forget('boutique_active');
            }
            $this->courante->definir($boutique);
        }

        return $next($request);
    }
}
