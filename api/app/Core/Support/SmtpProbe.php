<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Throwable;

/**
 * NFR-06, ADR 025: does the mailer's SMTP server accept a connection and our
 * sign-in? Connects, says EHLO, upgrades with STARTTLS when offered, signs
 * in, and quits without sending anything. `app:preflight` uses it, so a
 * wrong password or a blocked port stops the deploy instead of the first
 * verification code silently never arriving.
 *
 * Returns null when the server accepted us, or what went wrong. The message
 * never carries the password, and the configured username is masked: the
 * deploy prints it into the CI log.
 */
class SmtpProbe
{
    public const TIMEOUT_SECONDS = 10;

    /** Whether the mailer is one this probe can check (an SMTP transport). */
    public function applies(string $mailer): bool
    {
        return config("mail.mailers.{$mailer}.transport") === 'smtp';
    }

    public function check(string $mailer): ?string
    {
        try {
            $transport = Mail::mailer($mailer)->getSymfonyTransport();
            if (! $transport instanceof EsmtpTransport) {
                return null;
            }

            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout(self::TIMEOUT_SECONDS);
            }

            $transport->start();
            $transport->stop();

            return null;
        } catch (Throwable $e) {
            return $this->mask($e->getMessage(), $mailer);
        }
    }

    private function mask(string $message, string $mailer): string
    {
        foreach (['username', 'password'] as $key) {
            $value = (string) config("mail.mailers.{$mailer}.{$key}");
            if ($value !== '') {
                $message = str_replace($value, "<{$key}>", $message);
            }
        }

        return trim(preg_replace('/\s+/', ' ', $message)) ?: 'the SMTP server refused the connection';
    }
}
