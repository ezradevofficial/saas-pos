<?php

namespace App\Core\Notifications\Mail;

use App\Core\Notifications\Templates\TemplateRenderer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * NOT-01, NOT-03: a notification email, plain text and HTML, from a
 * delivery's rendered text. The HTML escapes the text (values included)
 * and keeps its line breaks; black on white, no theme colours.
 */
class NotificationMail extends Mailable
{
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $text,
        public readonly ?string $link,
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
            with: [
                'html' => TemplateRenderer::toHtml($this->text),
                'text' => $this->text,
                'url' => self::absolute($this->link),
            ],
        );
    }

    /** A path in the web app becomes a full URL; a full URL is kept. */
    public static function absolute(?string $link): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $link) === 1) {
            return $link;
        }

        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($link, '/');
    }
}
