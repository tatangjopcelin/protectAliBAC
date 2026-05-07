<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lien non utilisable</title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; background: #f8fafc; color: #0f172a; }
        .wrap {
            max-width: 560px;
            margin: 40px auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.06);
        }
        h1 { margin: 0 0 8px; font-size: 22px; }
        p { margin: 0 0 12px; color: #475569; line-height: 1.45; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Lien non utilisable</h1>
        <p>{{ $message }}</p>
        <p style="color: #64748b; font-size: 14px;">
            Si vous avez déjà confirmé ou refusé cette commande, aucune autre action n’est nécessaire.
            En cas de doute, contactez directement l’établissement.
        </p>
    </div>
</body>
</html>
