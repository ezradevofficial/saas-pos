<?php

namespace Tests\Feature\Core\Audit;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUD-03: the database itself refuses edits, and an edit made by bypassing
// that (schema owner, trigger disabled) is caught by hash verification.
//
// The owner connection cannot see rows inside the app connection's test
// transaction, so this class commits its rows (no wrapping transaction: the
// database is still migrated once if needed) and removes them in tearDown
// through the owner connection.
class AuditTamperTest extends TestCase
{
    use RefreshTenantDatabase;

    /** @var list<string> No wrapping transaction: rows are committed. */
    protected $connectionsToTransact = [];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::provision(['name' => 'Tamper Ltd']);
        app(TenantContext::class)->set($this->tenant->id);

        $auditor = app(Auditor::class);
        foreach ([1, 2, 3] as $n) {
            $auditor->record('core.settings.edit', null, ['n' => $n - 1], ['n' => $n]);
        }
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);

        if (isset($this->tenant)) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $this->owner()->transaction(function (Connection $owner) {
            $owner->statement('alter table audit_logs disable trigger audit_logs_append_only');
            $owner->delete('delete from audit_logs where tenant_id = ?', [$this->tenant->id]);
            $owner->statement('alter table audit_logs enable trigger audit_logs_append_only');
            $owner->statement('alter table audit_chain_heads disable trigger audit_chain_heads_forward_only');
            $owner->delete('delete from audit_chain_heads where tenant_id = ?', [$this->tenant->id]);
            $owner->statement('alter table audit_chain_heads enable trigger audit_chain_heads_forward_only');
            $owner->delete('delete from tenants where id = ?', [$this->tenant->id]);
        });
    }

    private function owner(): Connection
    {
        return DB::connection('pgsql_owner');
    }

    public function test_tampering_with_an_entry_is_detected_at_its_seq(): void
    {
        $this->assertNull(app(Auditor::class)->verify($this->tenant->id));

        $this->owner()->transaction(function (Connection $owner) {
            $owner->statement('alter table audit_logs disable trigger audit_logs_append_only');
            $owner->update(
                'update audit_logs set after = ? where tenant_id = ? and seq = 2',
                ['{"n": 200}', $this->tenant->id],
            );
            $owner->statement('alter table audit_logs enable trigger audit_logs_append_only');
        });

        $this->assertSame(2, app(Auditor::class)->verify($this->tenant->id));

        $this->artisan('audit:verify', ['tenant' => $this->tenant->id])
            ->expectsOutputToContain('broken at seq 2')
            ->assertExitCode(1);
    }

    public function test_a_rewritten_hash_is_detected(): void
    {
        $this->owner()->transaction(function (Connection $owner) {
            $owner->statement('alter table audit_logs disable trigger audit_logs_append_only');
            $owner->update(
                "update audit_logs set hash = repeat('a', 64) where tenant_id = ? and seq = 1",
                [$this->tenant->id],
            );
            $owner->statement('alter table audit_logs enable trigger audit_logs_append_only');
        });

        $this->assertSame(1, app(Auditor::class)->verify($this->tenant->id));
    }

    public function test_the_trigger_blocks_even_the_schema_owner(): void
    {
        foreach ([
            fn (Connection $c) => $c->update("update audit_logs set action = 'x' where tenant_id = ?", [$this->tenant->id]),
            fn (Connection $c) => $c->delete('delete from audit_logs where tenant_id = ?', [$this->tenant->id]),
            fn (Connection $c) => $c->statement('truncate audit_logs'),
        ] as $attempt) {
            try {
                $this->owner()->transaction($attempt);
                $this->fail('The owner modified the audit log');
            } catch (QueryException $e) {
                $this->assertStringContainsString('audit log is append-only', $e->getMessage());
            }
        }

        $this->assertSame(3, (int) $this->owner()->selectOne(
            'select count(*) as n from audit_logs where tenant_id = ?', [$this->tenant->id],
        )->n);
        $this->assertNull(app(Auditor::class)->verify($this->tenant->id));
    }

    public function test_the_runtime_role_cannot_move_the_head_back_or_remove_it(): void
    {
        foreach ([
            fn (Connection $c) => $c->update('update audit_chain_heads set seq = 1 where tenant_id = ?', [$this->tenant->id]),
            fn (Connection $c) => $c->update('update audit_chain_heads set seq = seq - 1 where tenant_id = ?', [$this->tenant->id]),
            fn (Connection $c) => $c->update('update audit_chain_heads set tenant_id = ? where tenant_id = ?', [(string) Str::uuid7(), $this->tenant->id]),
            fn (Connection $c) => $c->delete('delete from audit_chain_heads where tenant_id = ?', [$this->tenant->id]),
            fn (Connection $c) => $c->statement('truncate audit_chain_heads'),
        ] as $i => $attempt) {
            try {
                DB::connection(TenantContext::CONNECTION)->transaction($attempt);
                $this->fail("Attempt {$i} changed the chain head");
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/audit chain head only moves forward|permission denied|row-level security/', $e->getMessage());
            }
        }

        // The schema owner is refused too.
        foreach ([
            fn (Connection $c) => $c->update('update audit_chain_heads set seq = 1 where tenant_id = ?', [$this->tenant->id]),
            fn (Connection $c) => $c->delete('delete from audit_chain_heads where tenant_id = ?', [$this->tenant->id]),
        ] as $attempt) {
            try {
                $this->owner()->transaction($attempt);
                $this->fail('The owner moved the chain head back');
            } catch (QueryException $e) {
                $this->assertStringContainsString('audit chain head only moves forward', $e->getMessage());
            }
        }

        $this->assertSame(3, (int) $this->owner()->selectOne(
            'select seq from audit_chain_heads where tenant_id = ?', [$this->tenant->id],
        )->seq);
        $this->assertNull(app(Auditor::class)->verify($this->tenant->id));
    }

    public function test_entries_hidden_above_a_lowered_head_are_detected(): void
    {
        // Bypassing the trigger (owner, trigger disabled): head back to seq 1.
        $this->owner()->transaction(function (Connection $owner) {
            $head = $owner->selectOne('select hash from audit_logs where tenant_id = ? and seq = 1', [$this->tenant->id]);
            $owner->statement('alter table audit_chain_heads disable trigger audit_chain_heads_forward_only');
            $owner->update('update audit_chain_heads set seq = 1, hash = ? where tenant_id = ?', [$head->hash, $this->tenant->id]);
            $owner->statement('alter table audit_chain_heads enable trigger audit_chain_heads_forward_only');
        });

        // The walk up to the head is intact; entries 2 and 3 sit above it.
        $this->assertSame(2, app(Auditor::class)->verify($this->tenant->id));
    }

    public function test_entries_without_a_head_are_detected(): void
    {
        $this->owner()->transaction(function (Connection $owner) {
            $owner->statement('alter table audit_chain_heads disable trigger audit_chain_heads_forward_only');
            $owner->delete('delete from audit_chain_heads where tenant_id = ?', [$this->tenant->id]);
            $owner->statement('alter table audit_chain_heads enable trigger audit_chain_heads_forward_only');
        });

        $this->assertSame(1, app(Auditor::class)->verify($this->tenant->id));
    }
}
