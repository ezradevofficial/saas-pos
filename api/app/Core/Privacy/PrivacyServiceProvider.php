<?php

namespace App\Core\Privacy;

use App\Core\Identity\Models\User;
use App\Core\Rbac\OwnerGuard;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * NFR-08: data subject requests and consent records (docs/privacy.md).
 * Registers core's own subjects (customers are parties; users), the
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
            // copies of what was printed; the party's tax id is cleared.
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
            ));

            // AUTH-13, RBAC-10: a user is anonymised and deactivated, every
            // session ended; never the last active Owner.
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
                after: function (string $userId): array {
                    $user = User::query()->findOrFail($userId);
                    app(OwnerGuard::class)->assertNotLastOwner($user);
                    $user->forceFill([
                        'status' => User::STATUS_DEACTIVATED,
                        'password' => Hash::make(Str::random(64)),
                    ])->saveQuietly();
                    $user->tokens()->delete();

                    return ['user_deactivated', 'sessions_revoked'];
                },
            ));

            return $subjects;
        });
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
