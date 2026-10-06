<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->est_super_admin, 403, 'Espace réservé à l\'administration de la plateforme.');

        return $next($request);
    }
}
