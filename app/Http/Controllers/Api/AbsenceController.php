<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use Illuminate\Http\Request;

class AbsenceController extends Controller
{
    /**
     * Liste des absences (programmé mais pas pointé) pour une période.
     * GET ?start_date=Y-m-d&end_date=Y-m-d
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        $canSeeAll = $user->hasSharedPermission('planning') || $user->isAdmin();
        $requestedUserId = $request->has('user_id') ? (int) $request->user_id : null;
        if (!$canSeeAll && ($requestedUserId === null || $requestedUserId !== $user->id)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $query = Absence::with(['user:id,name,email']);

        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }
        if ($requestedUserId !== null) {
            $query->where('user_id', $requestedUserId);
        } elseif (!$canSeeAll) {
            $query->where('user_id', $user->id);
        }

        $absences = $query->orderBy('date')->orderBy('user_id')->get();

        $items = $absences->map(function ($a) {
            return $this->formatAbsence($a);
        });

        return response()->json($items);
    }

    /**
     * Mettre à jour une absence (justifiée / non justifiée). Réservé à l'admin ou permission planning.
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        if (!$user->hasSharedPermission('planning') && !$user->isAdmin()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $absence = Absence::where('id', $id)->firstOrFail();
        if ($user->store_id && $absence->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'is_justified' => 'required|boolean',
        ]);

        $absence->update(['is_justified' => $validated['is_justified']]);
        $absence->load('user:id,name,email');

        return response()->json($this->formatAbsence($absence));
    }

    private function formatAbsence(Absence $a): array
    {
        return [
            'id' => $a->id,
            'user_id' => $a->user_id,
            'date' => $a->date->format('Y-m-d'),
            'schedule_id' => $a->schedule_id,
            'is_justified' => (bool) $a->is_justified,
            'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name, 'email' => $a->user->email] : null,
        ];
    }
}
