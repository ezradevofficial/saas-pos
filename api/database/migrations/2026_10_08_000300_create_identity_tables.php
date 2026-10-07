<?php

use App\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// AUTH-01, AUTH-02, AUTH-09, AUTH-10: users, verification challenges, login
// events, and the cross-tenant login lookup.
return new class extends Migration
{
    public function up(): void
    {
        // citext is a trusted extension: the schema owner may install it.
        DB::statement('create extension if not exists citext');

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name');
            // Unique indexes see every row whatever RLS hides, so a login
            // identifier is unique across tenants.
            $table->string('email')->nullable()->unique();
            $table->string('phone', 16)->nullable()->unique();
            $table->string('password');
            $table->enum('locale', ['en', 'fr'])->default('en');
            $table->enum('status', ['pending', 'active', 'deactivated'])->default('pending');
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->enum('two_factor_method', ['totp', 'sms'])->nullable();
            $table->integer('failed_sign_ins')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_sign_in_at')->nullable();
            $table->boolean('is_platform_staff')->default(false);
            $table->timestampsTz();
        });

        DB::statement('alter table users alter column email type citext');
        DB::statement('alter table users add constraint users_email_or_phone_check check (email is not null or phone is not null)');
        Rls::enable('users');

        // System table, read before the tenant is known; only Challenges
        // touches it (ADR 002). Codes are stored as HMACs.
        Schema::create('verification_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('purpose', ['verify_contact', 'two_factor', 'password_reset']);
            // Null for TOTP two-factor challenges: the code comes from the app.
            $table->enum('channel', ['email', 'sms'])->nullable();
            $table->string('destination')->nullable();
            $table->char('code_hash', 64);
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('login_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->char('fingerprint', 64);
            $table->boolean('succeeded');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'fingerprint']);
        });

        Rls::enable('login_events');

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });

        // The tenant of a login (email or E.164 phone), across tenants. Runs
        // as the schema owner (BYPASSRLS) and returns nothing but the id.
        DB::unprepared(<<<'SQL'
            create or replace function auth_tenant_for_login(p_login text) returns uuid
            language sql stable security definer
            set search_path = public
            as $$
                select tenant_id from users
                where email = p_login::citext or phone = p_login
                limit 1
            $$;

            revoke all on function auth_tenant_for_login(text) from public;
            SQL);

        $runtimeRole = '"'.str_replace('"', '""', config('database.connections.pgsql.username') ?: 'app').'"';
        DB::statement("grant execute on function auth_tenant_for_login(text) to {$runtimeRole}");
    }

    public function down(): void
    {
        DB::statement('drop function if exists auth_tenant_for_login(text)');

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
        });

        Schema::dropIfExists('login_events');
        Schema::dropIfExists('verification_challenges');
        Schema::dropIfExists('users');
    }
};
