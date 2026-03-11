<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! str_starts_with($request->path(), 'api')) {
            return $next($request);
        }
        Log::info('API request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'origin' => $request->header('Origin'),
        ]);

        return $next($request);
    }
}
