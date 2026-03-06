<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\UserAlertLastSeen;
use App\Policies\AlertPolicy;
use App\Services\AlertService;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function __construct(protected AlertService $alertService)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !(new AlertPolicy())->viewAny($user)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        
        $query = Alert::with('product.zone.store');

        // Filtrer obligatoirement par établissement de l'utilisateur connecté (sécurité)
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        } else {
            // Si l'utilisateur n'a pas de store_id, ne retourner aucune alerte pour la sécurité
            $query->whereRaw('1 = 0');
        }

        if ($request->has('is_read')) {
            $query->where('is_read', $request->boolean('is_read'));
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('severity')) {
            $query->where('severity', $request->severity);
        }

        $alerts = $query->orderBy('created_at', 'desc')->get();

        foreach ($alerts as $alert) {
            if ($alert->product) {
                $alert->product->status = $alert->product->getComputedStatus();
            }
            $display = $this->alertService->getExpirationAlertDisplay($alert);
            if ($display !== null) {
                $alert->message = $display['message'];
                $alert->type = $display['type'];
                $alert->severity = $display['severity'];
            }
        }

        return response()->json($alerts);
    }

    public function unread(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $storeId = $user->store_id;
        if (!$storeId) {
            return response()->json([]);
        }

        // Compteur par utilisateur : alertes créées après la dernière consultation de cet employé
        $lastSeen = UserAlertLastSeen::where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->first();

        $query = Alert::with('product.zone.store')
            ->where('store_id', $storeId);
        if ($lastSeen && $lastSeen->last_seen_at) {
            $query->where('created_at', '>', $lastSeen->last_seen_at);
        }

        $alerts = $query->orderBy('severity', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($alerts as $alert) {
            if ($alert->product) {
                $alert->product->status = $alert->product->getComputedStatus();
            }
            $display = $this->alertService->getExpirationAlertDisplay($alert);
            if ($display !== null) {
                $alert->message = $display['message'];
                $alert->type = $display['type'];
                $alert->severity = $display['severity'];
            }
        }

        return response()->json($alerts);
    }

    public function markAsRead(string $id, Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        
        $alert = Alert::where('id', $id);
        if ($user->store_id) {
            $alert->where('store_id', $user->store_id);
        } else {
            // Si l'utilisateur n'a pas de store_id, ne retourner aucune alerte pour la sécurité
            $alert->whereRaw('1 = 0');
        }
        $alert = $alert->firstOrFail();

        if (!(new AlertPolicy())->markAsRead($user, $alert)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $alert->markAsRead();

        $alert = $alert->fresh('product.zone.store');
        if ($alert->product) {
            $alert->product->status = $alert->product->getComputedStatus();
        }
        $display = $this->alertService->getExpirationAlertDisplay($alert);
        if ($display !== null) {
            $alert->message = $display['message'];
            $alert->type = $display['type'];
            $alert->severity = $display['severity'];
        }

        return response()->json($alert);
    }

    /**
     * Marquer la page alertes comme vue pour cet employé uniquement (réinitialise son badge à 0, sans toucher aux autres).
     */
    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        if (!$user->store_id) {
            return response()->json(null, 204);
        }

        UserAlertLastSeen::updateOrCreate(
            ['user_id' => $user->id, 'store_id' => $user->store_id],
            ['last_seen_at' => now()]
        );

        return response()->json(null, 204);
    }

    public function show(string $id)
    {
        $user = request()->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        
        $alert = Alert::with('product.zone.store')->where('id', $id);
        if ($user->store_id) {
            $alert->where('store_id', $user->store_id);
        } else {
            // Si l'utilisateur n'a pas de store_id, ne retourner aucune alerte pour la sécurité
            $alert->whereRaw('1 = 0');
        }
        $alert = $alert->firstOrFail();

        if ($alert->product) {
            $alert->product->status = $alert->product->getComputedStatus();
        }
        $display = $this->alertService->getExpirationAlertDisplay($alert);
        if ($display !== null) {
            $alert->message = $display['message'];
            $alert->type = $display['type'];
            $alert->severity = $display['severity'];
        }

        return response()->json($alert);
    }
}
