<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité sur toutes les réponses :
 * - CSP : scripts, styles, images et polices viennent seulement de l'application (tout est hébergé dans public/vendor),
 *   pas d'objets (Flash…), pas d'intégration de nos pages dans un site tiers (clickjacking) ;
 * - pas de détection de type MIME par le navigateur (un fichier envoyé ne s'exécute pas comme un script) ;
 * - l'adresse complète des pages (numéros de vente, recherches) ne part pas vers les sites ouverts depuis l'application ;
 * - seule la caméra est autorisée (scan des codes-barres à la caisse) ;
 * - HTTPS imposé par le navigateur en production (HSTS), une fois le site servi en HTTPS.
 */
class EnTetesSecurite
{
    public function handle(Request $request, Closure $next): Response
    {
        $reponse = $next($request);
        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');   // ne pas annoncer la version de PHP
        }
        $h = $reponse->headers;
        $h->remove('X-Powered-By');
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        if (! $h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', $this->csp());
        }
        if ($request->isSecure() && app()->isProduction()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $reponse;
    }

    private function csp(): string
    {
        // Les fichiers envoyés (logos, photos) sont servis à l'adresse APP_URL, qui peut différer de l'adresse ouverte
        $app = parse_url((string) config('app.url'));
        $origine = isset($app['scheme'], $app['host']) ? ' '.$app['scheme'].'://'.$app['host'].(isset($app['port']) ? ':'.$app['port'] : '') : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:".$origine,
            "font-src 'self' data:",
            "connect-src 'self'",
            "media-src 'self' blob:",
            "worker-src 'self'",
            "manifest-src 'self'",
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            // Les formulaires restent sur l'application ; la commande de licence redirige vers la page de paiement Djomy (HTTPS)
            "form-action 'self' https:",
        ]);
    }
}
