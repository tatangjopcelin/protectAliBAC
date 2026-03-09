<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vos identifiants de connexion</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; text-align: center;">
        <p style="color: #2c3e50; margin: 0 0 8px 0; font-size: 18px;">Bienvenue sur l'application Brole</p>
        <h1 style="color: #2c3e50; margin-top: 0;">Vos identifiants de connexion</h1>
    </div>

    <div style="background-color: #ffffff; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
        <h2 style="color: #2c3e50;">Bonjour {{ $user->name }},</h2>

        <p>Un compte a été créé pour vous. Voici vos identifiants pour vous connecter à l'application :</p>

        <p style="background-color: #f8f9fa; padding: 12px; border-radius: 5px; margin: 16px 0;">
            <strong>Email :</strong> {{ $user->email }}<br>
            <strong>Mot de passe :</strong> {{ $plainPassword }}
        </p>

        <p>Nous vous recommandons de modifier votre mot de passe après votre première connexion.</p>

        <p>Conservez cet email en lieu sûr et ne partagez pas vos identifiants.</p>
    </div>

    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; color: #666; font-size: 12px;">
        <p>Cet email a été envoyé automatiquement. Merci de ne pas y répondre.</p>
    </div>
</body>
</html>
