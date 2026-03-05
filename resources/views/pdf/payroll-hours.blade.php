<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Récapitulatif des heures - {{ $monthLabel }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        .subtitle { font-size: 12px; color: #666; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f5f5f5; font-weight: bold; }
        .total-row { font-weight: bold; background-color: #e8f4fc; }
        .total-summary { margin-top: 16px; padding: 12px; background-color: #f0f7ff; border: 1px solid #2196F3; border-radius: 4px; font-size: 13px; }
        .text-right { text-align: right; }
    </style>
</head>
<body>
    <h1>Récapitulatif des heures travaillées</h1>
    <p class="subtitle">{{ $employeeName }} – {{ $monthLabel }}</p>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Entrée</th>
                <th>Sortie</th>
                <th>Pause</th>
                <th>Heures travaillées</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
            <tr>
                <td>{{ $row['date'] }}</td>
                <td>{{ $row['clock_in'] }}</td>
                <td>{{ $row['clock_out'] }}</td>
                <td class="text-right">{{ (int)floor($row['break_minutes'] / 60) }} h {{ str_pad((string)($row['break_minutes'] % 60), 2, '0', STR_PAD_LEFT) }}</td>
                <td class="text-right">{{ $row['worked_formatted'] }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align: center;">Aucun pointage pour ce mois.</td>
            </tr>
            @endforelse
        </tbody>
        @if(count($rows) > 0)
        <tfoot>
            <tr class="total-row">
                <td colspan="3">Total du mois</td>
                <td class="text-right">{{ (int)floor($totalBreakMinutes / 60) }} h {{ str_pad((string)($totalBreakMinutes % 60), 2, '0', STR_PAD_LEFT) }}</td>
                <td class="text-right">{{ $totalHours }} h {{ str_pad((string)$totalMinutes, 2, '0', STR_PAD_LEFT) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>

    @if(count($rows) > 0)
    <div class="total-summary">
        <strong>Total des heures travaillées :</strong> {{ $totalHours }} h {{ str_pad((string)$totalMinutes, 2, '0', STR_PAD_LEFT) }}
        (soit {{ $totalHoursDecimal }} heures)
    </div>
    @endif
</body>
</html>
