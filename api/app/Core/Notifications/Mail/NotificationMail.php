<?php

namespace App\Core\Notifications\Mail;

use App\Core\Notifications\Notifier;
use App\Core\Notifications\Templates\TemplateRenderer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * NOT-01, NOT-03: a notification email, plain text and HTML, from a
 * delivery's rendered text. The HTML escapes the text (values included)
 * and keeps its line breaks; black on white, no theme colours.
 *
 * BR-06: $from is the tenant's own sender (BrandingSettings::sender, an
 * address on a verified domain of the tenant); null sends from the
 * platform's MAIL_FROM_ADDRESS.
 */
class NotificationMail extends Mailable
{
    /** @param list<array{label: string, url: string}> $actions buttons made at send time (MailActions), absolute https URLs */
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $text,
        public readonly ?string $link,
        string $locale,
        public readonly array $actions = [],
        public readonly ?Address $sender = null,
    ) {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(from: $this->sender, subject: $this->mailSubject);
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
                'actions' => array_values(array_filter($this->actions, fn ($a) => is_string($a['url'] ?? null) && str_starts_with($a['url'], rtrim((string) config('app.frontend_url'), '/').'/'))),
            ],
        );
    }

    /** A path in the web app becomes a full URL; anything else is dropped (Notifier::safeLink). */
    public static function absolute(?string $link): ?string
    {
        if (Notifier::safeLink($link) === null) {
            return null;
        }

        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($link, '/');
    }
}
