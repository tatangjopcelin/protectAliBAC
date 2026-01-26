<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Notifications\SchedulePublishedNotification;
use Carbon\Carbon;

class ScheduleController extends Controller
{
    /**
     * Formate la date d'un schedule pour éviter les problèmes de fuseau horaire
     */
    private function formatScheduleDate($schedule)
    {
        if ($schedule->date) {
            $dateValue = $schedule->getAttributes()['date'] ?? $schedule->date;
            if ($dateValue instanceof \Carbon\Carbon) {
                $schedule->date = $dateValue->format('Y-m-d');
            } elseif (is_string($dateValue)) {
                // Si c'est une chaîne ISO, extraire juste la date
                if (strpos($dateValue, 'T') !== false) {
                    $schedule->date = substr($dateValue, 0, 10);
                } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
                    // Si ce n'est pas déjà au format yyyy-MM-dd, essayer de parser
                    try {
                        $date = Carbon::parse($dateValue);
                        $schedule->date = $date->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Garder la valeur originale si le parsing échoue
                    }
                }
            }
        }
        return $schedule;
    }
    /**
     * Display a listing of schedules.
     * Tous les utilisateurs peuvent voir leur propre planning
     * Admin/chef/directeur peuvent voir tous les plannings
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = Schedule::with(['user', 'creator']);

        // Filtrer par établissement
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        }

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut voir que son propre planning
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            $query->where('user_id', $user->id);
        }

        // Filtres
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }
        if ($request->has('status')) {
            // Gérer plusieurs statuts séparés par des virgules
            $statuses = explode(',', $request->status);
            if (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            } else {
                $query->where('status', $request->status);
            }
        } else {
            // Par défaut, exclure les plannings "cancelled" (refusés)
            // Sauf si l'utilisateur demande explicitement de les voir
            $query->where('status', '!=', 'cancelled');
        }

        // Tri par date
        $query->orderBy('date', 'asc')->orderBy('start_time', 'asc');

        $schedules = $query->get();
        
        // Formater les dates pour éviter les problèmes de fuseau horaire
        $schedules->transform(function ($schedule) {
            return $this->formatScheduleDate($schedule);
        });

        return response()->json($schedules);
    }

    /**
     * Store a newly created schedule.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent créer des plannings
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'break_duration' => 'nullable|date_format:H:i',
            'start_break' => 'nullable|date_format:H:i',
            'end_break' => 'nullable|date_format:H:i|after:start_break',
            'status' => 'nullable|in:planned,confirmed,cancelled',
            'notes' => 'nullable|string',
        ]);

        // Calculer break_duration si start_break et end_break sont fournis
        $breakDuration = null;
        if (!empty($validated['start_break']) && !empty($validated['end_break'])) {
            // Parser les heures au format H:i (ex: "12:00")
            $startBreak = Carbon::createFromFormat('H:i', $validated['start_break']);
            $endBreak = Carbon::createFromFormat('H:i', $validated['end_break']);
            
            // Si end_break est avant start_break, on suppose que c'est le lendemain
            if ($endBreak->lt($startBreak)) {
                $endBreak->addDay();
            }
            
            $breakDurationMinutes = $startBreak->diffInMinutes($endBreak);
            // Convertir en format H:i
            $hours = floor($breakDurationMinutes / 60);
            $minutes = $breakDurationMinutes % 60;
            $breakDuration = sprintf('%02d:%02d', $hours, $minutes);
        } elseif (!empty($validated['break_duration'])) {
            $breakDuration = $validated['break_duration'];
        }

        // Vérifier que l'utilisateur cible appartient au même établissement
        $targetUser = User::findOrFail($validated['user_id']);
        if ($user->store_id && $targetUser->store_id !== $user->store_id) {
            return response()->json(['message' => 'L\'utilisateur n\'appartient pas à votre établissement'], 403);
        }

        // Vérifier si l'employé est en congé approuvé ce jour-là
        $scheduleDate = \Carbon\Carbon::parse($validated['date'])->format('Y-m-d');
        $leave = \App\Models\Leave::where('user_id', $validated['user_id'])
            ->where('status', 'approved')
            ->where(function ($query) use ($scheduleDate) {
                $query->whereJsonContains('dates', $scheduleDate)
                    ->orWhere(function ($q) use ($scheduleDate) {
                        $q->whereDate('start_date', '<=', $scheduleDate)
                          ->whereDate('end_date', '>=', $scheduleDate);
                    });
            })
            ->first();

        if ($leave) {
            $leaveType = $leave->is_paid ? 'payé' : 'non soldé';
            return response()->json([
                'message' => "Cet employé est en congé {$leaveType} le {$scheduleDate}. Impossible de créer un planning."
            ], 422);
        }

        $schedule = Schedule::create([
            'user_id' => $validated['user_id'],
            'store_id' => $user->store_id, // Assigner automatiquement le store_id de l'admin
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'break_duration' => $breakDuration,
            'start_break' => $validated['start_break'] ?? null,
            'end_break' => $validated['end_break'] ?? null,
            'status' => $validated['status'] ?? 'planned',
            'notes' => $validated['notes'] ?? null,
            'created_by' => $user->id,
        ]);
        
        \Log::info('Planning créé:', [
            'id' => $schedule->id,
            'start_break' => $schedule->start_break,
            'end_break' => $schedule->end_break,
            'break_duration' => $schedule->break_duration
        ]);

        $schedule = $schedule->load(['user', 'creator']);
        $this->formatScheduleDate($schedule);
        return response()->json($schedule, 201);
    }

    /**
     * Display the specified schedule.
     * Tous les utilisateurs peuvent voir leur propre planning
     * Admin/chef/directeur peuvent voir tous les plannings
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $schedule = Schedule::with(['user', 'creator', 'timeEntry'])->findOrFail($id);

        // Vérifier que le planning appartient au même établissement
        if ($user->store_id && $schedule->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut voir que son propre planning
        if (!in_array($user->role, ['admin', 'chef', 'director']) && $schedule->user_id !== $user->id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $this->formatScheduleDate($schedule);
        return response()->json($schedule);
    }

    /**
     * Update the specified schedule.
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $schedule = Schedule::findOrFail($id);

        // Vérifier que le planning appartient au même établissement
        if ($user->store_id && $schedule->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut modifier que son propre planning
        // et avec des restrictions (pas de modification de user_id, date, status)
        $isAdmin = in_array($user->role, ['admin', 'chef', 'director']);
        if (!$isAdmin && $schedule->user_id !== $user->id) {
            return response()->json(['message' => 'Vous ne pouvez modifier que votre propre planning'], 403);
        }

        // Les employés ne peuvent modifier que les heures et les pauses, pas user_id, date ou status
        $validationRules = [
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'break_duration' => 'nullable|date_format:H:i',
            'start_break' => 'nullable|date_format:H:i',
            'end_break' => 'nullable|date_format:H:i|after:start_break',
            'notes' => 'nullable|string',
        ];

        // Seuls les admins peuvent modifier user_id, date et status
        if ($isAdmin) {
            $validationRules['user_id'] = 'sometimes|exists:users,id';
            $validationRules['date'] = 'sometimes|date';
            $validationRules['status'] = 'sometimes|in:planned,confirmed,cancelled';
        }

        $validated = $request->validate($validationRules);

        // Si l'employé essaie de modifier user_id, date ou status, rejeter
        if (!$isAdmin) {
            if (isset($validated['user_id']) || isset($validated['date']) || isset($validated['status'])) {
                return response()->json(['message' => 'Vous ne pouvez modifier que les heures et les pauses'], 403);
            }
        }

        // Calculer break_duration si start_break et end_break sont fournis
        if (!empty($validated['start_break']) && !empty($validated['end_break'])) {
            $startBreak = Carbon::parse($validated['start_break']);
            $endBreak = Carbon::parse($validated['end_break']);
            $breakMinutes = $startBreak->diffInMinutes($endBreak);
            // Convertir en format H:i
            $hours = floor($breakMinutes / 60);
            $minutes = $breakMinutes % 60;
            $validated['break_duration'] = sprintf('%02d:%02d', $hours, $minutes);
        }

        // Si la date est modifiée, vérifier si l'employé est en congé ce jour-là
        $targetUserId = $validated['user_id'] ?? $schedule->user_id;
        if (isset($validated['date'])) {
            // Comparer les dates normalisées (sans l'heure)
            $newDate = Carbon::parse($validated['date'])->format('Y-m-d');
            $currentDate = Carbon::parse($schedule->date)->format('Y-m-d');
            
            if ($newDate !== $currentDate) {
                $scheduleDate = $newDate;
                $leave = \App\Models\Leave::where('user_id', $targetUserId)
                    ->where('status', 'approved')
                    ->where(function ($query) use ($scheduleDate) {
                        $query->whereJsonContains('dates', $scheduleDate)
                            ->orWhere(function ($q) use ($scheduleDate) {
                                $q->whereDate('start_date', '<=', $scheduleDate)
                                  ->whereDate('end_date', '>=', $scheduleDate);
                            });
                    })
                    ->first();

                if ($leave) {
                    $leaveType = $leave->is_paid ? 'payé' : 'non soldé';
                    return response()->json([
                        'message' => "Cet employé est en congé {$leaveType} le {$scheduleDate}. Impossible de modifier le planning."
                    ], 422);
                }
            }
        }

        $updateData = $validated;
        if (isset($validated['start_break'])) {
            $updateData['start_break'] = $validated['start_break'];
        }
        if (isset($validated['end_break'])) {
            $updateData['end_break'] = $validated['end_break'];
        }
        $schedule->update($updateData);

        $schedule = $schedule->load(['user', 'creator']);
        $this->formatScheduleDate($schedule);
        return response()->json($schedule);
    }

    /**
     * Remove the specified schedule.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent supprimer des plannings
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $schedule = Schedule::findOrFail($id);

        // Vérifier que le planning appartient au même établissement
        if ($user->store_id && $schedule->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $schedule->delete();

        return response()->json(['message' => 'Planning supprimé'], 200);
    }

    /**
     * Get weekly schedule for a user or all users
     * Tous les utilisateurs peuvent voir leur propre planning
     * Admin/chef/directeur peuvent voir tous les plannings
     */
    public function getWeeklySchedule(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $weekStart = $request->has('week_start') 
            ? Carbon::parse($request->week_start)->startOfWeek()
            : Carbon::now()->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();

        $query = Schedule::with(['user', 'creator'])
            ->whereBetween('date', [$weekStart, $weekEnd]);

        // Filtrer par établissement
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        }

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut voir que son propre planning
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            $query->where('user_id', $user->id);
        } elseif ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $schedules = $query->orderBy('date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'schedules' => $schedules
        ]);
    }

    /**
     * Get monthly schedule for a user or all users
     * Tous les utilisateurs peuvent voir leur propre planning
     * Admin/chef/directeur peuvent voir tous les plannings
     */
    public function getMonthlySchedule(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $month = $request->has('month') 
            ? Carbon::parse($request->month)->startOfMonth()
            : Carbon::now()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $query = Schedule::with(['user', 'creator'])
            ->whereBetween('date', [$month, $monthEnd]);

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut voir que son propre planning
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            $query->where('user_id', $user->id);
        } elseif ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $schedules = $query->orderBy('date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'month' => $month->format('Y-m'),
            'schedules' => $schedules
        ]);
    }

    /**
     * Valider un planning "request" (demande)
     * Seuls admin, chef et directeur peuvent valider
     */
    public function validateRequest(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent valider
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $schedule = Schedule::findOrFail($id);

        // Vérifier que c'est bien un planning "request"
        if ($schedule->status !== 'request') {
            return response()->json([
                'message' => 'Ce planning n\'est pas une demande en attente de validation'
            ], 400);
        }

        // Changer le statut en "confirmed"
        $schedule->status = 'confirmed';
        $schedule->save();

        return response()->json([
            'message' => 'Planning validé avec succès. Les heures seront maintenant comptabilisées.',
            'schedule' => $schedule->load(['user', 'creator'])
        ]);
    }

    /**
     * Publier et envoyer le planning par email à tous les employés
     * Seuls admin, chef et directeur peuvent publier
     */
    public function publishSchedule(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent publier
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $weekStart = Carbon::parse($validated['start_date'])->startOfDay();
        $weekEnd = Carbon::parse($validated['end_date'])->endOfDay();

        // Récupérer tous les plannings de la semaine (sauf cancelled)
        $query = Schedule::with(['user', 'creator'])
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->where('status', '!=', 'cancelled');

        // Filtrer par établissement
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        }

        $schedules = $query->orderBy('date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        // Formater les dates pour éviter les problèmes de fuseau horaire
        $schedules->transform(function ($schedule) {
            return $this->formatScheduleDate($schedule);
        });

        // Grouper les plannings par employé
        $schedulesByUser = [];
        foreach ($schedules as $schedule) {
            $userId = $schedule->user_id;
            if (!isset($schedulesByUser[$userId])) {
                $schedulesByUser[$userId] = [];
            }
            $schedulesByUser[$userId][] = $schedule;
        }

        // Envoyer un email à chaque employé avec son planning
        $sentCount = 0;
        $errors = [];

        foreach ($schedulesByUser as $userId => $userSchedules) {
            try {
                $employee = User::find($userId);
                if (!$employee || !$employee->email) {
                    $errors[] = "Employé ID {$userId} : email non trouvé";
                    continue;
                }

                // Envoyer la notification par email
                $employee->notify(new SchedulePublishedNotification(
                    $userSchedules,
                    $weekStart->format('Y-m-d'),
                    $weekEnd->format('Y-m-d'),
                    $user
                ));

                $sentCount++;
            } catch (\Exception $e) {
                Log::error("Erreur envoi email planning à l'employé {$userId}: " . $e->getMessage());
                $errors[] = "Employé ID {$userId} : " . $e->getMessage();
            }
        }

        $response = [
            'message' => "Planning publié avec succès. {$sentCount} email(s) envoyé(s).",
            'sent_count' => $sentCount,
            'total_employees' => count($schedulesByUser),
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
            $response['message'] .= " " . count($errors) . " erreur(s) lors de l'envoi.";
        }

        return response()->json($response, 200);
    }
}
