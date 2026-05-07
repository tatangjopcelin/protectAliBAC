<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motif de refus</title>
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
        textarea {
            width: 100%;
            min-height: 130px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px;
            font-size: 15px;
            resize: vertical;
            box-sizing: border-box;
        }
        button {
            margin-top: 14px;
            background: #dc2626;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Refuser la commande</h1>
        <p>Le motif de refus est obligatoire. Merci d'indiquer la raison pour informer l'établissement.</p>

        <form method="POST" action="{{ url('/api/supplier-orders/token/'.$token.'/respond/cancelled') }}">
            @csrf
            <textarea name="note" required placeholder="Exemple : produit indisponible cette semaine, rupture fournisseur..."></textarea>
            <button type="submit">Envoyer le refus</button>
        </form>
    </div>
</body>
</html>
