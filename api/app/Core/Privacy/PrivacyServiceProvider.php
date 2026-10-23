<?php

namespace App\Core\Privacy;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\CreditLimits\CreditLimitChangeAccess;
use App\Core\MasterData\Parties\Http\Resources\PartyResource;
use App\Core\Privacy\Contracts\ReceivesOptOutReplies;
use App\Core\Privacy\Http\Controllers\OptOutController;
use App\Core\Rbac\OwnerGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * NFR-08: data subject requests and consent records (docs/privacy.md).
 * Registers core's own tables about customers (parties) and users, the
 * routes, and the registry modules add their tables to
 * (`$this->callAfterResolving(PrivacySubjects::class, ...)`).
 */
class PrivacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PrivacySubjects::class, function () {
            $subjects = new PrivacySubjects;

            // MD-03: a customer is a party. Fiscal documents keep their own
            // copies, and POS sales their buyer snapshot (owner decision
            // 2026-10-22), so the party is fully anonymised; the POS's
            // retention rule only makes it wait while fiscal documents are
            // being sent.
            $subjects->register(new SubjectSource(
                subjectType: 'customer',
                table: 'parties',
                match: 'id',
                primary: true,
                personal: [
                    'name' => 'label', 'legal_name' => 'null', 'tax_id' => 'null',
                    'phones' => 'empty_array', 'emails' => 'empty_array', 'addresses' => 'empty_array', 'custom' => 'empty_object',
                ],
                correctable: [
                    'name' => ['sometimes', 'string', 'min:1', 'max:255'],
                    'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                    'tax_id' => ['sometimes', 'nullable', 'string', 'max:50'],
                    'phones' => ['sometimes', 'array', 'max:10'],
                    'phones.*' => ['array:number,label'],
                    'phones.*.number' => ['required', 'string', 'max:30'],
                    'phones.*.label' => ['sometimes', 'nullable', 'string', 'max:40'],
                    'emails' => ['sometimes', 'array', 'max:10'],
                    'emails.*' => ['array:address,label'],
                    'emails.*.address' => ['required', 'string', 'email', 'max:254'],
                    'emails.*.label' => ['sometimes', 'nullable', 'string', 'max:40'],
                ],
                display: ['name'],
                fieldRules: PartyResource::FIELD_RULES,
            ));
            // WF-01: credit limit changes asked for this customer (the party's limit field rules hide their amounts).
            $limits = array_merge(...array_map(fn (string $side) => ["{$side}_limit_minor", "{$side}_limit_currency"], ['current', 'requested']));
            $subjects->register(new SubjectSource(subjectType: 'customer', table: 'credit_limit_changes', match: 'party_id', related: true,
                fieldRules: CreditLimitChangeAccess::FIELD_RULES, fieldColumns: array_fill_keys(CreditLimitChangeAccess::LIMIT_FIELDS, $limits)));

            // AUTH-13, RBAC-10: a user is anonymised and deactivated, every
            // session ended, audited as a deactivation; never the last active
            // Owner, and only by someone who may deactivate them (review M4).
            $subjects->register(new SubjectSource(
                subjectType: 'user',
                table: 'users',
                match: 'id',
                primary: true,
                personal: ['name' => 'label', 'email' => 'anon_email', 'phone' => 'null', 'two_factor_secret' => 'null'],
                correctable: [
                    'name' => ['sometimes', 'string', 'min:1', 'max:255'],
                ],
                display: ['name', 'email'],
                companyColumn: null,
                // Review L2: API-key, app and support principals are not people.
                subjects: fn (Builder $query) => $query->where('users.status', '<>', User::STATUS_SERVICE)->whereNull('users.support_grant_id'),
                after: function (string $userId): array {
                    $user = User::query()->findOrFail($userId);
                    app(OwnerGuard::class)->assertNotLastOwner($user);
                    $before = $user->status;
                    $user->forceFill([
                        'status' => User::STATUS_DEACTIVATED,
                        'password' => Hash::make(Str::random(64)),
                    ])->saveQuietly();
                    $user->tokens()->delete();
                    // As UserController::deactivate records it (AUTH-13).
                    if ($before !== User::STATUS_DEACTIVATED) {
                        app(Auditor::class)->record('core.user.deactivate', $user, ['status' => $before], ['status' => $user->status]);
                    }

                    return ['user_deactivated', 'sessions_revoked'];
                },
            ));
            // Review M5: sign-in history keeps no address or browser; one-time
            // code destinations go (a global table, confined to the tenant).
            $subjects->register(new SubjectSource(subjectType: 'user', table: 'login_events', match: 'user_id', personal: ['ip' => 'null', 'user_agent' => 'null']));
            $subjects->register(new SubjectSource(subjectType: 'user', table: 'verification_challenges', match: 'user_id', personal: ['destination' => 'null'], report: false));
            // RBAC-04: the user's own role assignments and till sign-ins.
            $subjects->register(new SubjectSource(subjectType: 'user', table: 'role_assignments', match: 'user_id', related: true));
            $subjects->register(new SubjectSource(subjectType: 'user', table: 'till_sign_ins', match: 'user_id', related: true));
            $subjects->guardDeletion('user', function (User $actor, string $userId): bool {
                $target = User::query()->find($userId);

                return $target !== null && Gate::forUser($actor)->allows('deactivate', $target);
            });

            return $subjects;
        });
    }

    public function boot(): void
    {
        // NFR-08: inbound SMS and WhatsApp replies opt out through this contract.
        $this->app->bindIf(ReceivesOptOutReplies::class, OptOuts::class);
        RateLimiter::for(OptOutController::LIMITER, fn (Request $request) => Limit::perMinute(30)->by(OptOutController::LIMITER.'|'.$request->ip()));

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
