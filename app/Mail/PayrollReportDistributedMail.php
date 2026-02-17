<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;

class PayrollReportDistributedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $employee,
        public Carbon $monthStart,
        public string $link,
        public string $pdfContent,
        public string $pdfFilename
    ) {}

    public function envelope(): Envelope
    {
        $subject = "Rapport de paie - {$this->monthStart->locale('fr')->monthName} {$this->monthStart->year}";
        return new Envelope(
            to: [$this->employee->email],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payroll-report-distributed',
        );
    }

    public function attachments(): array
    {
        return [
            \Illuminate\Mail\Mailables\Attachment::fromData(
                fn () => $this->pdfContent,
                $this->pdfFilename
            )->withMime('application/pdf'),
        ];
    }
}
