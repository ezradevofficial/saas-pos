<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Identity\Rules\NotCommonPassword;
use App\Core\Identity\Services\PasswordPolicy;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-02: minimum 8 characters (tenant may raise it), not a common password.
class PasswordPolicyTest extends TestCase
{
    use RefreshTenantDatabase;

    /** @return list<string> */
    private function errors(string $password, ?Tenant $tenant = null): array
    {
        return Validator::make(['password' => $password], ['password' => PasswordPolicy::rules($tenant)])
            ->errors()
            ->get('password');
    }

    public function test_common_passwords_are_rejected(): void
    {
        foreach (['password123', 'qwertyuiop', 'QwertyUiop', 'password'] as $password) {
            $this->assertContains(__('auth.password.common'), $this->errors($password), $password);
        }
    }

    public function test_seven_characters_are_too_short(): void
    {
        $this->assertSame(
            [__('validation.min.string', ['attribute' => 'password', 'min' => 8])],
            $this->errors('kq7vbn3'),
        );
    }

    public function test_a_long_uncommon_password_passes(): void
    {
        $this->assertSame([], $this->errors('violet-harbour-42'));
    }

    public function test_the_tenant_minimum_applies(): void
    {
        $tenant = Tenant::provision(['name' => 'Strict', 'settings' => ['password_min_length' => 12]]);

        $this->assertSame(
            [__('validation.min.string', ['attribute' => 'password', 'min' => 12])],
            $this->errors('kq7vbn3xzpw', $tenant),
        );
        $this->assertSame([], $this->errors('kq7vbn3xzpwr', $tenant));
    }

    public function test_a_stored_minimum_below_eight_never_weakens_the_policy(): void
    {
        foreach ([4, 0, -1, 'abc', null] as $value) {
            $tenant = Tenant::provision(['name' => 'Weak', 'settings' => ['password_min_length' => $value]]);

            $this->assertSame(8, PasswordPolicy::minLength($tenant), var_export($value, true));
            $this->assertNotSame([], $this->errors('kq7vbn3', $tenant));
        }
    }

    public function test_the_rules_have_the_documented_shape(): void
    {
        $rules = PasswordPolicy::rules(null);

        $this->assertSame(['required', 'string', 'min:8'], array_slice($rules, 0, 3));
        $this->assertInstanceOf(NotCommonPassword::class, $rules[3]);
    }
}
