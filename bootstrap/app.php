<?php

use App\Exceptions\OperationRefusee;
use App\Http\Middleware\DeconnexionInactivite;
use App\Http\Middleware\DefinirBoutique;
use App\Http\Middleware\EspaceBoutique;
use App\Http\Middleware\SuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\EnTetesSecurite::class);   // toutes les réponses, y compris webhooks et erreurs
        $middleware->web(append: [DeconnexionInactivite::class, DefinirBoutique::class]);
        // Les webhooks des prestataires ne portent pas de jeton CSRF : ils sont authentifiés par signature HMAC
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        // La boutique doit être connue AVANT la résolution des {modèles} dans les URL,
        // sinon un utilisateur pourrait ouvrir la fiche d'une autre boutique.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: DefinirBoutique::class,
        );
        $middleware->alias([
            'boutique' => EspaceBoutique::class,
            'fonction' => \App\Http\Middleware\ExigerFonction::class,
            'super_admin' => SuperAdmin::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn (Request $r) => $r->user()?->est_super_admin ? route('admin.dashboard') : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Les erreurs métier reviennent au formulaire avec un message clair
        $exceptions->render(function (OperationRefusee $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('erreur', $e->getMessage());
        });
    })->create();
