<?php

namespace Tests\Feature\Core\Manual;

use App\Core\Identity\Models\User;
use App\Core\Manual\DemoTenantCommand;
use App\Core\Rbac\RoleTemplates;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\TenantContext;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// `manual:demo-tenant`: the tenant the manual's screenshots are taken in.
class DemoTenantCommandTest extends TestCase
{
    use RefreshTenantDatabase;

    public function test_it_refuses_to_run_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('manual:demo-tenant')->assertExitCode(1);

        $this->app['env'] = 'testing';
        $this->assertSame(0, app(TenantContext::class)->run(DemoTenantCommand::TENANT_ID, fn () => User::query()->count()));
    }

    public function test_it_creates_one_user_per_role_template_and_can_run_again(): void
    {
        $this->artisan('manual:demo-tenant')->assertExitCode(0);
        $this->artisan('manual:demo-tenant')->assertExitCode(0);

        app(TenantContext::class)->run(DemoTenantCommand::TENANT_ID, function () {
            $templates = array_column(app(RoleTemplates::class)->all(), 'key');

            $this->assertSame(count($templates), User::query()->count());
            $this->assertSame(2, Branch::query()->count());

            $owner = User::query()->where('email', 'demo-owner@demo.example.com')->firstOrFail();
            $this->assertTrue(password_verify(DemoTenantCommand::PASSWORD, $owner->getAuthPassword()));
        });

        $this->postJson('/api/v1/auth/sign-in', ['login' => 'demo-owner@demo.example.com', 'password' => DemoTenantCommand::PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }
}
