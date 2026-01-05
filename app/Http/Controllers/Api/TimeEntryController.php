<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TimeEntryController extends Controller
{
    /**
     * Display a listing of time entries.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = TimeEntry::with(['user', 'schedule', 'breaks']);

        // Filtrer par établissement
        if ($user && $user->store_id) {
            $query->where('store_id', $user->store_id);
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
            $query->where('status', $request->status);
        }

        // Tri par date décroissante
        $query->orderBy('date', 'desc')->orderBy('clock_in', 'desc');

        $timeEntries = $query->get();

        return response()->json($timeEntries);
    }

    /**
     * Pointage d'arrivée (clock in)
     * Seuls admin, chef et directeur peuvent pointer
     */
    public function clockIn(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent pointer
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé. Seuls les administrateurs, chefs et directeurs peuvent pointer.'], 403);
        }

        $validated = $request->validate([
            'location' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $today = Carbon::today();
        
        // Vérifier si un pointage existe déjà pour aujourd'hui
        $existingEntry = TimeEntry::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();

        if ($existingEntry && $existingEntry->clock_in) {
            return response()->json([
                'message' => 'Vous avez déjà pointé votre arrivée aujourd\'hui',
                'time_entry' => $existingEntry->load(['user', 'schedule'])
            ], 400);
        }

        // Vérifier si un pointage en cours existe déjà
        $existingActiveEntry = TimeEntry::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->first();

        if ($existingActiveEntry) {
            return response()->json([
                'message' => 'Vous avez déjà un pointage en cours pour aujourd\'hui. Veuillez pointer votre départ avant de commencer un nouveau pointage.',
                'time_entry' => $existingActiveEntry->load(['user', 'schedule'])
            ], 400);
        }

        // Chercher le planning du jour (le plus récent ou celui qui correspond à l'heure actuelle)
        $schedule = Schedule::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->where('status', '!=', 'cancelled')
            ->orderBy('start_time', 'desc')
            ->first();

        // Créer un NOUVEAU pointage (pas updateOrCreate) pour permettre plusieurs pointages par jour
        $timeEntry = TimeEntry::create([
            'user_id' => $user->id,
            'store_id' => $user->store_id, // Assigner automatiquement le store_id
            'date' => $today,
            'schedule_id' => $schedule?->id,
            'clock_in' => now(),
            'location' => $validated['location'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => 'present',
        ]);

        // Vérifier si l'utilisateur est en retard
        if ($schedule) {
            $scheduledStart = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->start_time);
            $actualStart = Carbon::parse($timeEntry->clock_in);
            
            if ($actualStart->gt($scheduledStart->addMinutes(15))) {
                $timeEntry->status = 'late';
                $timeEntry->save();
            }
        }

        return response()->json([
            'message' => 'Pointage d\'arrivée enregistré',
            'time_entry' => $timeEntry->load(['user', 'schedule'])
        ], 201);
    }

    /**
     * Pointage de départ (clock out)
     * Seuls admin, chef et directeur peuvent pointer
     */
    public function clockOut(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent pointer
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé. Seuls les administrateurs, chefs et directeurs peuvent pointer.'], 403);
        }

        $validated = $request->validate([
            'break_duration' => 'nullable|numeric|min:0', // Maintenant en minutes
            'notes' => 'nullable|string',
        ]);

        $today = Carbon::today();
        
        // Trouver le pointage EN COURS (sans clock_out) pour aujourd'hui
        $timeEntry = TimeEntry::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->orderBy('clock_in', 'desc') // Prendre le plus récent
            ->first();

        if (!$timeEntry) {
            return response()->json([
                'message' => 'Aucun pointage d\'arrivée en cours trouvé pour aujourd\'hui. Veuillez pointer votre arrivée d\'abord.'
            ], 400);
        }

        $clockOutTime = now();
        
        // Vérifier la limite d'heures supplémentaires
        $autoClockOut = $this->checkOvertimeLimit($timeEntry, $clockOutTime);
        
        if ($autoClockOut['auto_clocked']) {
            $clockOutTime = $autoClockOut['clock_out_time'];
            $timeEntry->notes = ($timeEntry->notes ? $timeEntry->notes . "\n" : '') . 
                "Pointage automatique: Limite d'heures supplémentaires atteinte (" . 
                $autoClockOut['overtime_hours'] . "h max autorisées). " . 
                ($validated['notes'] ?? '');
        } else {
            if ($timeEntry->notes) {
                $timeEntry->notes .= "\n" . ($validated['notes'] ?? '');
            } else {
                $timeEntry->notes = $validated['notes'] ?? null;
            }
        }
        
        // VALIDATION CRITIQUE: S'assurer que clock_out est toujours après clock_in
        $clockInTime = Carbon::parse($timeEntry->clock_in);
        if (!$clockOutTime instanceof Carbon) {
            $clockOutTime = Carbon::parse($clockOutTime);
        }
        
        if ($clockOutTime->lte($clockInTime)) {
            \Log::error('Tentative d\'enregistrement d\'un clock_out avant clock_in (clockOut)', [
                'user_id' => $user->id,
                'time_entry_id' => $timeEntry->id,
                'clock_in' => $timeEntry->clock_in,
                'clock_out_proposed' => $clockOutTime->toDateTimeString(),
                'auto_clocked' => $autoClockOut['auto_clocked'] ?? false
            ]);
            
            return response()->json([
                'message' => 'Erreur: L\'heure de départ ne peut pas être avant l\'heure d\'arrivée. Veuillez vérifier les données.',
                'error' => 'clock_out_before_clock_in',
                'clock_in' => $timeEntry->clock_in,
                'clock_out_proposed' => $clockOutTime->toDateTimeString()
            ], 400);
        }
        
        $timeEntry->clock_out = $clockOutTime;
        // break_duration est maintenant en minutes (pas de conversion nécessaire)
        $timeEntry->break_duration = $validated['break_duration'] ?? $timeEntry->break_duration;

        // Calculer les heures travaillées
        $timeEntry->hours_worked = $timeEntry->calculateHoursWorked();
        $timeEntry->save();

        return response()->json([
            'message' => 'Pointage de départ enregistré',
            'time_entry' => $timeEntry->load(['user', 'schedule', 'breaks']),
            'hours_worked' => $timeEntry->hours_worked
        ]);
    }

    /**
     * Get today's time entry for the authenticated user
     */
    public function getTodayEntry(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $today = Carbon::today();
        $timeEntry = TimeEntry::with(['user', 'schedule', 'breaks'])
            ->where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();

        return response()->json($timeEntry ?? null);
    }

    /**
     * Get user's time entries
     */
    public function getUserEntries(Request $request, string $userId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Un utilisateur peut voir ses propres pointages, ou admin/chef/directeur peuvent voir tous
        if ($user->id != $userId && !in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $query = TimeEntry::with(['user', 'schedule', 'breaks'])
            ->where('user_id', $userId);

        // Filtrer par établissement
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        } else {
            // Par défaut, afficher les 30 derniers jours
            $query->where('date', '>=', Carbon::now()->subDays(30));
        }

        $timeEntries = $query->orderBy('date', 'desc')->get();

        return response()->json($timeEntries);
    }

    /**
     * Update a time entry (admin only)
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent modifier des pointages
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $timeEntry = TimeEntry::findOrFail($id);

        // Vérifier que le pointage appartient au même établissement
        if ($user->store_id && $timeEntry->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'clock_in' => 'sometimes|date',
            'clock_out' => 'sometimes|date|after:clock_in',
            'hours_worked' => 'sometimes|numeric|min:0',
            'break_duration' => 'nullable|numeric|min:0',
            'status' => 'sometimes|in:present,absent,late,early_leave',
            'notes' => 'nullable|string',
        ]);

        $timeEntry->update($validated);

        // Recalculer les heures travaillées si nécessaire
        if ($timeEntry->clock_in && $timeEntry->clock_out) {
            $timeEntry->hours_worked = $timeEntry->calculateHoursWorked();
            $timeEntry->save();
        }

        return response()->json($timeEntry->load(['user', 'schedule']));
    }

    /**
     * Créer un pointage manuel pour un employé (admin/chef/director seulement)
     * Utilisé quand un employé a oublié de pointer
     */
    public function createManual(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent créer des pointages manuels
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'date' => 'required|date',
            'clock_in' => 'required|date',
            'clock_out' => 'required|date|after:clock_in',
            'break_duration' => 'nullable|numeric|min:0',
            'status' => 'sometimes|in:present,absent,late,early_leave',
            'notes' => 'nullable|string',
        ]);

        // Parser les dates en timezone locale (Europe/Paris)
        // Si la date est au format 'YYYY-MM-DD HH:mm:ss', la parser comme date locale
        $appTimezone = 'Europe/Paris'; // Timezone de l'application
        
        $clockIn = null;
        $clockOut = null;
        
        // Si les dates sont au format 'YYYY-MM-DD HH:mm:ss' (sans timezone), 
        // les interpréter comme étant dans le timezone de l'application
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $validated['clock_in'])) {
            // Format sans timezone, créer en timezone locale puis convertir en UTC pour le stockage
            $clockIn = Carbon::createFromFormat('Y-m-d H:i:s', $validated['clock_in'], $appTimezone);
        } else {
            // Format ISO avec timezone, parser et convertir en timezone locale
            $clockIn = Carbon::parse($validated['clock_in'])->setTimezone($appTimezone);
        }
        
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $validated['clock_out'])) {
            $clockOut = Carbon::createFromFormat('Y-m-d H:i:s', $validated['clock_out'], $appTimezone);
        } else {
            $clockOut = Carbon::parse($validated['clock_out'])->setTimezone($appTimezone);
        }
        
        // Vérifier que clock_out est après clock_in
        if ($clockOut <= $clockIn) {
            return response()->json([
                'message' => 'L\'heure de départ doit être après l\'heure d\'arrivée'
            ], 400);
        }
        
        // Stocker les dates (Carbon les convertira automatiquement en UTC pour le stockage en DB)
        // Mais on garde la valeur en timezone locale pour l'affichage
        $validated['clock_in'] = $clockIn;
        $validated['clock_out'] = $clockOut;

        // Vérifier que l'employé appartient au même établissement
        $targetUser = \App\Models\User::findOrFail($validated['user_id']);
        if ($user->store_id && $targetUser->store_id !== $user->store_id) {
            return response()->json(['message' => 'L\'employé doit appartenir au même établissement'], 403);
        }

        // Chercher le planning du jour si disponible
        $schedule = Schedule::where('user_id', $validated['user_id'])
            ->whereDate('date', $validated['date'])
            ->where('status', '!=', 'cancelled')
            ->orderBy('start_time', 'desc')
            ->first();

        // Si aucun planning n'existe, créer un planning automatiquement basé sur le pointage manuel
        if (!$schedule) {
            // Extraire les heures de clock_in et clock_out pour créer le planning
            $startTime = $clockIn->format('H:i');
            $endTime = $clockOut->format('H:i');
            
            // Calculer la durée de pause si fournie
            $breakDuration = null;
            if (isset($validated['break_duration']) && $validated['break_duration'] > 0) {
                $breakHours = floor($validated['break_duration']);
                $breakMinutes = round(($validated['break_duration'] - $breakHours) * 60);
                $breakDuration = sprintf('%02d:%02d', $breakHours, $breakMinutes);
            }
            
            // Créer le planning avec le statut "confirmed" pour indiquer qu'il est validé
            $schedule = Schedule::create([
                'user_id' => $validated['user_id'],
                'store_id' => $targetUser->store_id,
                'date' => $validated['date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'break_duration' => $breakDuration,
                'status' => 'confirmed', // Statut confirmé car c'est un pointage réel
                'notes' => 'Planning créé automatiquement à partir d\'un pointage manuel ajouté par ' . $user->name,
                'created_by' => $user->id,
            ]);
        }

        // Créer le pointage manuel
        $timeEntry = TimeEntry::create([
            'user_id' => $validated['user_id'],
            'store_id' => $targetUser->store_id,
            'schedule_id' => $schedule->id,
            'date' => $validated['date'],
            'clock_in' => $validated['clock_in'],
            'clock_out' => $validated['clock_out'],
            'break_duration' => $validated['break_duration'] ?? 0,
            'status' => $validated['status'] ?? 'present',
            'notes' => $validated['notes'] ?? 'Pointage manuel ajouté par ' . $user->name,
        ]);

        // Calculer les heures travaillées
        $timeEntry->hours_worked = $timeEntry->calculateHoursWorked();
        $timeEntry->save();

        return response()->json([
            'message' => 'Pointage manuel créé avec succès',
            'time_entry' => $timeEntry->load(['user', 'schedule'])
        ], 201);
    }

    /**
     * Get statistics for time entries
     * Tous les utilisateurs peuvent voir leurs propres statistiques
     * Admin/chef/directeur peuvent voir les statistiques de tous
     */
    public function getStatistics(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut voir que ses propres statistiques
        $userId = $request->has('user_id') && in_array($user->role, ['admin', 'chef', 'director'])
            ? $request->user_id
            : $user->id;

        $startDate = $request->has('start_date') 
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();
        $endDate = $request->has('end_date')
            ? Carbon::parse($request->end_date)
            : Carbon::now()->endOfMonth();

        $timeEntries = TimeEntry::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->with('schedule');

        // Filtrer par établissement
        if ($user->store_id) {
            $timeEntries->where('store_id', $user->store_id);
        }

        $timeEntries = $timeEntries->get();

        // Ne compter que les heures des plannings validés (confirmed) ou sans planning
        // Les plannings "request" ne sont pas comptabilisés jusqu'à validation
        $validatedEntries = $timeEntries->filter(function ($entry) {
            // Si pas de planning, compter (pointage sans planning)
            if (!$entry->schedule) {
                return true;
            }
            // Si planning avec statut "confirmed", compter
            return $entry->schedule->status === 'confirmed';
        });

        $totalHours = $validatedEntries->sum('hours_worked');
        $totalDays = $validatedEntries->where('status', 'present')->count();
        $lateCount = $validatedEntries->where('status', 'late')->count();
        $absentCount = $validatedEntries->where('status', 'absent')->count();

        return response()->json([
            'user_id' => $userId,
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'statistics' => [
                'total_hours' => round($totalHours, 2),
                'total_days' => $totalDays,
                'late_count' => $lateCount,
                'absent_count' => $absentCount,
                'average_hours_per_day' => $totalDays > 0 ? round($totalHours / $totalDays, 2) : 0,
            ],
            'time_entries' => $timeEntries->load(['user', 'schedule'])
        ]);
    }

    /**
     * Vérifie si l'utilisateur dépasse la limite d'heures supplémentaires
     * et retourne l'heure de sortie automatique si nécessaire
     */
    private function checkOvertimeLimit(TimeEntry $timeEntry, $proposedClockOut): array
    {
        $user = $timeEntry->user;
        $schedule = $timeEntry->schedule;
        
        // Si pas de planning ou pas de limite définie, pas de vérification
        if (!$schedule || !$user->max_overtime_hours) {
            return [
                'auto_clocked' => false,
                'clock_out_time' => $proposedClockOut,
                'overtime_hours' => 0
            ];
        }
        
        // Convertir proposedClockOut en Carbon si ce n'est pas déjà le cas
        if (!$proposedClockOut instanceof Carbon) {
            $proposedClockOut = Carbon::parse($proposedClockOut);
        }
        
        // Calculer les heures planifiées
        $scheduledStart = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->start_time);
        $scheduledEnd = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->end_time);
        
        // Calculer la durée planifiée en heures (sans les pauses)
        $scheduledHours = $scheduledEnd->diffInMinutes($scheduledStart) / 60;
        if ($schedule->start_break && $schedule->end_break) {
            $breakStart = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->start_break);
            $breakEnd = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->end_break);
            $breakHours = $breakEnd->diffInMinutes($breakStart) / 60;
            $scheduledHours -= $breakHours;
        }
        
        // Calculer les heures travaillées jusqu'à maintenant
        $clockIn = Carbon::parse($timeEntry->clock_in);
        $actualHours = $proposedClockOut->diffInMinutes($clockIn) / 60;
        
        // Soustraire les pauses
        if ($timeEntry->break_duration) {
            $actualHours -= $timeEntry->break_duration;
        }
        
        // Calculer les heures supplémentaires
        $overtimeHours = max(0, $actualHours - $scheduledHours);
        
        // Si les heures supplémentaires dépassent la limite
        if ($overtimeHours > $user->max_overtime_hours) {
            // Calculer l'heure de sortie maximale autorisée
            $maxAllowedHours = $scheduledHours + $user->max_overtime_hours;
            
            // Ajouter les pauses
            if ($timeEntry->break_duration) {
                $maxAllowedHours += $timeEntry->break_duration;
            }
            
            // Calculer l'heure de sortie automatique
            $autoClockOutTime = $clockIn->copy()->addHours($maxAllowedHours);
            
            // VALIDATION: S'assurer que l'heure de sortie automatique est après l'heure d'arrivée
            if ($autoClockOutTime->lte($clockIn)) {
                \Log::error('Erreur dans checkOvertimeLimit: autoClockOutTime est avant ou égal à clockIn', [
                    'user_id' => $user->id,
                    'time_entry_id' => $timeEntry->id ?? null,
                    'clock_in' => $clockIn->toDateTimeString(),
                    'auto_clock_out_time' => $autoClockOutTime->toDateTimeString(),
                    'max_allowed_hours' => $maxAllowedHours,
                    'scheduled_hours' => $scheduledHours
                ]);
                // En cas d'erreur, utiliser l'heure actuelle comme fallback
                $autoClockOutTime = now();
            }
            
            return [
                'auto_clocked' => true,
                'clock_out_time' => $autoClockOutTime,
                'overtime_hours' => $user->max_overtime_hours
            ];
        }
        
        return [
            'auto_clocked' => false,
            'clock_out_time' => $proposedClockOut,
            'overtime_hours' => $overtimeHours
        ];
    }

    /**
     * Envoyer un code de vérification par email pour pointer l'arrivée
     */
    public function sendClockInCode(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent envoyer des codes
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $targetUser = \App\Models\User::findOrFail($validated['user_id']);
        
        // Vérifier si un pointage existe déjà pour aujourd'hui AVANT d'envoyer le code
        // Un employé peut pointer plusieurs fois par jour, mais seulement si le pointage précédent est terminé (clock_out)
        $today = Carbon::today();
        $existingEntry = TimeEntry::where('user_id', $targetUser->id)
            ->whereDate('date', $today)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out') // Seulement si le pointage précédent n'est pas terminé
            ->first();

        if ($existingEntry) {
            return response()->json([
                'message' => 'Vous avez déjà un pointage en cours aujourd\'hui. Veuillez pointer votre départ avant de commencer un nouveau pointage.',
                'time_entry' => $existingEntry->load(['user', 'schedule']),
                'already_clocked_in' => true
            ], 400);
        }

        // Vérifier si un code est déjà actif et non expiré
        $targetUser->refresh();
        $isReused = false;
        $expiresAt = null;
        
        if ($targetUser->clock_in_code && $targetUser->clock_in_code_expires_at) {
            $expiresAt = $targetUser->clock_in_code_expires_at instanceof \Carbon\Carbon 
                ? $targetUser->clock_in_code_expires_at 
                : Carbon::parse($targetUser->clock_in_code_expires_at);
            
            if (Carbon::now()->lt($expiresAt)) {
                // Un code valide existe déjà, le renvoyer
                $code = $targetUser->clock_in_code;
                $isReused = true;
                \Log::info('Code existant réutilisé', [
                    'user_id' => $targetUser->id,
                    'code' => $code,
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                ]);
            } else {
                // Le code est expiré, générer un nouveau code
                $code = str_pad((string)rand(100, 999), 3, '0', STR_PAD_LEFT);
                if (strlen($code) !== 3) {
                    $code = str_pad($code, 3, '0', STR_PAD_LEFT);
                }
                $targetUser->clock_in_code = $code;
                $targetUser->clock_in_code_expires_at = Carbon::now()->addMinutes(15);
                $targetUser->save();
                $expiresAt = $targetUser->clock_in_code_expires_at;
            }
        } else {
            // Aucun code existant, générer un nouveau code
            $code = str_pad((string)rand(100, 999), 3, '0', STR_PAD_LEFT);
            
            // S'assurer que le code est bien une string de 3 caractères
            $code = (string)$code;
            if (strlen($code) !== 3) {
                $code = str_pad($code, 3, '0', STR_PAD_LEFT);
            }

            // Sauvegarder le code dans la base de données
            $targetUser->clock_in_code = $code;
            $targetUser->clock_in_code_expires_at = Carbon::now()->addMinutes(15); // Code valide 15 minutes
            $targetUser->save();
            $expiresAt = $targetUser->clock_in_code_expires_at;
        }
        
        // Recharger pour s'assurer d'avoir les dernières données
        $targetUser->refresh();
        
        // Vérifier que le code a bien été sauvegardé
        \Log::info('Code généré et sauvegardé', [
            'user_id' => $targetUser->id,
            'code_generated' => $code,
            'code_stored' => $targetUser->clock_in_code,
            'expires_at' => $targetUser->clock_in_code_expires_at,
            'is_reused' => $isReused,
        ]);

        // Envoyer le code par email (même si c'est un code réutilisé)
        try {
            \Illuminate\Support\Facades\Mail::raw(
                "Bonjour {$targetUser->name},\n\n" .
                "Votre code de vérification pour pointer votre arrivée est : {$code}\n\n" .
                "Ce code est valide pendant 15 minutes.\n\n" .
                "Cordialement,\nL'équipe",
                function ($message) use ($targetUser) {
                    $message->to($targetUser->email)
                        ->subject('Code de vérification - Pointage');
                }
            );
        } catch (\Exception $e) {
            \Log::error('Erreur envoi email code pointage: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de l\'envoi de l\'email. Le code a été généré mais l\'email n\'a pas pu être envoyé.',
                'code' => $code, // En cas d'erreur, retourner le code directement (pour le développement)
            ], 500);
        }

        return response()->json([
            'message' => 'Code de vérification envoyé par email',
            'user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
            ],
        ]);
    }

    /**
     * Pointer l'arrivée avec vérification du code
     */
    public function clockInWithCode(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent pointer
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'code' => 'required|string|size:3',
            'location' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $targetUser = \App\Models\User::findOrFail($validated['user_id']);
        
        // Recharger l'utilisateur depuis la base de données pour s'assurer d'avoir les dernières données
        $targetUser->refresh();

        // Normaliser les codes (enlever les espaces, convertir en string)
        $storedCode = trim((string)$targetUser->clock_in_code);
        $providedCode = trim((string)$validated['code']);

        // Log pour déboguer
        \Log::info('Vérification code pointage', [
            'user_id' => $targetUser->id,
            'stored_code' => $storedCode,
            'provided_code' => $providedCode,
            'codes_match' => $storedCode === $providedCode,
            'stored_code_length' => strlen($storedCode),
            'provided_code_length' => strlen($providedCode),
        ]);

        // Vérifier le code
        if (!$storedCode || $storedCode !== $providedCode) {
            return response()->json([
                'message' => 'Code de vérification incorrect',
                'debug' => [
                    'stored_code_length' => strlen($storedCode),
                    'provided_code_length' => strlen($providedCode),
                ]
            ], 400);
        }

        // Vérifier l'expiration
        if (!$targetUser->clock_in_code_expires_at) {
            \Log::warning('Code expiré: pas de date d\'expiration', [
                'user_id' => $targetUser->id,
            ]);
            return response()->json([
                'message' => 'Aucun code de vérification trouvé. Veuillez demander un nouveau code.'
            ], 400);
        }
        
        // Convertir en Carbon si ce n'est pas déjà fait
        $expiresAt = $targetUser->clock_in_code_expires_at instanceof \Carbon\Carbon 
            ? $targetUser->clock_in_code_expires_at 
            : Carbon::parse($targetUser->clock_in_code_expires_at);
        
        $now = Carbon::now();
        \Log::info('Vérification expiration code', [
            'user_id' => $targetUser->id,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'now' => $now->format('Y-m-d H:i:s'),
            'is_expired' => $now->gt($expiresAt),
        ]);
        
        if ($now->gt($expiresAt)) {
            return response()->json([
                'message' => 'Code de vérification expiré. Veuillez demander un nouveau code.'
            ], 400);
        }

        $today = Carbon::today();
        
        // Vérifier si un pointage existe déjà pour aujourd'hui et n'est pas terminé
        // Un employé peut pointer plusieurs fois par jour, mais seulement si le pointage précédent est terminé (clock_out)
        $existingEntry = TimeEntry::where('user_id', $targetUser->id)
            ->whereDate('date', $today)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out') // Seulement si le pointage précédent n'est pas terminé
            ->first();

        \Log::info('Vérification pointage existant', [
            'user_id' => $targetUser->id,
            'existing_entry' => $existingEntry ? $existingEntry->id : null,
            'has_clock_in' => $existingEntry && $existingEntry->clock_in ? true : false,
            'has_clock_out' => $existingEntry && $existingEntry->clock_out ? true : false,
        ]);

        if ($existingEntry) {
            // Effacer le code utilisé
            $targetUser->clock_in_code = null;
            $targetUser->clock_in_code_expires_at = null;
            $targetUser->save();

            return response()->json([
                'message' => 'Vous avez déjà un pointage en cours aujourd\'hui. Veuillez pointer votre départ avant de commencer un nouveau pointage.',
                'time_entry' => $existingEntry->load(['user', 'schedule'])
            ], 400);
        }
        
        \Log::info('Code validé, création du pointage', [
            'user_id' => $targetUser->id,
        ]);

        // Chercher le planning du jour qui correspond le mieux à l'heure actuelle
        // Priorité : trouver le planning non complété dont l'heure de début est la plus proche (avant ou après) de l'heure de pointage
        $clockInTime = now();
        $schedules = Schedule::where('user_id', $targetUser->id)
            ->whereDate('date', $today)
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'request') // Ne pas utiliser les plannings "request"
            ->orderBy('start_time', 'asc')
            ->get();
        
        $schedule = null;
        $bestSchedule = null;
        $minDiff = PHP_INT_MAX;
        
        foreach ($schedules as $s) {
            // Vérifier si ce planning a déjà un pointage complété
            $hasCompletedEntry = TimeEntry::where('user_id', $targetUser->id)
                ->whereDate('date', $today)
                ->where('schedule_id', $s->id)
                ->whereNotNull('clock_in')
                ->whereNotNull('clock_out')
                ->exists();
            
            // Ne considérer que les plannings non complétés
            if (!$hasCompletedEntry) {
                // Calculer la différence entre l'heure de début du planning et l'heure de pointage
                $scheduleStart = Carbon::parse($s->date->format('Y-m-d') . ' ' . $s->start_time);
                $diff = abs($clockInTime->diffInMinutes($scheduleStart));
                
                // Si le planning commence avant ou à l'heure de pointage, le considérer
                // Sinon, ne le considérer que si c'est le meilleur choix (le plus proche)
                if ($scheduleStart <= $clockInTime || $diff < $minDiff) {
                    if ($diff < $minDiff) {
                        $minDiff = $diff;
                        $bestSchedule = $s;
                    }
                }
            }
        }
        
        $schedule = $bestSchedule;

        // Vérifier si l'employé a déjà pointé et pointé son départ aujourd'hui
        // Si oui, et qu'il veut pointer à nouveau, c'est une nouvelle période de travail
        // qui doit créer un planning "request" si elle n'est pas programmée
        $hasCompletedEntryToday = TimeEntry::where('user_id', $targetUser->id)
            ->whereDate('date', $today)
            ->whereNotNull('clock_in')
            ->whereNotNull('clock_out')
            ->exists();

        $clockInTime = now();
        $scheduleId = null;

        // Si un planning existe (confirmé ou planifié), TOUJOURS l'utiliser
        // même si l'heure de pointage est en dehors du planning
        // Le planning sera mis à jour avec les heures réelles au départ
        if ($schedule) {
            // Si l'employé a déjà complété un pointage aujourd'hui, vérifier si c'est le même planning
            if ($hasCompletedEntryToday) {
                // Vérifier si le planning existe déjà pour un pointage complété
                $completedEntry = TimeEntry::where('user_id', $targetUser->id)
                    ->whereDate('date', $today)
                    ->whereNotNull('clock_in')
                    ->whereNotNull('clock_out')
                    ->where('schedule_id', $schedule->id)
                    ->first();
                
                // Si ce planning a déjà été utilisé pour un pointage complété, ne pas le réutiliser
                // (cela créera une demande pour le nouveau pointage)
                if ($completedEntry) {
                    $scheduleId = null;
                } else {
                    // Sinon, utiliser ce planning
                    $scheduleId = $schedule->id;
                }
            } else {
                // Premier pointage de la journée, utiliser le planning s'il existe
                $scheduleId = $schedule->id;
            }
        }

        // Si pas de planning, créer un planning "request" immédiatement
        if (!$scheduleId) {
            // Vérifier s'il existe déjà un planning "request" pour aujourd'hui
            $existingRequestSchedule = Schedule::where('user_id', $targetUser->id)
                ->whereDate('date', $today)
                ->where('status', 'request')
                ->first();
            
            if (!$existingRequestSchedule) {
                // Créer un planning "request" avec l'heure d'arrivée
                // L'heure de fin sera mise à jour au départ
                $startTime = $clockInTime->format('H:i:s');
                // Utiliser une heure de fin par défaut (4 heures plus tard)
                $defaultEndTime = $clockInTime->copy()->addHours(4)->format('H:i:s');
                
                $requestSchedule = Schedule::create([
                    'user_id' => $targetUser->id,
                    'store_id' => $targetUser->store_id, // Assigner automatiquement le store_id
                    'date' => $today,
                    'start_time' => $startTime,
                    'end_time' => $defaultEndTime,
                    'status' => 'request',
                    'notes' => 'Planning créé automatiquement suite au pointage d\'arrivée (en attente de validation)',
                    'created_by' => $request->user()?->id,
                ]);
                
                $scheduleId = $requestSchedule->id;
            } else {
                // Utiliser le planning "request" existant
                $scheduleId = $existingRequestSchedule->id;
            }
        }

        // Créer un NOUVEAU pointage (pas updateOrCreate) pour permettre plusieurs pointages par jour
        $timeEntry = TimeEntry::create([
            'user_id' => $targetUser->id,
            'store_id' => $targetUser->store_id, // Assigner automatiquement le store_id
            'date' => $today,
            'schedule_id' => $scheduleId,
            'clock_in' => $clockInTime,
            'location' => $validated['location'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => 'present',
        ]);

        // Vérifier si l'utilisateur est en retard
        if ($schedule) {
            $scheduledStart = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->start_time);
            $actualStart = Carbon::parse($timeEntry->clock_in);
            
            if ($actualStart->gt($scheduledStart->addMinutes(15))) {
                $timeEntry->status = 'late';
                $timeEntry->save();
            }
        }

        // Effacer le code utilisé
        $targetUser->clock_in_code = null;
        $targetUser->clock_in_code_expires_at = null;
        $targetUser->save();

        return response()->json([
            'message' => 'Pointage d\'arrivée enregistré',
            'time_entry' => $timeEntry->load(['user', 'schedule'])
        ], 201);
    }

    /**
     * Pointer le départ pour un utilisateur spécifique (admin/chef/director)
     */
    public function clockOutForUser(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent pointer
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'break_duration' => 'nullable|numeric|min:0', // Maintenant en minutes
            'notes' => 'nullable|string',
        ]);

        $targetUser = \App\Models\User::findOrFail($validated['user_id']);
        $today = Carbon::today();
        
        // Trouver le pointage EN COURS (sans clock_out) pour aujourd'hui
        $timeEntry = TimeEntry::where('user_id', $targetUser->id)
            ->whereDate('date', $today)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->orderBy('clock_in', 'desc') // Prendre le plus récent
            ->first();

        if (!$timeEntry) {
            return response()->json([
                'message' => 'Aucun pointage d\'arrivée en cours trouvé pour aujourd\'hui. Veuillez pointer votre arrivée d\'abord.'
            ], 400);
        }

        $clockOutTime = now();
        $clockInTime = Carbon::parse($timeEntry->clock_in);
        
        // Vérifier si un planning existe pour ce timeEntry
        $existingSchedule = $timeEntry->schedule;
        
        if ($existingSchedule) {
            // Si un planning existe, NE PAS le modifier
            // Juste stocker les heures programmées originales dans les notes du timeEntry
            // pour pouvoir calculer la différence plus tard
            $originalStartTime = $existingSchedule->start_time;
            $originalEndTime = $existingSchedule->end_time;
            
            // Stocker les heures originales dans les notes du timeEntry pour référence
            if ($timeEntry->notes) {
                $timeEntry->notes .= "\n[Heures programmées originales: {$originalStartTime} - {$originalEndTime}]";
            } else {
                $timeEntry->notes = "[Heures programmées originales: {$originalStartTime} - {$originalEndTime}]";
            }
            
            // Le planning reste lié au timeEntry, mais on ne le modifie PAS
            // Les heures réelles sont dans le timeEntry (clock_in, clock_out)
        } else {
            // Pas de planning du tout, créer une demande UNIQUEMENT si vraiment pas de planning
            // Vérifier d'abord s'il existe un planning pour aujourd'hui (même non lié)
            $scheduleForToday = Schedule::where('user_id', $targetUser->id)
                ->whereDate('date', $today)
                ->where('status', '!=', 'cancelled')
                ->where('status', '!=', 'request') // Ne pas compter les demandes
                ->first();
            
            if ($scheduleForToday) {
                // Un planning existe mais n'était pas lié au timeEntry
                // Le lier SANS le modifier
                $timeEntry->schedule_id = $scheduleForToday->id;
                
                // Stocker les heures originales dans les notes
                $originalStartTime = $scheduleForToday->start_time;
                $originalEndTime = $scheduleForToday->end_time;
                
                if ($timeEntry->notes) {
                    $timeEntry->notes .= "\n[Heures programmées originales: {$originalStartTime} - {$originalEndTime}]";
                } else {
                    $timeEntry->notes = "[Heures programmées originales: {$originalStartTime} - {$originalEndTime}]";
                }
            } else {
                // Vraiment pas de planning, vérifier s'il existe déjà un planning "request" créé au clockIn
                // Chercher un planning "request" qui pourrait être lié à ce timeEntry
                $existingRequestSchedule = Schedule::where('user_id', $targetUser->id)
                    ->whereDate('date', $today)
                    ->where('status', 'request')
                    ->first();
                
                if ($existingRequestSchedule && !$timeEntry->schedule_id) {
                    // Si un planning "request" existe et que le timeEntry n'a pas de planning, le lier
                    // Mettre à jour le planning "request" avec l'heure de fin réelle
                    $existingRequestSchedule->end_time = $clockOutTime->format('H:i:s');
                    $existingRequestSchedule->notes = 'Planning créé automatiquement suite au pointage (en attente de validation) - Heures réelles: ' . 
                        $clockInTime->format('H:i') . ' - ' . $clockOutTime->format('H:i');
                    $existingRequestSchedule->save();
                    
                    $timeEntry->schedule_id = $existingRequestSchedule->id;
                } else if (!$existingRequestSchedule) {
                    // Créer une nouvelle demande (cas où le planning n'a pas été créé au clockIn)
                    $startTime = $clockInTime->format('H:i:s');
                    $endTime = $clockOutTime->format('H:i:s');
                    
                    $requestSchedule = Schedule::create([
                        'user_id' => $targetUser->id,
                        'store_id' => $targetUser->store_id, // Assigner automatiquement le store_id
                        'date' => $today,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'status' => 'request',
                        'notes' => 'Planning créé automatiquement suite au pointage (en attente de validation)',
                        'created_by' => $request->user()?->id,
                    ]);
                    
                    $timeEntry->schedule_id = $requestSchedule->id;
                }
            }
        }
        
        // Vérifier la limite d'heures supplémentaires
        $autoClockOut = $this->checkOvertimeLimit($timeEntry, $clockOutTime);
        
        if ($autoClockOut['auto_clocked']) {
            $clockOutTime = $autoClockOut['clock_out_time'];
            $timeEntry->notes = ($timeEntry->notes ? $timeEntry->notes . "\n" : '') . 
                "Pointage automatique: Limite d'heures supplémentaires atteinte (" . 
                $autoClockOut['overtime_hours'] . "h max autorisées). " . 
                ($validated['notes'] ?? '');
        } else {
            if ($timeEntry->notes) {
                $timeEntry->notes .= "\n" . ($validated['notes'] ?? '');
            } else {
                $timeEntry->notes = $validated['notes'] ?? null;
            }
        }
        
        $timeEntry->clock_out = $clockOutTime;
        // break_duration est maintenant en minutes (pas de conversion nécessaire)
        $timeEntry->break_duration = $validated['break_duration'] ?? $timeEntry->break_duration;

        // Calculer les heures travaillées
        $timeEntry->hours_worked = $timeEntry->calculateHoursWorked();
        $timeEntry->save();

        // Recharger le timeEntry avec toutes les relations pour s'assurer que le schedule est à jour
        $timeEntry->refresh();
        $timeEntry->load(['user', 'schedule', 'breaks']);

        return response()->json([
            'message' => 'Pointage de départ enregistré',
            'time_entry' => $timeEntry,
            'hours_worked' => $timeEntry->hours_worked,
        ]);
    }
}
