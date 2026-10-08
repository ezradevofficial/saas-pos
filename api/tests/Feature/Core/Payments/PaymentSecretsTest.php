<?php

namespace Tests\Feature\Core\Payments;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-04, concept note 7.1: provider secrets and the callback token never
// leave the server except to the people who configure the method; the
// token is stored encrypted, rotation is audited by name only, and C2B
// URLs are registered with Daraja without the forbidden words.
class PaymentSecretsTest extends TestCase
{
    use BuildsPayments, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments(['initiator_name' => 'apiop'], ['security_credential' => 'sec-'.self::SECRET]);
        $this->fakeDaraja();
    }

    public function test_callback_urls_need_configure_and_secrets_never_show(): void
    {
        $urls = $this->getJson("/api/v1/payment-methods/{$this->mpesa->id}/callbacks", $this->headersFor())->assertOk()->json('data.urls');
        $this->assertSame(['stk', 'c2b-validate', 'c2b-confirm', 'b2c-result', 'b2c-timeout', 'status-result', 'status-timeout'], array_keys($urls));
        $token = $this->callbackToken();

        foreach ($urls as $url) {
            $this->assertStringContainsString($token, $url);
            foreach (['mpesa', 'safaricom', 'sql', 'query', 'exec', 'cmd'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $url);
            }
        }

        // The method itself never shows the token or the secrets.
        $method = $this->getJson("/api/v1/payment-methods/{$this->mpesa->id}", $this->headersFor())->assertOk();
        $this->assertStringNotContainsString($token, $method->getContent());
        $this->assertStringNotContainsString(self::SECRET, $method->getContent());
        $method->assertJsonPath('data.secrets_set.security_credential', true);

        // A branch manager reads payment methods but may not see the URLs.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->getJson("/api/v1/payment-methods/{$this->mpesa->id}/callbacks", $this->headersFor($manager))->assertForbidden();
        $this->postJson("/api/v1/payment-methods/{$this->mpesa->id}/callbacks/rotate", [], $this->headersFor($manager))->assertForbidden();

        $this->inTenant(function () use ($token) {
            $this->assertStringNotContainsString($token, (string) DB::table('payment_methods')->where('id', $this->mpesa->id)->value('callback_token'));
            $this->assertStringNotContainsString($token, AuditEntry::query()->get()->toJson());
        });
    }

    public function test_rotation_is_audited_by_name_only(): void
    {
        $old = $this->callbackToken();
        $new = $this->postJson("/api/v1/payment-methods/{$this->mpesa->id}/callbacks/rotate", [], $this->headersFor())->assertOk()->json('data.urls.stk');

        $this->assertStringNotContainsString($old, $new);
        $this->inTenant(function () use ($old, $new) {
            $entry = AuditEntry::query()->where('action', 'core.payment_method.callback_token_rotate')->sole();
            $this->assertSame($this->owner->id, $entry->user_id);
            $all = AuditEntry::query()->get()->toJson();
            $this->assertStringNotContainsString($old, $all);
            $this->assertStringNotContainsString(basename(dirname($new)), $all);
        });
    }

    public function test_c2b_urls_are_registered_for_the_shortcode(): void
    {
        $this->postJson("/api/v1/payment-methods/{$this->mpesa->id}/c2b/register", [], $this->headersFor())->assertOk()->assertJsonPath('data.registered', true);

        $body = Http::recorded(fn (Request $r) => str_contains($r->url(), '/mpesa/c2b/v1/registerurl'))->first()[0]->data();
        $this->assertSame('174379', $body['ShortCode']);
        $this->assertSame('Completed', $body['ResponseType']);
        $this->assertStringEndsWith('/c2b-confirm', $body['ConfirmationURL']);
        $this->assertStringEndsWith('/c2b-validate', $body['ValidationURL']);
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::query()->where('action', 'core.payment_method.c2b_register')->count()));
    }

    public function test_settings_take_only_known_keys_and_values(): void
    {
        $this->patchJson("/api/v1/payment-methods/{$this->mpesa->id}", ['settings' => ['transaction_type' => 'shop']], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('settings.transaction_type');
        $this->patchJson("/api/v1/payment-methods/{$this->mpesa->id}", ['settings' => ['transaction_type' => 'till', 'till_number' => '5123456']], $this->headersFor())
            ->assertOk()->assertJsonPath('data.settings.transaction_type', 'till')->assertJsonPath('data.configured', true);
        // The optional initiator keys are not needed to switch the method on.
        $this->assertNotContains('security_credential', $this->getJson("/api/v1/payment-methods/{$this->mpesa->id}", $this->headersFor())->json('data.missing'));
    }
}
