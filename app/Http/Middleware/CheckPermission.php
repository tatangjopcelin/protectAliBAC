<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permission  Format: "resource.action" (ex: "products.create")
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        if (!$user->hasPermission($permission)) {
            return response()->json([
                'message' => 'Accès refusé. Vous n\'avez pas la permission nécessaire.',
                'required_permission' => $permission,
                'user_role' => $user->role,
            ], 403);
        }

        return $next($request);
    }
}
