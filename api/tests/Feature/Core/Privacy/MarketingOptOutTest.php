<?php

namespace Tests\Feature\Core\Privacy;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Parties\Party;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\NotificationAddress;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Privacy\Consents;
use App\Core\Privacy\Models\ConsentRecord;
use App\Core\Privacy\OptOuts;
use App\Core\Privacy\OptOutTokens;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-08 (KE DPA): every marketing message carries an opt-out. Email: a
 * signed, single-purpose unsubscribe link and List-Unsubscribe headers;
 * using the link withdraws consent on that channel once, without signing
 * in. SMS and WhatsApp: "Reply STOP to opt out", and a STOP reply
 * withdraws consent on every channel (owner decision 2026-10-22). Tampered, foreign and expired links
 * do nothing; a later marketing send to the customer is skipped.
 */
class MarketingOptOutTest extends TestCase
{
    use BuildsNotifications, RefreshTenantDatabase;

    private const OFFER = 'core.marketing.test_offer';

    private Party $amina;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpOrganisation();
        app(EventTypes::class)->register(new EventType(
            key: self::OFFER,
            placeholders: ['sender_name' => 'Duka', 'message' => 'Offer'],
            defaultChannels: [Channels::EMAIL],
            channels: EventType::MARKETING_CHANNELS,
            langKey: 'notifications.events.core.notification.test',
            contactsOnly: true,
            marketing: true,
        ));
        $this->amina = $this->inTenant(fn () => Party::query()->create([
            'company_id' => $this->acme->id, 'kind' => 'person', 'name' => 'Amina Otieno', 'roles' => ['customer'],
            'phones' => [['number' => '+254712000111', 'label' => null]],
        ]));
        $this->inTenant(fn () => app(Consents::class)->record($this->owner, 'customer', $this->amina->id, 'marketing', 'any', true, 'back_office', 'Asked at the till'));
    }

    private function offer(string $channel, string $to, string $locale = 'en', ?Party $party = null): Collection
    {
        $party ??= $this->amina;

        return $this->inTenant(fn () => app(Notifier::class)->send(new NotificationEvent(
            self::OFFER, [], ['sender_name' => 'Duka', 'message' => 'Ten percent off this weekend'], null,
            [new NotificationAddress($channel, $to, $party->name, $locale, $party->id)],
        )));
    }

    /** @return array{0: NotificationDelivery, 1: string} the email delivery and its link's token */
    private function sentEmail(): array
    {
        $delivery = $this->offer(Channels::EMAIL, 'amina@example.com')->sole();
        $mail = null;
        Mail::assertSent(NotificationMail::class, function (NotificationMail $m) use (&$mail) {
            $mail = $m;

            return true;
        });
        $this->assertNotNull($mail->unsubscribeUrl);
        $token = (string) preg_replace('#^.*/opt-out/([^?]+)\?lang=en$#', '$1', $mail->unsubscribeUrl);

        return [$delivery, $token];
    }

    private function withdrawals(): int
    {
        return $this->inTenant(fn () => ConsentRecord::query()->where('granted', false)->count());
    }

    public function test_a_marketing_email_carries_an_unsubscribe_link_and_one_click_headers(): void
    {
        [$delivery] = $this->sentEmail();

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($delivery) {
            $headers = $mail->headers()->text;
            $this->assertStringStartsWith(rtrim((string) config('app.frontend_url'), '/').'/opt-out/', $mail->unsubscribeUrl);
            $this->assertSame('<'.$mail->oneClickUrl.'>', $headers['List-Unsubscribe']);
            $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
            $this->assertStringContainsString($delivery->id, $mail->oneClickUrl);
            $this->assertStringContainsString('Unsubscribe', $mail->render());

            return true;
        });

        // A transactional (non-marketing) email has neither.
        $this->assertNull((new NotificationMail('s', 't', null, 'en'))->unsubscribeUrl);
        $this->assertSame([], (new NotificationMail('s', 't', null, 'en'))->headers()->text);
    }

    public function test_the_link_withdraws_email_consent_once_and_a_later_send_is_skipped(): void
    {
        [$delivery, $token] = $this->sentEmail();

        // Reading the page never withdraws (mail scanners open links).
        $this->getJson("/api/v1/opt-out/{$token}")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.channel', 'email')->assertJsonMissingPath('data.party');
        $this->assertSame(0, $this->withdrawals());

        $this->postJson("/api/v1/opt-out/{$token}")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $record = $this->inTenant(fn () => ConsentRecord::query()->where('granted', false)->sole());
        $this->assertSame(['email', 'opt_out_link', null], [$record->channel, $record->source, $record->recorded_by]);
        $this->assertStringNotContainsString('Ten percent', (string) $record->evidence);

        // Reused: nothing new is recorded.
        $this->postJson("/api/v1/opt-out/{$token}")->assertOk()->assertJsonPath('data.status', 'already');
        $this->getJson("/api/v1/opt-out/{$token}")->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertSame(1, $this->withdrawals());
        $this->assertNotNull($this->inTenant(fn () => $delivery->fresh()->opted_out_at));

        // The next marketing email is skipped; SMS still goes (consent was withdrawn for email only).
        $this->assertSame(NotificationDelivery::REASON_NO_CONSENT, $this->offer(Channels::EMAIL, 'amina@example.com')->sole()->reason);
        $this->assertSame('delivered', $this->inTenant(fn () => $this->offer(Channels::SMS, '+254712000111')->sole()->fresh()->status));
    }

    public function test_a_mail_client_one_click_post_withdraws(): void
    {
        [, $token] = $this->sentEmail();

        $this->call('POST', "/api/v1/opt-out/{$token}", ['List-Unsubscribe' => 'One-Click'], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => '*/*'])
            ->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->assertFalse($this->inTenant(fn () => app(Consents::class)->allows('customer', $this->amina->id, 'marketing', 'email')));
    }

    public function test_a_tampered_token_is_refused(): void
    {
        [$delivery, $token] = $this->sentEmail();
        [$tenant, $id, $signature] = explode('.', $token);
        $flipped = $signature[0] === 'A' ? 'B'.substr($signature, 1) : 'A'.substr($signature, 1);

        foreach (["{$tenant}.{$id}.{$flipped}", "{$tenant}.{$this->owner->id}.{$signature}", "{$id}.{$tenant}.{$signature}", 'not-a-token', "{$tenant}.{$id}"] as $bad) {
            $this->postJson('/api/v1/opt-out/'.$bad)->assertNotFound()->assertJsonPath('code', 'opt_out_link_invalid');
        }

        // A token of another purpose: a signature over the same ids with another key.
        $foreign = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$tenant}|{$id}", (string) config('app.key'), true)), '+/', '-_'), '=');
        $this->postJson("/api/v1/opt-out/{$tenant}.{$id}.{$foreign}")->assertNotFound();

        $this->assertSame(0, $this->withdrawals());
        $this->assertNull($this->inTenant(fn () => $delivery->fresh()->opted_out_at));
    }

    public function test_a_token_never_reaches_another_tenant(): void
    {
        [$delivery] = $this->sentEmail();
        $other = $this->otherTenant()['user'];

        // Even correctly signed, tenant B's id with tenant A's delivery finds nothing under B's row-level security.
        $crossed = app(OptOutTokens::class)->make($other->tenant_id, $delivery->id);
        $this->postJson("/api/v1/opt-out/{$crossed}")->assertNotFound();
        $this->getJson("/api/v1/opt-out/{$crossed}")->assertNotFound();
        $this->assertSame(0, $this->withdrawals());
    }

    public function test_an_expired_link_is_refused(): void
    {
        [, $token] = $this->sentEmail();
        $this->travel(OptOuts::LINK_DAYS + 1)->days();

        $this->postJson("/api/v1/opt-out/{$token}")->assertNotFound();
        $this->assertSame(0, $this->withdrawals());
    }

    public function test_texts_say_reply_stop_in_the_customers_language(): void
    {
        $sms = $this->offer(Channels::SMS, '+254712000111')->sole();
        $this->assertStringEndsWith('Reply STOP to opt out.', $sms->body);

        $whatsapp = $this->offer(Channels::WHATSAPP, '+254712000111', 'fr')->sole();
        $this->assertStringEndsWith('Répondez STOP pour ne plus en recevoir.', $whatsapp->body);

        $this->assertStringNotContainsString('STOP', $this->offer(Channels::EMAIL, 'amina@example.com')->sole()->body);
    }

    public function test_a_stop_reply_withdraws_every_channel(): void
    {
        $optOuts = app(OptOuts::class);
        foreach (['STOP', ' stop. ', 'Arrêt', 'unsubscribe!', 'STOP ALL'] as $word) {
            $this->assertTrue($optOuts->isOptOut($word), $word);
        }
        foreach (['stop the order please', 'Stopover', '', 'two widgets'] as $text) {
            $this->assertFalse($optOuts->isOptOut($text), $text);
        }

        // A second customer with the number who consents by email only: a STOP by SMS withdraws that too.
        $baraka = $this->inTenant(fn () => Party::query()->create([
            'company_id' => $this->acme->id, 'kind' => 'person', 'name' => 'Baraka Otieno', 'roles' => ['customer'],
            'phones' => [['number' => '+254712000111', 'label' => null]],
        ]));
        $this->inTenant(fn () => app(Consents::class)->record($this->owner, 'customer', $baraka->id, 'marketing', 'email', true, 'back_office', 'Asked at the till'));

        $this->assertSame(2, $this->inTenant(fn () => $optOuts->optOut('sms', '+254712000111', 'test_provider')));
        // A repeat finds nobody still consenting.
        $this->assertSame(0, $this->inTenant(fn () => $optOuts->optOut('sms', '+254712000111', 'test_provider')));

        // One withdrawal per customer, through Consents (audited), for every channel, with the evidence.
        $records = $this->inTenant(fn () => ConsentRecord::query()->where('granted', false)->orderBy('subject_id')->get());
        $this->assertCount(2, $records);
        $this->assertEqualsCanonicalizing([$this->amina->id, $baraka->id], $records->pluck('subject_id')->all());
        foreach ($records as $record) {
            $this->assertSame(['any', 'opt_out_reply'], [$record->channel, $record->source]);
            $this->assertStringContainsString('sms', (string) $record->evidence);
            $this->assertStringContainsString('test_provider', (string) $record->evidence);
        }
        $this->assertSame(2, $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.consent.withdraw')->count()));

        foreach ([$this->amina, $baraka] as $party) {
            foreach (EventType::MARKETING_CHANNELS as $channel) {
                $this->assertFalse($this->inTenant(fn () => app(Consents::class)->allows('customer', $party->id, 'marketing', $channel)), "{$party->name} {$channel}");
            }
        }
        $this->assertSame(NotificationDelivery::REASON_NO_CONSENT, $this->offer(Channels::SMS, '+254712000111')->sole()->reason);
        $this->assertSame(NotificationDelivery::REASON_NO_CONSENT, $this->offer(Channels::EMAIL, 'amina@example.com')->sole()->reason);

        // Another tenant's customer with the same number is untouched: replies act in the tenant they reached.
        $this->assertSame(0, $this->inTenant(fn () => $optOuts->optOut('sms', '+254799999999', 'test_provider')));
    }
}
