<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Commande fournisseur {{ $order->order_number }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111827; font-size: 12px; }
        h1 { margin: 0 0 8px; font-size: 20px; }
        .meta { margin: 0 0 16px; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; }
        th { background: #f3f4f6; text-align: left; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>Commande fournisseur confirmée</h1>
    <p class="meta">
        <strong>Commande :</strong> {{ $order->order_number }}<br>
        <strong>Etablissement :</strong> {{ optional($order->store)->name ?? '-' }}<br>
        <strong>Fournisseur :</strong> {{ optional($order->supplier)->name ?? '-' }}<br>
        <strong>Date commande :</strong> {{ optional($order->order_date)->format('d/m/Y') }}<br>
        <strong>Date de livraison prévue :</strong>
        {{ optional($order->expected_delivery_date)->format('d/m/Y') ?? 'Non précisée' }}
    </p>

    @if(optional($order->store)->address || optional($order->store)->phone)
        <p class="meta">
            <strong>Contact établissement :</strong><br>
            @if(optional($order->store)->address)
                {{ $order->store->address }}<br>
            @endif
            @if(optional($order->store)->phone)
                <strong>Tél. magasin :</strong> {{ $order->store->phone }}<br>
            @endif
        </p>
    @endif

    @php($staffPhonesPdf = optional($order->store)->keyStaffPhonesSlashSeparated() ?? '')
    @if($staffPhonesPdf !== '')
        <p class="meta">
            <strong>Téléphones :</strong> {{ $staffPhonesPdf }}
        </p>
    @endif

    @if(!empty($order->supplier_confirmation_note))
        <p class="meta">
            <strong>Note fournisseur :</strong><br>
            {{ $order->supplier_confirmation_note }}
        </p>
    @endif

    <table>
        <thead>
            <tr>
                <th>Nom produit</th>
                <th class="right">Quantité</th>
                <th>Unité</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td>{{ $item->product_name }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}</td>
                    <td>{{ $item->unit ?: '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
