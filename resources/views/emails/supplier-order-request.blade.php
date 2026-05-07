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
        <strong>Date:</strong> {{ optional($order->order_date)->format('d/m/Y') }}<br>
        <strong>Date de livraison prevue:</strong>
        {{ optional($order->expected_delivery_date)->format('d/m/Y') ?? 'Non precisee' }}
    </p>

    @php
        $ec = $establishmentContacts ?? ['store' => null, 'staff' => []];
        $teamPhonesMail = optional($order->store)->keyStaffPhonesSlashSeparated() ?? '';
    @endphp
    @if(!empty($ec['store']) || $teamPhonesMail !== '')
        <h3>Coordonnées de l’établissement</h3>
        @if(!empty($ec['store']))
            <p style="margin: 0 0 8px;">
                <strong>{{ $ec['store']['name'] ?? '' }}</strong><br>
                @if(!empty($ec['store']['address']))
                    {{ $ec['store']['address'] }}<br>
                @endif
                @if(!empty($ec['store']['phone']))
                    <strong>Tél. magasin :</strong> {{ $ec['store']['phone'] }}
                @endif
            </p>
        @endif
        @if($teamPhonesMail !== '')
            <p style="margin: 8px 0 0;">
                <strong>Téléphones :</strong> {{ $teamPhonesMail }}
            </p>
        @endif
    @endif

    <h3>Produits demandés</h3>
    <table style="width:100%; border-collapse: collapse; border: 1px solid #e5e7eb; margin-top: 8px;">
        <thead>
            <tr style="background: #f3f4f6;">
                <th style="text-align:left; padding:8px; border:1px solid #e5e7eb;">Nom produit</th>
                <th style="text-align:right; padding:8px; border:1px solid #e5e7eb;">Quantité</th>
                <th style="text-align:left; padding:8px; border:1px solid #e5e7eb;">Unité</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td style="padding:8px; border:1px solid #e5e7eb;">{{ $item->product_name }}</td>
                    <td style="padding:8px; border:1px solid #e5e7eb; text-align:right;">
                        {{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}
                    </td>
                    <td style="padding:8px; border:1px solid #e5e7eb;">{{ $item->unit ?: '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

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
