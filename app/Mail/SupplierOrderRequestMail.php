<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupplierOrderRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public string $confirmUrl,
        public string $rejectUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nouvelle commande fournisseur - '.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.supplier-order-request',
        );
    }
}
