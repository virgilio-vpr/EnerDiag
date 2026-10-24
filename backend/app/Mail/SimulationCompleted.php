<?php

namespace App\Mail;

use App\Models\Report;
use App\Reports\PdfReportGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SimulationCompleted extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public Report $report) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Seu relatório EnerDiag está pronto');
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.simulation-completed',
            with: [
                'monthlySavings' => $this->report->total_monthly_savings,
                'yearlySavings' => $this->report->total_yearly_savings,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $report = $this->report;

        return [Attachment::fromData(
            fn (): string => app(PdfReportGenerator::class)->generate($report),
            'relatorio-enerdiag.pdf',
        )->withMime('application/pdf')];
    }
}
