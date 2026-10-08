<?php

namespace Tests\Feature\Core\Approvals;

use App\Core\Approvals\EmailApprovals;
use App\Core\Approvals\Models\ApprovalEmailToken;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovals;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * APR-08: approve or reject from the email: single-use links stored
 * hashed, a GET that changes nothing, sign-in required when used,
 * expired, no longer waiting or when the user's role requires two-factor.
 */
class EmailApprovalTest extends TestCase
{
    use BuildsApprovals, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpApprovals();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{approve: string, reject: string} the tokens of the last approval email to $user */
    private function links(User $user): array
    {
        $mail = Mail::sent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo($user->email))->last();
        $this->assertNotNull($mail, 'no approval email');
        $tokens = [];

        foreach ($mail->actions as $action) {
            $this->assertStringStartsWith('http://localhost:3008/approvals/email/', $action['url']);
            $tokens[strtolower($action['label'])] = substr($action['url'], strlen('http://localhost:3008/approvals/email/'));
        }

        $this->assertSame(['approve', 'reject'], array_keys($tokens));

        return $tokens;
    }

    public function test_the_approve_link_shows_a_confirmation_then_approves_once(): void
    {
        $approval = $this->submit($this->approvalGraph());
        $tokens = $this->links($this->managerA);

        // Only hashes are stored; the stored message has no link.
        $this->assertSame(0, $this->inTenant(fn () => ApprovalEmailToken::query()->where('token_hash', $tokens['approve'])->count()));
        $this->assertSame(1, $this->inTenant(fn () => ApprovalEmailToken::query()->where('token_hash', ApprovalEmailToken::hashToken($tokens['approve']))->count()));
        $this->assertFalse($this->inTenant(fn () => NotificationDelivery::query()->where('body', 'like', '%'.$tokens['approve'].'%')->exists()));

        $show = $this->getJson('/api/v1/approvals/email/'.$tokens['approve'])->assertOk();
        $show->assertJsonPath('data.status', 'confirm')->assertJsonPath('data.action', 'approve')->assertJsonPath('data.approval.id', $approval->id);
        $this->assertSame('pending', $this->fresh($approval)->status);

        $this->postJson('/api/v1/approvals/email/'.$tokens['approve'])->assertOk()->assertJsonPath('data.approval_status', 'approved');
        $this->assertSame('approved', $this->fresh($approval)->status);
        $audit = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.approval.approve')->sole());
        $this->assertSame($this->managerA->id, $audit->user_id);
        $this->assertSame('email', $audit->after['via']);

        // Single use: both links are spent.
        $this->getJson('/api/v1/approvals/email/'.$tokens['approve'])->assertOk()->assertJsonPath('data.status', 'sign_in_required')->assertJsonPath('data.reason', 'used');
        $this->postJson('/api/v1/approvals/email/'.$tokens['reject'], ['comment' => 'No'])->assertForbidden()->assertJsonPath('code', 'sign_in_required');
    }

    public function test_a_blocked_email_approval_never_shows_values_of_hidden_fields(): void
    {
        // H3 (RBAC-05): the approve link is refused by the exit rule on `total`, which branch managers can't see.
        $this->inTenant(fn () => FieldRule::create(['role_id' => $this->roles->get('branch_manager')->id, 'resource' => TestRequestType::KEY, 'field' => 'total', 'mode' => 'hidden']));
        $this->submit($this->approvalGraph([], ['exit' => ['field' => 'total', 'op' => 'lt', 'value' => ['amount_minor' => '100', 'currency' => 'KES']]]));
        $tokens = $this->links($this->managerA);

        $response = $this->postJson('/api/v1/approvals/email/'.$tokens['approve'])->assertUnprocessable()->assertJsonPath('code', 'exit_blocked');

        $this->assertSame(['A rule you can’t see was not met.'], $response->json('reasons'));
        $this->assertStringNotContainsString('120,000', $response->getContent());
    }

    public function test_the_reject_link_needs_a_reason(): void
    {
        $approval = $this->submit($this->approvalGraph());
        $tokens = $this->links($this->managerA);

        $this->postJson('/api/v1/approvals/email/'.$tokens['reject'])->assertUnprocessable()->assertJsonPath('code', 'reason_required');
        $this->postJson('/api/v1/approvals/email/'.$tokens['reject'], ['comment' => 'Over budget'])->assertOk();
        $this->assertSame('rejected', $this->fresh($approval)->status);
    }

    public function test_expired_unknown_and_no_longer_waiting_links_require_sign_in(): void
    {
        $approval = $this->submit($this->approvalGraph());
        $tokens = $this->links($this->managerA);

        $this->getJson('/api/v1/approvals/email/'.str_repeat('a', 48))->assertNotFound();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(73));
        $this->getJson('/api/v1/approvals/email/'.$tokens['approve'])->assertJsonPath('data.reason', 'expired');
        $this->postJson('/api/v1/approvals/email/'.$tokens['approve'])->assertForbidden();
        $this->assertSame('pending', $this->fresh($approval)->status);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07T07:00:00Z'));
        $other = $this->submit($this->approvalGraph(), publish: false);
        $fresh = $this->links($this->managerA);
        $this->postJson($this->approvalUrl($other, '/approve'), [], $this->headersFor($this->managerA))->assertOk();
        $this->getJson('/api/v1/approvals/email/'.$fresh['approve'])->assertJsonPath('data.reason', 'not_waiting');
    }

    public function test_a_role_requiring_two_factor_must_sign_in(): void
    {
        $this->inTenant(function () {
            $secure = $this->role('Secure approvers');
            $secure->forceFill(['requires_two_factor' => true])->save();
            $this->assign($this->managerA, $secure, Scope::tenant());
        });
        $approval = $this->submit($this->approvalGraph());
        $tokens = $this->links($this->managerA);

        $this->getJson('/api/v1/approvals/email/'.$tokens['approve'])->assertJsonPath('data.status', 'sign_in_required')
            ->assertJsonPath('data.reason', 'two_factor')->assertJsonPath('data.approval', null);
        $this->postJson('/api/v1/approvals/email/'.$tokens['approve'])->assertForbidden();
        $this->assertSame(ApprovalRequest::PENDING, $this->fresh($approval)->status);
    }

    public function test_a_user_with_two_factor_on_or_a_locked_account_must_sign_in(): void
    {
        $approval = $this->submit($this->approvalGraph());
        $tokens = $this->links($this->managerA);

        $this->inTenant(fn () => $this->managerA->forceFill(['locked_until' => CarbonImmutable::now()->addMinutes(10)])->saveQuietly());
        $this->getJson('/api/v1/approvals/email/'.$tokens['approve'])->assertJsonPath('data.reason', 'locked');
        $this->postJson('/api/v1/approvals/email/'.$tokens['approve'])->assertForbidden();

        // Chosen voluntarily (no role requires it).
        $this->inTenant(fn () => $this->managerA->forceFill(['locked_until' => null, 'two_factor_method' => 'totp', 'two_factor_secret' => 'SECRET', 'two_factor_confirmed_at' => now()])->saveQuietly());
        $this->getJson('/api/v1/approvals/email/'.$tokens['approve'])->assertJsonPath('data.reason', 'two_factor');
        $this->postJson('/api/v1/approvals/email/'.$tokens['approve'])->assertForbidden();
        $this->assertSame(ApprovalRequest::PENDING, $this->fresh($approval)->status);
    }

    public function test_email_endpoints_send_no_referrer_and_have_their_own_rate_limit(): void
    {
        $this->submit($this->approvalGraph());
        $tokens = $this->links($this->managerA);

        $this->getJson('/api/v1/approvals/email/'.$tokens['approve'])->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->getJson('/api/v1/approvals/email/'.str_repeat('b', 48))->assertNotFound()->assertHeader('Referrer-Policy', 'no-referrer');

        for ($i = 2; $i < EmailApprovals::PER_MINUTE; $i++) {
            $this->getJson('/api/v1/approvals/email/'.str_repeat('c', 48));
        }

        $this->getJson('/api/v1/approvals/email/'.$tokens['approve'])->assertStatus(429);
        // Sign-in uses another bucket.
        $this->postJson('/api/v1/auth/sign-in', ['login' => 'nobody@example.com', 'password' => 'x'])->assertStatus(422);
    }

    public function test_nodes_without_email_approval_send_no_links(): void
    {
        $this->submit($this->approvalGraph(['allow_email' => false]));

        $mail = Mail::sent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo($this->managerA->email))->last();
        $this->assertNotNull($mail);
        $this->assertSame([], $mail->actions);
    }
}
