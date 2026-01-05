<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class SchedulePublishedNotification extends Notification
{
    use Queueable;

    protected $schedules;
    protected $weekStart;
    protected $weekEnd;
    protected $publisher;

    /**
     * Create a new notification instance.
     */
    public function __construct($schedules, $weekStart, $weekEnd, User $publisher)
    {
        $this->schedules = $schedules;
        $this->weekStart = $weekStart;
        $this->weekEnd = $weekEnd;
        $this->publisher = $publisher;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $weekStartFormatted = Carbon::parse($this->weekStart)->locale('fr_FR')->isoFormat('dddd D MMMM YYYY');
        $weekEndFormatted = Carbon::parse($this->weekEnd)->locale('fr_FR')->isoFormat('dddd D MMMM YYYY');
        
        // Grouper les plannings par jour
        $schedulesByDay = [];
        $dayNames = [];
        $shiftTypes = [];
        $durations = [];
        $statusLabels = [];
        
        foreach ($this->schedules as $schedule) {
            $date = Carbon::parse($schedule->date)->format('Y-m-d');
            if (!isset($schedulesByDay[$date])) {
                $schedulesByDay[$date] = [];
                $dayNames[$date] = Carbon::parse($date)->locale('fr_FR')->isoFormat('dddd D MMMM');
            }
            $schedulesByDay[$date][] = $schedule;
            
            // Formater les heures - les accesseurs du modèle retournent déjà H:i
            $startTime = $schedule->start_time ?: '-';
            $endTime = $schedule->end_time ?: '-';
            
            // Calculer la durée
            $duration = '-';
            if ($schedule->start_time && $schedule->end_time) {
                try {
                    // Essayer d'abord avec H:i:s, puis H:i
                    $start = null;
                    $end = null;
                    
                    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $schedule->getAttributes()['start_time'] ?? '')) {
                        $start = Carbon::createFromFormat('H:i:s', $schedule->getAttributes()['start_time']);
                    } elseif (preg_match('/^\d{2}:\d{2}$/', $schedule->getAttributes()['start_time'] ?? '')) {
                        $start = Carbon::createFromFormat('H:i', $schedule->getAttributes()['start_time']);
                    } else {
                        // Utiliser la valeur formatée du modèle
                        $start = Carbon::createFromFormat('H:i', $schedule->start_time);
                    }
                    
                    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $schedule->getAttributes()['end_time'] ?? '')) {
                        $end = Carbon::createFromFormat('H:i:s', $schedule->getAttributes()['end_time']);
                    } elseif (preg_match('/^\d{2}:\d{2}$/', $schedule->getAttributes()['end_time'] ?? '')) {
                        $end = Carbon::createFromFormat('H:i', $schedule->getAttributes()['end_time']);
                    } else {
                        // Utiliser la valeur formatée du modèle
                        $end = Carbon::createFromFormat('H:i', $schedule->end_time);
                    }
                    
                    if ($start && $end) {
                        if ($end->lt($start)) {
                            $end->addDay();
                        }
                        $minutes = $start->diffInMinutes($end);
                        
                        // Soustraire la pause si disponible
                        if ($schedule->break_duration) {
                            $breakMinutes = 0;
                            if (preg_match('/(\d+):(\d+)/', $schedule->break_duration, $matches)) {
                                $breakMinutes = (int)$matches[1] * 60 + (int)$matches[2];
                            }
                            $minutes = max(0, $minutes - $breakMinutes);
                        }
                        
                        $hours = floor($minutes / 60);
                        $mins = $minutes % 60;
                        $duration = $hours > 0 ? ($mins > 0 ? "{$hours}h{$mins}" : "{$hours}h") : "{$mins}min";
                    }
                } catch (\Exception $e) {
                    \Log::warning('Erreur calcul durée planning: ' . $e->getMessage() . ' - start_time: ' . ($schedule->start_time ?? 'null') . ' - end_time: ' . ($schedule->end_time ?? 'null'));
                    $duration = '-';
                }
            }
            $durations[$schedule->id] = $duration;
            
            // Déterminer le type de shift
            $shiftType = 'MATIN';
            if ($schedule->start_time) {
                try {
                    // Extraire l'heure directement depuis la chaîne formatée
                    if (preg_match('/^(\d{2}):/', $schedule->start_time, $matches)) {
                        $hour = (int)$matches[1];
                        if ($hour >= 12 && $hour < 18) {
                            $shiftType = 'APRÈS-MIDI';
                        } elseif ($hour >= 18) {
                            $shiftType = 'SOIR';
                        }
                    }
                } catch (\Exception $e) {
                    \Log::warning('Erreur détermination type shift: ' . $e->getMessage());
                }
            }
            $shiftTypes[$schedule->id] = $shiftType;
            
            $statusLabel = [
                'planned' => 'Planifié',
                'confirmed' => 'Confirmé',
                'request' => 'Demande'
            ][$schedule->status] ?? $schedule->status;
            $statusLabels[$schedule->id] = $statusLabel;
        }
        
        // Trier les jours
        ksort($schedulesByDay);
        
        // Calculer le total des heures planifiées
        $totalHours = 0;
        $totalMinutes = 0;
        foreach ($this->schedules as $schedule) {
            if ($schedule->start_time && $schedule->end_time) {
                try {
                    $start = null;
                    $end = null;
                    
                    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $schedule->getAttributes()['start_time'] ?? '')) {
                        $start = Carbon::createFromFormat('H:i:s', $schedule->getAttributes()['start_time']);
                    } elseif (preg_match('/^\d{2}:\d{2}$/', $schedule->getAttributes()['start_time'] ?? '')) {
                        $start = Carbon::createFromFormat('H:i', $schedule->getAttributes()['start_time']);
                    } else {
                        $start = Carbon::createFromFormat('H:i', $schedule->start_time);
                    }
                    
                    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $schedule->getAttributes()['end_time'] ?? '')) {
                        $end = Carbon::createFromFormat('H:i:s', $schedule->getAttributes()['end_time']);
                    } elseif (preg_match('/^\d{2}:\d{2}$/', $schedule->getAttributes()['end_time'] ?? '')) {
                        $end = Carbon::createFromFormat('H:i', $schedule->getAttributes()['end_time']);
                    } else {
                        $end = Carbon::createFromFormat('H:i', $schedule->end_time);
                    }
                    
                    if ($start && $end) {
                        if ($end->lt($start)) {
                            $end->addDay();
                        }
                        $minutes = $start->diffInMinutes($end);
                        
                        // Soustraire la pause si disponible
                        if ($schedule->break_duration) {
                            $breakMinutes = 0;
                            if (preg_match('/(\d+):(\d+)/', $schedule->break_duration, $matches)) {
                                $breakMinutes = (int)$matches[1] * 60 + (int)$matches[2];
                            }
                            $minutes = max(0, $minutes - $breakMinutes);
                        }
                        
                        $totalMinutes += $minutes;
                    }
                } catch (\Exception $e) {
                    \Log::warning('Erreur calcul total heures: ' . $e->getMessage());
                }
            }
        }
        
        $totalHours = floor($totalMinutes / 60);
        $remainingMinutes = $totalMinutes % 60;
        $totalHoursFormatted = $totalHours > 0 
            ? ($remainingMinutes > 0 ? "{$totalHours}h{$remainingMinutes}" : "{$totalHours}h") 
            : "{$remainingMinutes}min";
        
        return (new MailMessage)
            ->subject('Votre planning de la semaine - Table du Boucher')
            ->view('emails.schedule-published', [
                'notifiable' => $notifiable,
                'weekStartFormatted' => $weekStartFormatted,
                'weekEndFormatted' => $weekEndFormatted,
                'schedulesByDay' => $schedulesByDay,
                'dayNames' => $dayNames,
                'shiftTypes' => $shiftTypes,
                'durations' => $durations,
                'statusLabels' => $statusLabels,
                'publisher' => $this->publisher,
                'totalHoursFormatted' => $totalHoursFormatted,
                'totalHours' => $totalHours,
                'totalMinutes' => $totalMinutes,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'week_start' => $this->weekStart,
            'week_end' => $this->weekEnd,
            'schedules_count' => count($this->schedules),
            'publisher_id' => $this->publisher->id,
            'publisher_name' => $this->publisher->name,
        ];
    }
}

