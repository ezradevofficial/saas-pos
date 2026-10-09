<?php

namespace App\Core\DocumentTemplates\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * TPL-04: a document sent by email with its PDF attached, black on
 * white (the notification mail views, with no link or buttons).
 */
class DocumentMail extends Mailable
{
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $text,
        public readonly string $pdf,
        public readonly string $fileName,
        string $locale,
    ) {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notification',
            text: 'mail.notification-text',
            with: ['html' => e($this->text), 'text' => $this->text, 'url' => null, 'actions' => []],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->fileName)->withMime('application/pdf')];
    }
}
