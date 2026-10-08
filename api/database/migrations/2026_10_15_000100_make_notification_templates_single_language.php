<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// NOT-03 (owner decision 2026-10-08): a tenant writes ONE text per event
// type and channel, in its own language, and everyone receives it; only the
// built-in defaults are translated per recipient. Existing per-language
// rows are reduced to one per (tenant, event type, channel): the row in the
// tenant's default language, else the most recently updated one. Runs as
// the schema owner (ADR 002), so it sees every tenant's rows; the table's
// row-level security policy (on tenant_id) is untouched.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            delete from notification_templates t
            using (
                select nt.id, row_number() over (
                    partition by nt.tenant_id, nt.event_type, nt.channel
                    order by (nt.locale = tn.default_locale::text) desc, nt.updated_at desc nulls last, nt.id desc
                ) as rank
                from notification_templates nt
                left join tenants tn on tn.id = nt.tenant_id
            ) ranked
            where ranked.id = t.id and ranked.rank > 1
            SQL);

        DB::statement('alter table notification_templates drop constraint if exists notification_templates_locale_check');
        DB::statement('alter table notification_templates drop constraint if exists notification_templates_tenant_id_event_type_channel_locale_unique');
        DB::statement('alter table notification_templates drop column locale');
        DB::statement('alter table notification_templates add constraint notification_templates_tenant_id_event_type_channel_unique unique (tenant_id, event_type, channel)');
    }

    /** Back to per-language rows: each text becomes the tenant's default language's. */
    public function down(): void
    {
        DB::statement('alter table notification_templates drop constraint if exists notification_templates_tenant_id_event_type_channel_unique');
        DB::statement('alter table notification_templates add column locale char(2)');
        DB::statement(<<<'SQL'
            update notification_templates t
            set locale = coalesce((select tn.default_locale::text from tenants tn where tn.id = t.tenant_id), 'en')
            SQL);
        DB::statement('alter table notification_templates alter column locale set not null');
        DB::statement("alter table notification_templates add constraint notification_templates_locale_check check (locale in ('en', 'fr'))");
        DB::statement('alter table notification_templates add constraint notification_templates_tenant_id_event_type_channel_locale_unique unique (tenant_id, event_type, channel, locale)');
    }
};
