<?php

namespace App\Mail;

use App\Models\Quotation;
use App\Quotations\QuotationPdfGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuotationReleased extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Quotation $quotation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Orçamento EnerDiag '.$this->quotation->quote_number);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation-released',
            with: [
                'customerName' => $this->quotation->customer_name,
                'quoteNumber' => $this->quotation->quote_number,
                'validUntil' => $this->quotation->valid_until?->format('d/m/Y'),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $quotation = $this->quotation;

        return [Attachment::fromData(
            fn (): string => app(QuotationPdfGenerator::class)->generate($quotation),
            'orcamento-'.$quotation->quote_number.'.pdf',
        )->withMime('application/pdf')];
    }
}
