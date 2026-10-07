<?php

namespace Tests;

use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TEN-01: every test starts without a tenant context.
        app(TenantContext::class)->set(null);
    }
}
