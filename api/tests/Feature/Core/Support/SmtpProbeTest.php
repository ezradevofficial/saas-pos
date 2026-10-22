<?php

namespace Tests\Feature\Core\Support;

use App\Core\Support\SmtpProbe;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * NFR-06, ADR 025: `app:preflight` signs in to the owner's SMTP mailbox
 * before a deploy goes live. Against a local fake server: the right
 * password passes, a wrong one fails without printing either credential,
 * and a closed port fails within the probe's timeout.
 */
class SmtpProbeTest extends TestCase
{
    private ?Process $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop(0);
        Mail::forgetMailers();

        parent::tearDown();
    }

    private function serve(string $password): int
    {
        $this->server = new Process([PHP_BINARY, base_path('tests/Fixtures/smtp/fake-smtp-server.php'), $password]);
        $this->server->start();
        $port = 0;
        $this->server->waitUntil(function ($type, $output) use (&$port) {
            return preg_match('/PORT (\d+)/', $output, $m) === 1 && ($port = (int) $m[1]) > 0;
        });

        return $port;
    }

    private function mailer(int $port, string $password): void
    {
        config(['mail.mailers.smtp' => array_merge(config('mail.mailers.smtp'), [
            'scheme' => 'smtp',
            'host' => '127.0.0.1',
            'port' => $port,
            'username' => 'owner@example.test',
            'password' => $password,
        ])]);
        Mail::forgetMailers();
    }

    public function test_an_accepted_sign_in_passes(): void
    {
        $this->mailer($this->serve('right-mailbox-password'), 'right-mailbox-password');

        $probe = new SmtpProbe;

        $this->assertTrue($probe->applies('smtp'));
        $this->assertNull($probe->check('smtp'));
    }

    public function test_a_refused_sign_in_fails_without_printing_the_credentials(): void
    {
        $this->mailer($this->serve('right-mailbox-password'), 'wrong-mailbox-password');

        $problem = (new SmtpProbe)->check('smtp');

        $this->assertNotNull($problem);
        $this->assertStringContainsString('535', $problem);
        $this->assertStringNotContainsString('owner@example.test', $problem);
        $this->assertStringNotContainsString('wrong-mailbox-password', $problem);
    }

    public function test_a_closed_port_fails_and_other_mailers_are_not_probed(): void
    {
        $this->mailer(1, 'any-password');

        $this->assertStringContainsString('Connection could not be established', (string) (new SmtpProbe)->check('smtp'));
        $this->assertFalse((new SmtpProbe)->applies('log'));
    }
}
