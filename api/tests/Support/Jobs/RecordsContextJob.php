<?php

namespace Tests\Support\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Review focus 3: a job that may set a tenant and audit context directly
 * (as a careless job would) and records what it found when it started.
 */
class RecordsContextJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @var list<array{name: string, tenant: ?string, db_tenant: string, audit_user: ?string, device: ?string}> */
    public static array $seen = [];

    public function __construct(
        public string $name,
        public ?string $setTenantId = null,
    ) {}

    public function handle(TenantContext $tenants, AuditContext $audit): void
    {
        self::$seen[] = [
            'name' => $this->name,
            'tenant' => $tenants->id(),
            'db_tenant' => (string) DB::selectOne("select current_setting('app.tenant_id', true) as t")->t,
            'audit_user' => $audit->userId(),
            'device' => $audit->deviceId(),
        ];

        if ($this->setTenantId !== null) {
            $tenants->set($this->setTenantId);
            $audit->setUserId('0199c2a4-0000-7000-8000-000000000001')->setDeviceId('0199c2a4-0000-7000-8000-000000000002');
        }
    }
}
