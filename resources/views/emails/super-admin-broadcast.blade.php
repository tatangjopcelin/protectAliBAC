<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $emailSubject }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #2563eb; padding: 25px 20px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="color: #ffffff; margin: 0; font-size: 24px;">Brole</h1>
        <p style="color: rgba(255,255,255,0.9); margin: 5px 0 0 0; font-size: 14px;">Gestion intelligente de votre établissement</p>
    </div>
    
    <div style="background-color: #ffffff; padding: 30px 25px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px;">
        <p style="font-size: 16px; color: #374151; margin-bottom: 20px;">
            Bonjour @if($adminName)<strong>{{ $adminName }}</strong>@else<strong>{{ $storeName }}</strong>@endif,
        </p>
        
        <div style="background-color: #f9fafb; padding: 20px; border-radius: 8px; border-left: 4px solid #2563eb; margin: 20px 0;">
            <div style="white-space: pre-line; color: #1f2937; font-size: 15px; line-height: 1.7;">{{ $emailBody }}</div>
        </div>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
            <p style="color: #6b7280; font-size: 14px; margin: 0;">
                Ce message a été envoyé à l'établissement <strong>{{ $storeName }}</strong>.
            </p>
        </div>
    </div>
    
    <div style="margin-top: 20px; text-align: center; color: #9ca3af; font-size: 12px;">
        <p style="margin: 5px 0;">
            Cordialement,<br>
            <strong style="color: #6b7280;">L'équipe Brole</strong>
        </p>
        <p style="margin: 15px 0 0 0;">
            © {{ date('Y') }} Brole - Tous droits réservés
        </p>
    </div>
</body>
</html>
