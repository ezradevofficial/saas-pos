<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADM-04, CP-01..CP-03 (ADR 022): country pack releases. Platform staff
// load a pack document (tax, payroll, hr, holidays, fixed_assets, accounts)
// for one country, the console validates and diffs it, a second staff
// member approves and a third role publishes it. From its effective date
// the latest published release is the pack the app reads, instead of the
// shipped file.
// Global reference data (no tenant_id, ADR 013): the platform role writes
// it; the runtime role reads it (packs are the same for every tenant).
// A trigger keeps a release's content fixed and its status moving forward,
// and the database refuses the same person approving and publishing.
// The platform role also writes what publishing a tax or holidays release
// materialises (country_packs, country_pack_tax_codes, public_holidays),
// which the deploy commands write as the owner today.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_pack_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('country', 2);
            $table->string('kind', 20);
            $table->integer('version');
            $table->string('status', 10)->default('draft');
            $table->jsonb('content');
            $table->char('content_hash', 64);
            $table->date('effective_from');
            $table->text('notes')->nullable();
            // {errors: [...], warnings: [...]} and {base: {...}, added, removed, changed} at load.
            $table->jsonb('validation');
            $table->jsonb('diff');
            $table->foreignUuid('loaded_by')->constrained('platform_users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('platform_users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('platform_users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('platform_users')->restrictOnDelete();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            // Tax and holidays: when the release was written into the pack tables (from its effective date).
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->unique(['country', 'kind', 'version']);
            $table->index(['country', 'kind', 'status', 'effective_from']);
        });

        DB::statement("alter table country_pack_releases add constraint country_pack_releases_kind_check check (kind in ('tax', 'payroll', 'hr', 'holidays', 'fixed_assets', 'accounts'))");
        DB::statement("alter table country_pack_releases add constraint country_pack_releases_status_check check (status in ('draft', 'approved', 'published', 'rejected'))");
        // ADM-04c: two people: whoever loads never approves, whoever approves never publishes.
        DB::statement('alter table country_pack_releases add constraint country_pack_releases_approver_check check (approved_by is null or approved_by <> loaded_by)');
        DB::statement('alter table country_pack_releases add constraint country_pack_releases_publisher_check check (published_by is null or (approved_by is not null and published_by <> approved_by))');

        DB::unprepared(<<<'SQL'
            create or replace function country_pack_releases_guard() returns trigger
            language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'pack releases are never deleted' using errcode = 'insufficient_privilege';
                end if;
                if new.content is distinct from old.content or new.content_hash is distinct from old.content_hash
                    or new.country is distinct from old.country or new.kind is distinct from old.kind
                    or new.version is distinct from old.version or new.effective_from is distinct from old.effective_from
                    or new.loaded_by is distinct from old.loaded_by or new.validation is distinct from old.validation then
                    raise exception 'a pack release never changes: load a new one' using errcode = 'insufficient_privilege';
                end if;
                if new.status is distinct from old.status and not (
                    (old.status = 'draft' and new.status in ('approved', 'rejected'))
                    or (old.status = 'approved' and new.status in ('published', 'rejected'))) then
                    raise exception 'a pack release moves draft, approved, published (or rejected)' using errcode = 'insufficient_privilege';
                end if;
                if old.status in ('published', 'rejected') and (new.approved_by is distinct from old.approved_by
                        or new.published_by is distinct from old.published_by or new.rejected_by is distinct from old.rejected_by) then
                    raise exception 'a published or rejected release keeps its record' using errcode = 'insufficient_privilege';
                end if;
                return new;
            end;
            $$;

            create trigger country_pack_releases_guard
                before update or delete on country_pack_releases
                for each row execute function country_pack_releases_guard();

            create trigger country_pack_releases_no_truncate
                before truncate on country_pack_releases
                for each statement execute function country_pack_releases_guard();
            SQL);

        $runtime = $this->quoted(config('database.connections.pgsql.username') ?: 'app');
        $platform = $this->quoted(config('database.connections.pgsql_platform.username') ?: 'app_platform');

        DB::statement("revoke all on country_pack_releases from {$runtime}");
        DB::statement("grant select on country_pack_releases to {$runtime}");
        DB::statement("grant select, insert, update on country_pack_releases to {$platform}");
        // Publishing tax and holidays releases (ADR 022), on the platform role instead of the owner.
        DB::statement("grant select, insert on country_packs, country_pack_tax_codes to {$platform}");
        DB::statement("grant select, insert, delete on public_holidays to {$platform}");
    }

    public function down(): void
    {
        $platform = $this->quoted(config('database.connections.pgsql_platform.username') ?: 'app_platform');
        DB::statement("revoke all on country_packs, country_pack_tax_codes, public_holidays from {$platform}");
        Schema::dropIfExists('country_pack_releases');
        DB::statement('drop function if exists country_pack_releases_guard()');
    }

    private function quoted(string $role): string
    {
        return '"'.str_replace('"', '""', $role).'"';
    }
};
