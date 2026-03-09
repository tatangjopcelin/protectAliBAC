<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $plainPassword
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->user->email],
            subject: 'Vos identifiants de connexion',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-credentials',
        );
    }
}
