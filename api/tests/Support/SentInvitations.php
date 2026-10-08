<?php

namespace Tests\Support;

use App\Core\Notifications\Channels;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Notifications\Drivers\FakeChannelDriver;
use App\Core\Notifications\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Assert;

/**
 * Invitations go out through the Notifier (ADR 009): by email as a
 * NotificationMail whose link carries the token (needs Mail::fake()), or
 * by SMS through the fake SMS driver. The token appears only in what was
 * handed over, never in a table.
 */
final class SentInvitations
{
    /** @return list<array{channel: string, to: string, token: string, text: string}> every invitation handed over, in order */
    public static function all(): array
    {
        $sent = [];

        foreach (Mail::sent(NotificationMail::class) as $mail) {
            if (preg_match('#^/invitations/([A-Za-z0-9]{40})$#', (string) $mail->link, $m) === 1) {
                $sent[] = ['channel' => Channels::EMAIL, 'to' => $mail->to[0]['address'], 'token' => $m[1], 'text' => $mail->text];
            }
        }

        $sms = app(ChannelDrivers::class)->for(Channels::SMS);

        if ($sms instanceof FakeChannelDriver) {
            foreach ($sms->sent as $message) {
                if (preg_match('#/invitations/([A-Za-z0-9]{40})#', $message->body, $m) === 1) {
                    $sent[] = ['channel' => Channels::SMS, 'to' => $message->to, 'token' => $m[1], 'text' => $message->body];
                }
            }
        }

        return $sent;
    }

    /** The plain token of the last invitation sent (to $to, when given). */
    public static function lastToken(?string $to = null): string
    {
        $sent = array_values(array_filter(self::all(), fn (array $s) => $to === null || $s['to'] === $to));
        Assert::assertNotEmpty($sent, 'No invitation was sent.');

        return end($sent)['token'];
    }
}
