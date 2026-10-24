<?php

namespace App\Mail;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminReportResend extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Report $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Seu relatório EnerDiag');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report-resend',
            with: [
                'customerName' => $this->report->simulation->user->name,
                'report' => $this->report,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
