<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\Schedule;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Supprime l'absence puis le créneau planifié lié. Les pointages sur ce créneau sont supprimés en cascade.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        if (! $user->hasSharedPermission('planning') && ! $user->isAdmin()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $absence = Absence::where('id', $id)->firstOrFail();
        if ($user->store_id && $absence->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        try {
            DB::transaction(function () use ($absence, $user) {
                $scheduleId = $absence->schedule_id;
                $absence->delete();

                if ($scheduleId) {
                    $schedule = Schedule::where('id', $scheduleId)->first();
                    if ($schedule) {
                        if ($user->store_id && $schedule->store_id !== $user->store_id) {
                            throw new \RuntimeException('store_mismatch');
                        }
                        $schedule->delete();
                    }
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'store_mismatch') {
                return response()->json(['message' => 'Accès refusé'], 403);
            }
            throw $e;
        }

        return response()->json(['message' => 'Absence et créneau associé supprimés'], 200);
    }

    /**
     * Absences calculées automatiquement : créneaux planifiés sans arrivée pointée (clock_in),
     * dont la fin réelle du créneau + 1h est dépassée (créneaux après minuit : fin sur le jour suivant).
     * GET ?start_date=Y-m-d&end_date=Y-m-d&user_id= (optionnel)
     * Retourne : { items: [{ date, schedule_id, planned_hours }, ...], total_count, total_hours }
     */
    public function computed(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        $canSeeAll = $user->hasSharedPermission('planning') || $user->isAdmin();
        $requestedUserId = $request->has('user_id') ? (int) $request->user_id : null;
        if (! $canSeeAll && ($requestedUserId === null || $requestedUserId !== $user->id)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        if (! $startDate || ! $endDate) {
            return response()->json(['message' => 'start_date et end_date requis'], 422);
        }

        $userId = $requestedUserId ?? $user->id;
        $now = Carbon::now();

        $schedules = Schedule::where('store_id', $user->store_id)
            ->where('user_id', $userId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $items = [];
        $totalHours = 0.0;

        foreach ($schedules as $schedule) {
            $dateStr = $schedule->date->format('Y-m-d');
            $startDt = Carbon::parse($dateStr.' '.$this->normalizeTime($schedule->start_time));
            $endDateTime = Carbon::parse($dateStr.' '.$this->normalizeTime($schedule->end_time));
            if ($endDateTime->lte($startDt)) {
                $endDateTime->addDay();
            }
            $deadline = $endDateTime->copy()->addHour();
            if ($now->lt($deadline)) {
                continue;
            }

            // Présent dès l’arrivée pointée : pas besoin de clock_out
            $hasClockIn = TimeEntry::where('schedule_id', $schedule->id)
                ->whereNotNull('clock_in')
                ->exists();
            if ($hasClockIn) {
                continue;
            }

            $plannedHours = $this->schedulePlannedHours($schedule);
            // Une absence par créneau (schedule) pour affichage / actions dans la grille
            $absence = Absence::firstOrCreate(
                [
                    'schedule_id' => $schedule->id,
                ],
                [
                    'user_id' => $schedule->user_id,
                    'date' => $dateStr,
                    'store_id' => $schedule->store_id,
                ]
            );
            $items[] = [
                'date' => $dateStr,
                'schedule_id' => $schedule->id,
                'planned_hours' => round($plannedHours, 2),
                'id' => $absence->id,
                'is_justified' => (bool) $absence->is_justified,
            ];
            $totalHours += $plannedHours;
        }

        return response()->json([
            'items' => $items,
            'total_count' => count($items),
            'total_hours' => round($totalHours, 2),
        ]);
    }

    private function normalizeTime($value): string
    {
        if (! $value) {
            return '00:00';
        }
        $str = is_string($value) ? trim($value) : (string) $value;
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $str)) {
            return substr($str, 0, 5);
        }
        try {
            return Carbon::parse($value)->format('H:i');
        } catch (\Throwable $e) {
            return '00:00';
        }
    }

    private function schedulePlannedHours(Schedule $schedule): float
    {
        $start = $this->normalizeTime($schedule->start_time);
        $end = $this->normalizeTime($schedule->end_time);
        $dateStr = $schedule->date->format('Y-m-d');
        $startDt = Carbon::parse($dateStr.' '.$start);
        $endDt = Carbon::parse($dateStr.' '.$end);
        if ($endDt->lte($startDt)) {
            $endDt->addDay();
        }
        $totalMinutes = $startDt->diffInMinutes($endDt);

        $breakMinutes = 0;
        if ($schedule->start_break && $schedule->end_break) {
            $bStart = $this->normalizeTime($schedule->start_break);
            $bEnd = $this->normalizeTime($schedule->end_break);
            $bStartDt = Carbon::parse($dateStr.' '.$bStart);
            $bEndDt = Carbon::parse($dateStr.' '.$bEnd);
            $breakMinutes = abs($bStartDt->diffInMinutes($bEndDt));
        }
        if ($breakMinutes === 0 && $schedule->break_duration !== null && (float) $schedule->break_duration > 0) {
            $b = (float) $schedule->break_duration;
            $breakMinutes = $b < 1 ? (int) round($b * 60) : (int) round($b);
        }

        $workMinutes = max(0, $totalMinutes - $breakMinutes);

        return $workMinutes / 60.0;
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
