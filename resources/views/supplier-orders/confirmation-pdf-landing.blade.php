<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commande confirmée</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f8fafc;
            color: #0f172a;
        }
        .wrap {
            max-width: 560px;
            margin: 40px auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.06);
        }
        h1 {
            margin: 0 0 8px;
            font-size: 22px;
        }
        p {
            margin: 0 0 14px;
            color: #475569;
            line-height: 1.45;
        }
        .btn {
            display: inline-block;
            margin-top: 8px;
            padding: 14px 18px;
            background: #16a34a;
            color: #fff !important;
            text-decoration: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            -webkit-tap-highlight-color: transparent;
        }
        .hint {
            font-size: 14px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Commande confirmée</h1>
        <p>La commande est bien enregistrée côté établissement. Vous pouvez télécharger le PDF récapitulatif ci-dessous (lien valable quelques minutes).</p>
        <p class="hint">Sur iPhone : après avoir appuyé sur le bouton, regardez aussi dans <strong>Téléchargements</strong> ou l’icône de partage si la fenêtre ne s’affiche pas tout de suite.</p>
        <p>
            <a href="{{ $downloadPath }}" class="btn">Télécharger le PDF</a>
        </p>
    </div>
</body>
</html>
