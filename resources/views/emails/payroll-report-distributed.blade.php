<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapport de paie</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; text-align: center;">
        @if($logoUrl = config('mail.logo_url'))
        <img src="{{ $logoUrl }}" alt="{{ $employee->getMailSignatureName() }}" style="max-height: 50px; max-width: 250px;">
        @else
        <h1 style="color: #2c3e50; margin-top: 0;">{{ $employee->getMailSignatureName() }}</h1>
        @endif
    </div>

    <div style="background-color: #ffffff; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
        <h2 style="color: #2c3e50;">Bonjour {{ $employee->name }},</h2>

        <p>Votre rapport de paie pour le mois de <strong>{{ $monthStart->locale('fr')->monthName }} {{ $monthStart->year }}</strong> est disponible.</p>

        <p>Un récapitulatif de vos heures travaillées (liste des jours, heures, minutes et pauses) est joint à cet email en PDF.</p>

        <p>Veuillez <strong>consulter et confirmer vos heures de travail</strong> en cliquant sur le lien suivant :</p>
        <p style="margin: 16px 0;">
            <a href="{{ $link }}" style="display: inline-block; padding: 12px 24px; background-color: #2196F3; color: #ffffff !important; text-decoration: none; border-radius: 5px; font-weight: bold;">Consulter et confirmer mes heures</a>
        </p>
        <p style="font-size: 12px; color: #666;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>{{ $link }}</p>
    </div>

    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; color: #666; font-size: 12px;">
        <p>Cordialement,<br>L'équipe {{ $employee->getMailSignatureName() }}</p>
    </div>
</body>
</html>
