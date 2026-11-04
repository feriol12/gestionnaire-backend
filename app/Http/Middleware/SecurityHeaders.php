<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité compatibles avec une API JSON / PDF (SEC-AUTH-02A).
 *
 * Volontairement minimal : pas de CSP (réponses API, pas de pages HTML
 * applicatives) et pas de HSTS (géré au niveau de la plateforme/du domaine).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Interdit au navigateur de "deviner" un autre type que Content-Type.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Une API n'a aucune raison de transmettre une URL de provenance.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
