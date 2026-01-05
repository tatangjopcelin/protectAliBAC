<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Votre planning de la semaine</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
        <h1 style="color: #2c3e50; margin-top: 0;">Table du Boucher</h1>
    </div>
    
    <div style="background-color: #ffffff; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
        <h2 style="color: #2c3e50;">Bonjour {{ $notifiable->name }} !</h2>
        
        <p>Votre planning pour la semaine du <strong>{{ $weekStartFormatted }}</strong> au <strong>{{ $weekEndFormatted }}</strong> a été publié.</p>
        
        @if(count($schedulesByDay) > 0)
        <div style="background-color: #e7f3ff; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0; border-radius: 4px;">
            <p style="margin: 0; font-size: 16px;">
                <strong>Total des heures planifiées : <span style="color: #2196F3; font-size: 18px;">{{ $totalHoursFormatted }}</span></strong>
            </p>
        </div>
        
        <p>Voici vos horaires planifiés :</p>
        <table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #ffffff;">
            <thead>
                <tr style="background-color: #f8f9fa;">
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Jour</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Heures</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Type</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Durée</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Statut</th>
                </tr>
            </thead>
            <tbody>
                @foreach($schedulesByDay as $date => $daySchedules)
                    @foreach($daySchedules as $schedule)
                    @php
                        // Les heures sont déjà formatées par les accesseurs du modèle
                        $startTime = $schedule->start_time ?: '-';
                        $endTime = $schedule->end_time ?: '-';
                    @endphp
                    <tr>
                        <td style="padding: 8px; border: 1px solid #ddd;"><strong>{{ $dayNames[$date] }}</strong></td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $startTime }} - {{ $endTime }}</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $shiftTypes[$schedule->id] }}</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $durations[$schedule->id] }}</td>
                        <td style="padding: 8px; border: 1px solid #ddd;">{{ $statusLabels[$schedule->id] }}</td>
                    </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
        
        <div style="background-color: #f8f9fa; padding: 15px; margin-top: 20px; border-radius: 4px; text-align: center;">
            <p style="margin: 0; font-size: 14px; color: #666;">
                <strong>Récapitulatif :</strong> {{ count($schedulesByDay) }} jour(s) planifié(s) pour un total de <strong style="color: #2c3e50;">{{ $totalHoursFormatted }}</strong>
            </p>
        </div>
        @else
        <p style="padding: 15px; background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 5px;">
            Aucun planning n'a été planifié pour cette semaine.
        </p>
        @endif
        
        <p style="margin-top: 20px;">
            <strong>Publié par :</strong> {{ $publisher->name }}
        </p>
        
        <p>Si vous avez des questions ou des modifications à demander, veuillez contacter votre responsable.</p>
    </div>
    
    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; color: #666; font-size: 12px;">
        <p>Cordialement,<br>L'équipe Table du Boucher</p>
    </div>
</body>
</html>

