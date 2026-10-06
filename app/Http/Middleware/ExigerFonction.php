<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Réserve une page à la formule qui inclut la fonction (ou à la boutique qui l'a reçue en dérogation). */
class ExigerFonction
{
    public function handle(Request $request, Closure $next, string $fonction): Response
    {
        return fonction($fonction) ? $next($request) : self::refus($request, $fonction);
    }

    public static function refus(Request $request, string $fonction): Response
    {
        $libelle = config("gestion.fonctions.{$fonction}.0", $fonction);
        if ($request->expectsJson()) {
            return response()->json(['message' => "« {$libelle} » n'est pas inclus dans votre formule."], 403);
        }
        $formules = \App\Models\Plan::where('actif', true)->orderBy('prix_mensuel')->get()->filter->inclut($fonction);

        return response()->view('fonction-indisponible', ['libelle' => $libelle, 'fonction' => $fonction, 'formules' => $formules], 403);
    }
}
