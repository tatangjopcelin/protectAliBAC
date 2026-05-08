<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmer la commande</title>
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
            min-height: 100px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px;
            font-size: 15px;
            resize: vertical;
            box-sizing: border-box;
        }
        button {
            margin-top: 14px;
            background: #16a34a;
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
        <h1>Confirmer la commande</h1>
        <p>En validant, la commande est enregistrée comme confirmée. Vous pouvez ajouter une note optionnelle (précision de livraison, conditionnement…).</p>
        <p>Vous serez redirigé vers une page où vous pourrez <strong>télécharger le PDF</strong> récapitulatif — cela fonctionne mieux sur téléphone que l’envoi direct du fichier.</p>

        {{-- Chemin relatif : garde le même schéma (https) que la page ; url() utilisait parfois APP_URL en http → avertissement Chrome « formulaire non sécurisé » --}}
        <form method="POST" action="/api/supplier-orders/token/{{ $token }}/respond/confirmed">
            @csrf
            <label for="confirmation_note" style="display:block;font-weight:600;margin-bottom:6px;font-size:14px;">Note (optionnelle)</label>
            <textarea id="confirmation_note" name="confirmation_note" placeholder="Exemple : livraison le matin, palette entière attendue…"></textarea>
            <button type="submit">Confirmer et télécharger le PDF</button>
        </form>
    </div>
</body>
</html>
