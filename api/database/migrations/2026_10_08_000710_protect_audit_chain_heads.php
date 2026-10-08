<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// AUD-03: a chain head only moves forward. Lowering its seq would hide the
// entries above it from verification; moving it to another tenant or
// deleting it would reset a chain. The trigger binds every role, the schema
// owner included; Auditor::verify() also flags entries beyond the head.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function audit_chain_heads_forward_only() returns trigger
            language plpgsql as $$
            begin
                if tg_op = 'UPDATE'
                    and new.tenant_id = old.tenant_id
                    and new.seq >= old.seq then
                    return new;
                end if;

                raise exception 'audit chain head only moves forward' using errcode = 'insufficient_privilege';
            end;
            $$;

            create trigger audit_chain_heads_forward_only
                before update or delete on audit_chain_heads
                for each row execute function audit_chain_heads_forward_only();

            create trigger audit_chain_heads_no_truncate
                before truncate on audit_chain_heads
                for each statement execute function audit_chain_heads_forward_only();
            SQL);

        // Rows are inserted and moved forward by the runtime role, never removed.
        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("revoke delete, truncate on audit_chain_heads from {$runtimeRole}");
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists audit_chain_heads_no_truncate on audit_chain_heads;
            drop trigger if exists audit_chain_heads_forward_only on audit_chain_heads;
            drop function if exists audit_chain_heads_forward_only();
            SQL);
    }
};
