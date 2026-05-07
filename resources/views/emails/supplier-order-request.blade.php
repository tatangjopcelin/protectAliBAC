<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Commande fournisseur</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827;">
    <h2>Nouvelle commande fournisseur</h2>
    <p>Bonjour {{ $order->supplier->name }},</p>
    <p>L'établissement <strong>{{ optional($order->store)->name ?? 'Brole' }}</strong> vous a envoyé une commande.</p>

    <p>
        <strong>Commande:</strong> {{ $order->order_number }}<br>
        <strong>Date:</strong> {{ optional($order->order_date)->format('d/m/Y') }}
    </p>

    <h3>Produits demandés</h3>
    <ul>
        @foreach($order->items as $item)
            <li>
                {{ $item->product_name }} - {{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }} {{ $item->unit }}
            </li>
        @endforeach
    </ul>

    <p style="margin-top: 24px;">
        <a href="{{ $confirmUrl }}" style="display:inline-block;padding:10px 14px;background:#16a34a;color:#fff;text-decoration:none;border-radius:8px;">
            Confirmer la commande
        </a>
        <a href="{{ $rejectUrl }}" style="display:inline-block;padding:10px 14px;background:#dc2626;color:#fff;text-decoration:none;border-radius:8px;margin-left:8px;">
            Refuser la commande
        </a>
    </p>

    <p style="margin-top: 16px; color: #6b7280; font-size: 13px;">
        Ce lien est valable jusqu'au {{ optional($order->supplier_token_expires_at)->format('d/m/Y H:i') }}.
    </p>
</body>
</html>
