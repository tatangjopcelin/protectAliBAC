<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollReportToken;
use App\Models\User;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PayrollReportController extends Controller
{
    /**
     * Distribuer les rapports de paie pour un mois donné
     */
    public function distribute(Request $request)
    {
        $user = $request->user();
        
        // Vérifier que l'utilisateur est admin ou directeur
        if (!in_array($user->role, ['admin', 'director'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $request->validate([
            'month' => 'required|date_format:Y-m'
        ]);

        $month = $request->month;
        $monthStart = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $monthEnd = Carbon::createFromFormat('Y-m', $month)->endOfMonth();

        // Récupérer tous les employés du même établissement (sauf admin)
        $employeesQuery = User::where('role', '!=', 'admin');
        if ($user->store_id) {
            $employeesQuery->where('store_id', $user->store_id);
        }
        $employees = $employeesQuery->get();

        $sentCount = 0;
        $errors = [];

        foreach ($employees as $employee) {
            try {
                // Vérifier si un token existe déjà pour ce mois et cet employé
                $existingToken = PayrollReportToken::where('user_id', $employee->id)
                    ->where('month', $month)
                    ->first();

                // Si le token existe et est déjà confirmé, on skip cet employé
                if ($existingToken && $existingToken->status === 'confirmed') {
                    continue; // Ne pas envoyer d'email aux employés qui ont déjà confirmé
                }

                if ($existingToken) {
                    // Le token existe mais est "pending" ou "rejected", on le réinitialise
                    $existingToken->update([
                        'status' => 'pending',
                        'rejection_reason' => null,
                        'responded_at' => null,
                        'viewed_at' => null,
                        'sent_at' => now()
                    ]);
                    $token = $existingToken->fresh(); // Recharger le modèle pour avoir les données à jour
                } else {
                    // Créer un nouveau token
                    $token = PayrollReportToken::create([
                        'user_id' => $employee->id,
                        'store_id' => $employee->store_id, // Assigner automatiquement le store_id
                        'token' => PayrollReportToken::generateToken(),
                        'month' => $month,
                        'status' => 'pending',
                        'sent_at' => now()
                    ]);
                }

                // Envoyer l'email avec le lien (URL frontend)
                $frontendUrl = env('FRONTEND_URL', 'http://localhost:8101');
                $link = "{$frontendUrl}/payroll-report/{$token->token}";
                
                Mail::raw(
                    "Bonjour {$employee->name},\n\n" .
                    "Votre rapport de paie pour le mois de {$monthStart->locale('fr')->monthName} {$monthStart->year} est disponible.\n\n" .
                    "Veuillez consulter et confirmer vos heures de travail en cliquant sur le lien suivant :\n" .
                    $link . "\n\n" .
                    "Cordialement,\n" .
                    "L'équipe Table du Boucher",
                    function ($message) use ($employee, $monthStart) {
                        $message->to($employee->email)
                            ->subject("Rapport de paie - {$monthStart->locale('fr')->monthName} {$monthStart->year}");
                    }
                );

                // sent_at est déjà mis à jour dans la condition ci-dessus
                $sentCount++;

            } catch (\Exception $e) {
                Log::error("Erreur envoi rapport paie à {$employee->email}: " . $e->getMessage());
                $errors[] = $employee->email;
            }
        }

        return response()->json([
            'message' => "Rapports distribués avec succès",
            'sent_count' => $sentCount,
            'errors' => $errors
        ]);
    }

    /**
     * Récupérer les données du rapport via token
     */
    public function getByToken($token)
    {
        $reportToken = PayrollReportToken::where('token', $token)
            ->with('user')
            ->first();

        if (!$reportToken) {
            return response()->json(['message' => 'Token invalide'], 404);
        }

        // Marquer comme vu
        if (!$reportToken->viewed_at) {
            $reportToken->update(['viewed_at' => now()]);
        }

        // Récupérer les pointages du mois
        $monthStart = Carbon::createFromFormat('Y-m', $reportToken->month)->startOfMonth();
        $monthEnd = Carbon::createFromFormat('Y-m', $reportToken->month)->endOfMonth();

        $timeEntries = TimeEntry::where('user_id', $reportToken->user_id)
            ->whereBetween('date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
            ->whereNotNull('clock_in')
            ->whereNotNull('clock_out')
            ->with('breaks')
            ->orderBy('date')
            ->orderBy('clock_in')
            ->get();

        // Calculer le total d'heures
        $totalMinutes = 0;
        foreach ($timeEntries as $entry) {
            // Utiliser hours_worked seulement s'il est > 0
            if ($entry->hours_worked && $entry->hours_worked > 0) {
                $totalMinutes += $entry->hours_worked * 60;
            } else if ($entry->clock_in && $entry->clock_out) {
                $clockIn = Carbon::parse($entry->clock_in);
                $clockOut = Carbon::parse($entry->clock_out);
                
                // Calculer la différence en millisecondes puis convertir en minutes
                $diffMs = $clockOut->getTimestamp() - $clockIn->getTimestamp();
                
                // Si clock_out est avant clock_in, utiliser la valeur absolue
                if ($diffMs < 0) {
                    $diffMs = abs($diffMs);
                }
                
                $diffMinutes = round($diffMs / 60);
                
                // Soustraire les pauses
                $breakMinutes = 0;
                if ($entry->breaks) {
                    foreach ($entry->breaks as $breakItem) {
                        if ($breakItem->duration_minutes) {
                            $breakMinutes += $breakItem->duration_minutes;
                        } else if ($breakItem->start_break && $breakItem->end_break) {
                            $startBreak = Carbon::parse($breakItem->start_break);
                            $endBreak = Carbon::parse($breakItem->end_break);
                            $breakMs = $endBreak->getTimestamp() - $startBreak->getTimestamp();
                            if ($breakMs > 0) {
                                $breakMinutes += round($breakMs / 60);
                            }
                        }
                    }
                }
                
                $netMinutes = max(0, $diffMinutes - $breakMinutes);
                $totalMinutes += $netMinutes;
            }
        }

        $totalHours = floor($totalMinutes / 60);
        $remainingMinutes = $totalMinutes % 60;

        // Compter les jours uniques (pas le nombre de pointages)
        $uniqueDays = $timeEntries->pluck('date')
            ->map(function ($date) {
                return Carbon::parse($date)->format('Y-m-d');
            })
            ->unique()
            ->count();

        return response()->json([
            'token' => $reportToken,
            'user' => $reportToken->user,
            'month' => $reportToken->month,
            'time_entries' => $timeEntries,
            'total_hours' => $totalHours,
            'total_minutes' => $remainingMinutes,
            'days_worked' => $uniqueDays,
            'services_count' => $timeEntries->count(), // Nombre de pointages (services)
            'status' => $reportToken->status
        ]);
    }

    /**
     * Confirmer le rapport
     */
    public function confirm(Request $request, $token)
    {
        $reportToken = PayrollReportToken::where('token', $token)->first();

        if (!$reportToken) {
            return response()->json(['message' => 'Token invalide'], 404);
        }

        if ($reportToken->status !== 'pending') {
            return response()->json(['message' => 'Ce rapport a déjà été traité'], 400);
        }

        $reportToken->update([
            'status' => 'confirmed',
            'responded_at' => now()
        ]);

        return response()->json([
            'message' => 'Rapport confirmé avec succès',
            'status' => 'confirmed'
        ]);
    }

    /**
     * Rejeter le rapport
     */
    public function reject(Request $request, $token)
    {
        $request->validate([
            'reason' => 'required|string|max:1000'
        ]);

        $reportToken = PayrollReportToken::where('token', $token)->first();

        if (!$reportToken) {
            return response()->json(['message' => 'Token invalide'], 404);
        }

        if ($reportToken->status !== 'pending') {
            return response()->json(['message' => 'Ce rapport a déjà été traité'], 400);
        }

        $reportToken->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
            'responded_at' => now()
        ]);

        return response()->json([
            'message' => 'Rapport rejeté',
            'status' => 'rejected'
        ]);
    }

    /**
     * Récupérer les statuts des rapports pour un mois donné (pour l'admin)
     */
    public function getStatuses(Request $request)
    {
        $user = $request->user();
        
        // Vérifier que l'utilisateur est admin ou directeur
        if (!in_array($user->role, ['admin', 'director'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $request->validate([
            'month' => 'required|date_format:Y-m'
        ]);

        $month = $request->month;

        // Récupérer tous les tokens pour ce mois (du même établissement)
        $tokensQuery = PayrollReportToken::where('month', $month)
            ->with('user');
        if ($user->store_id) {
            $tokensQuery->where('store_id', $user->store_id);
        }
        $tokens = $tokensQuery->get();

        // Formater les résultats par user_id
        $statusesByUser = [];
        foreach ($tokens as $token) {
            $statusesByUser[$token->user_id] = [
                'user_id' => $token->user_id,
                'user_name' => $token->user->name,
                'status' => $token->status,
                'rejection_reason' => $token->rejection_reason,
                'sent_at' => $token->sent_at,
                'viewed_at' => $token->viewed_at,
                'responded_at' => $token->responded_at
            ];
        }

        return response()->json($statusesByUser);
    }
}
