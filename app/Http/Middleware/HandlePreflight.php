<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandlePreflight
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');
        $requestedHeaders = $request->header('Access-Control-Request-Headers');

        // Si aucune origine (curl/server-to-server), on laisse passer sans modifier.
        if (! $origin) {
            return $next($request);
        }

        // Répondre aux preflight CORS systématiquement, sinon le navigateur bloque le vrai GET/POST.
        if ($request->isMethod('OPTIONS')) {
            // Les navigateurs exigent que les headers demandés soient explicitement autorisés.
            // Ex: Authorization, Content-Type, X-Requested-With, etc.
            $allowHeaders = $requestedHeaders
                ? $requestedHeaders
                : 'Content-Type, Authorization, Accept, X-Requested-With';

            return response('', 204)
                ->header('Access-Control-Allow-Origin', $origin)
                ->header('Vary', 'Origin')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', $allowHeaders)
                ->header('Access-Control-Max-Age', '86400');
        }

        /** @var \Symfony\Component\HttpFoundation\Response $response */
        $response = $next($request);

        // Ajouter aussi les headers CORS sur les réponses non-OPTIONS (erreurs incluses).
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
