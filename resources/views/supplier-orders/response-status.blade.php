<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réponse fournisseur</title>
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
        .reason {
            margin-top: 10px;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8fafc;
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Réponse enregistrée</h1>
        <p>{{ $message }}</p>
        @if(!empty($reason))
            <p><strong>Motif transmis :</strong></p>
            <div class="reason">{{ $reason }}</div>
        @endif
    </div>
</body>
</html>
