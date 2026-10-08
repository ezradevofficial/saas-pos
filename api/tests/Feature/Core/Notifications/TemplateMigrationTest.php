<?php

namespace Tests\Feature\Core\Notifications;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NOT-03 (owner decision 2026-10-08): per-language tenant texts become one
// text per event type and channel, keeping the tenant's default language's
// row, else the most recently updated one. The migration is rolled back and
// re-run as the schema owner inside a transaction that is never committed.
class TemplateMigrationTest extends TestCase
{
    use RefreshTenantDatabase;

    private const OWNER = 'pgsql_owner';

    public function test_per_language_texts_are_reduced_to_one_per_event_and_channel(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_15_000100_make_notification_templates_single_language.php');
        $previous = DB::getDefaultConnection();
        $owner = DB::connection(self::OWNER);
        $owner->beginTransaction();
        DB::setDefaultConnection(self::OWNER);

        try {
            $migration->down();
            $this->assertTrue(Schema::hasColumn('notification_templates', 'locale'));

            $french = (string) Str::uuid7();
            $english = (string) Str::uuid7();
            $owner->table('tenants')->insert([
                ['id' => $french, 'name' => 'Kinshasa', 'default_locale' => 'fr', 'created_at' => now(), 'updated_at' => now()],
                ['id' => $english, 'name' => 'Nairobi', 'default_locale' => 'en', 'created_at' => now(), 'updated_at' => now()],
            ]);
            $row = fn (string $tenant, string $channel, string $locale, string $body, string $updated) => [
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'event_type' => 'core.notification.test',
                'channel' => $channel, 'locale' => $locale, 'body' => $body, 'created_at' => $updated, 'updated_at' => $updated,
            ];
            $owner->table('notification_templates')->insert([
                // The tenant's language wins, even when the other is newer.
                $row($french, 'all', 'fr', 'FR all', '2026-10-01 08:00:00+00'),
                $row($french, 'all', 'en', 'EN all', '2026-10-05 08:00:00+00'),
                // No row in the tenant's language: the newest one.
                $row($french, 'sms', 'en', 'EN sms old', '2026-10-01 08:00:00+00'),
                // A single row is kept whatever its language.
                $row($english, 'email', 'fr', 'FR email', '2026-10-02 08:00:00+00'),
                $row($english, 'all', 'en', 'EN all', '2026-10-01 08:00:00+00'),
                $row($english, 'all', 'fr', 'FR all', '2026-10-07 08:00:00+00'),
            ]);

            $migration->up();

            $this->assertFalse(Schema::hasColumn('notification_templates', 'locale'));
            $kept = $owner->table('notification_templates')->orderBy('channel')->get()
                ->map(fn ($t) => [$t->tenant_id === $french ? 'fr' : 'en', $t->channel, $t->body])->all();
            $this->assertEqualsCanonicalizing([
                ['fr', 'all', 'FR all'], ['fr', 'sms', 'EN sms old'],
                ['en', 'email', 'FR email'], ['en', 'all', 'EN all'],
            ], $kept);

            // One text per tenant, event type and channel from now on.
            $this->expectExceptionMessage('notification_templates_tenant_id_event_type_channel_unique');
            $owner->table('notification_templates')->insert(array_diff_key($row($french, 'all', 'fr', 'Again', '2026-10-08 08:00:00+00'), ['locale' => true]));
        } finally {
            DB::setDefaultConnection($previous);
            $owner->rollBack();
        }
    }

    public function test_row_level_security_still_isolates_the_table(): void
    {
        $policy = DB::connection(self::OWNER)->selectOne(
            "select c.relrowsecurity, c.relforcerowsecurity, p.polname from pg_class c join pg_policy p on p.polrelid = c.oid where c.relname = 'notification_templates'"
        );

        $this->assertSame([true, true, 'tenant_isolation'], [$policy->relrowsecurity, $policy->relforcerowsecurity, $policy->polname]);
    }
}
