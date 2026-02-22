<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PayrollReportDistributedMail;
use App\Models\PayrollReportToken;
use App\Models\User;
use App\Models\TimeEntry;
use Barryvdh\DomPDF\Facade\Pdf;
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

                // URL frontend pour consulter et confirmer
                $frontendUrl = env('FRONTEND_URL', 'http://localhost:8100');
                $link = "{$frontendUrl}/payroll-report/{$token->token}";

                // Données pour le PDF récapitulatif des heures
                $pdfData = $this->getPayrollPdfData($employee, $month);
                $pdf = Pdf::loadView('pdf.payroll-hours', $pdfData);
                $pdfContent = $pdf->output();
                $pdfFilename = 'recapitulatif-heures-' . $month . '-' . \Illuminate\Support\Str::slug($employee->name) . '.pdf';

                Mail::send(new PayrollReportDistributedMail($employee, $monthStart, $link, $pdfContent, $pdfFilename));

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
     * Construire les données pour le PDF récapitulatif des heures (pointages) d'un employé pour un mois.
     */
    private function getPayrollPdfData(User $employee, string $month): array
    {
        $monthStart = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $monthEnd = Carbon::createFromFormat('Y-m', $month)->endOfMonth();
        $monthLabel = $monthStart->locale('fr')->monthName . ' ' . $monthStart->year;

        $timeEntries = TimeEntry::where('user_id', $employee->id)
            ->whereBetween('date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
            ->whereNotNull('clock_in')
            ->whereNotNull('clock_out')
            ->with('breaks')
            ->orderBy('date')
            ->orderBy('clock_in')
            ->get();

        $rows = [];
        $totalNetMinutes = 0;
        $totalBreakMinutes = 0;

        foreach ($timeEntries as $entry) {
            $breakMinutes = 0;
            if ($entry->breaks) {
                foreach ($entry->breaks as $breakItem) {
                    if ($breakItem->duration_minutes) {
                        $breakMinutes += $breakItem->duration_minutes;
                    } elseif ($breakItem->start_break && $breakItem->end_break) {
                        $startBreak = Carbon::parse($breakItem->start_break);
                        $endBreak = Carbon::parse($breakItem->end_break);
                        $breakMs = $endBreak->getTimestamp() - $startBreak->getTimestamp();
                        if ($breakMs > 0) {
                            $breakMinutes += (int) round($breakMs / 60);
                        }
                    }
                }
            }

            $netMinutes = 0;
            if ($entry->hours_worked && $entry->hours_worked > 0) {
                $netMinutes = (int) round($entry->hours_worked * 60);
            } elseif ($entry->clock_in && $entry->clock_out) {
                $clockIn = Carbon::parse($entry->clock_in);
                $clockOut = Carbon::parse($entry->clock_out);
                $diffMinutes = (int) round(($clockOut->getTimestamp() - $clockIn->getTimestamp()) / 60);
                if ($diffMinutes < 0) {
                    $diffMinutes = abs($diffMinutes);
                }
                $netMinutes = max(0, $diffMinutes - $breakMinutes);
            }

            $totalNetMinutes += $netMinutes;
            $totalBreakMinutes += $breakMinutes;

            $clockInFormatted = $entry->clock_in ? Carbon::parse($entry->clock_in)->format('H:i') : '-';
            $clockOutFormatted = $entry->clock_out ? Carbon::parse($entry->clock_out)->format('H:i') : '-';
            $hours = (int) floor($netMinutes / 60);
            $mins = $netMinutes % 60;
            $workedFormatted = $hours > 0 ? "{$hours} h " . str_pad((string) $mins, 2, '0', STR_PAD_LEFT) : $mins . ' min';

            $rows[] = [
                'date' => Carbon::parse($entry->date)->locale('fr')->isoFormat('dddd D MMMM YYYY'),
                'clock_in' => $clockInFormatted,
                'clock_out' => $clockOutFormatted,
                'break_minutes' => $breakMinutes,
                'worked_formatted' => $workedFormatted,
            ];
        }

        $totalHours = (int) floor($totalNetMinutes / 60);
        $remainingMinutes = (int) ($totalNetMinutes % 60);
        $totalHoursDecimal = number_format($totalHours + $remainingMinutes / 60, 2, ',', ' ');

        return [
            'employeeName' => $employee->name,
            'monthLabel' => $monthLabel,
            'rows' => $rows,
            'totalBreakMinutes' => $totalBreakMinutes,
            'totalHours' => $totalHours,
            'totalMinutes' => $remainingMinutes,
            'totalHoursDecimal' => $totalHoursDecimal,
        ];
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

    /**
     * Compter les rapports confirmés ou rejetés non vus par l'admin
     */
    public function getDecidedUnseenCount(Request $request)
    {
        $user = $request->user();
        
        // Vérifier que l'utilisateur est admin ou directeur
        if (!in_array($user->role, ['admin', 'director'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        // Compter les rapports confirmés ou rejetés qui n'ont pas encore été vus par l'admin
        $countQuery = PayrollReportToken::whereIn('status', ['confirmed', 'rejected'])
            ->whereNull('admin_viewed_at');
        
        if ($user->store_id) {
            $countQuery->where('store_id', $user->store_id);
        }
        
        $count = $countQuery->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Marquer les rapports confirmés/rejetés comme vus par l'admin
     */
    public function markDecidedSeen(Request $request)
    {
        $user = $request->user();
        
        // Vérifier que l'utilisateur est admin ou directeur
        if (!in_array($user->role, ['admin', 'director'])) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        // Marquer tous les rapports confirmés/rejetés comme vus par l'admin
        $updateQuery = PayrollReportToken::whereIn('status', ['confirmed', 'rejected'])
            ->whereNull('admin_viewed_at');
        
        if ($user->store_id) {
            $updateQuery->where('store_id', $user->store_id);
        }
        
        $updateQuery->update(['admin_viewed_at' => now()]);

        return response()->json(['message' => 'Rapports marqués comme vus']);
    }
}
