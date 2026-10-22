<?php

namespace Tests\Feature\Core\Observability;

use App\Core\Identity\Models\User;
use App\Core\Observability\Keys\EncryptedColumns;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\TestCase;

/**
 * NFR-06: `app:reencrypt` after an APP_KEY rotation. Every encrypted column
 * is found; values under the previous key are re-encrypted with the new one
 * in every tenant (a closed one too, under its own row-level security) and
 * in the platform's tables, without the owner's credentials; the rows read
 * with the new key alone afterwards; a stopped run resumes; a second run
 * changes nothing; only counts are printed.
 */
class KeyRotationTest extends TestCase
{
    use BuildsOrganisation, CreatesPlatformStaff, RefreshTenantDatabase, WithoutOwnerConnection;

    /** The platform's rows roll back too (ADR 013), so other tests' staff never count here. */
    protected array $connectionsToTransact = ['pgsql', PlatformDatabase::CONNECTION];

    /** Platform values under the old key (this test's staff member, and any left by other tests). */
    private int $platformValues = 0;

    /** @var array<string, string> user id => plain secret */
    private array $secrets = [];

    private string $oldKey;

    /** @var array<string, string> user id => tenant id */
    private array $tenantsOf = [];

    private PlatformUser $staffMember;

    private string $staffSecret;

    /** @var array{0: mixed, 1: mixed} */
    private array $ownerLogin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
        $this->oldKey = (string) config('app.key');
        $this->ownerLogin = [config('database.connections.pgsql_owner.username'), config('database.connections.pgsql_owner.password')];

        // Three tenant values under the old key: two in Acme, one in a closed tenant.
        $colleague = $this->inTenant(fn () => $this->colleague($this->owner, ['name' => 'Wanjiku']));
        $other = $this->otherTenant()['user'];
        $this->asTenant($other->tenant_id, fn () => DB::table('tenants')->where('id', $other->tenant_id)->update(['status' => 'closed']));
        $this->assertSame('closed', $this->asTenant($other->tenant_id, fn () => DB::table('tenants')->value('status')));

        foreach ([[$this->owner, 'OWNERSECRETAAAAA'], [$colleague, 'COLLEAGUEBBBBBBB'], [$other, 'OTHERTENANTCCCCC']] as [$user, $secret]) {
            $this->asTenant($user->tenant_id, fn () => User::query()->findOrFail($user->id)->forceFill(['two_factor_secret' => $secret])->save());
            $this->secrets[$user->id] = $secret;
            $this->tenantsOf[$user->id] = $user->tenant_id;
        }

        $this->staffMember = $this->staff();
        $this->staffSecret = (string) $this->staffMember->two_factor_secret;
        $this->platformValues = PlatformUser::query()->whereNotNull('two_factor_secret')->count();
    }

    private function total(): int
    {
        return 3 + $this->platformValues;
    }

    private function restoreOwnerConnection(): void
    {
        DB::purge('pgsql_owner');
        config(['database.connections.pgsql_owner.username' => $this->ownerLogin[0], 'database.connections.pgsql_owner.password' => $this->ownerLogin[1]]);
    }

    private function useKeys(string $key, array $previous): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private function rotate(): string
    {
        $new = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));
        $this->useKeys($new, [$this->oldKey]);

        return $new;
    }

    /** @return array<string, string> user id => stored ciphertext, in each tenant */
    private function stored(): array
    {
        $stored = [];
        foreach (array_keys($this->secrets) as $id) {
            $tenant = $this->tenantOf($id);
            $stored[$id] = (string) $this->asTenant($tenant, fn () => DB::table('users')->where('id', $id)->value('two_factor_secret'));
        }

        return $stored;
    }

    private function tenantOf(string $userId): string
    {
        return $userId === $this->owner->id ? $this->owner->tenant_id : (string) collect($this->tenantsOf)->get($userId);
    }

    private function assertReadableWithTheNewKeyAlone(string $new): void
    {
        $this->useKeys($new, []);

        foreach ($this->secrets as $id => $secret) {
            $this->assertSame($secret, $this->asTenant($this->tenantsOf[$id], fn () => User::query()->findOrFail($id)->two_factor_secret));
        }

        $this->assertSame($this->staffSecret, PlatformUser::query()->findOrFail($this->staffMember->id)->two_factor_secret);
    }

    public function test_every_encrypted_column_is_found(): void
    {
        $found = [];
        foreach (app(EncryptedColumns::class)->all() as $table) {
            foreach ($table['columns'] as $column) {
                $found[] = "{$table['table']}.{$column}".($table['tenant'] ? '' : ' (platform)');
            }
        }

        foreach (['users.two_factor_secret', 'payment_methods.secrets', 'company_fiscal_settings.credentials', 'webhook_subscriptions.previous_secret',
            'hr_employee_identifiers.value', 'hr_employees.medical_notes', 'ecom_orders.tracking_token_secret', 'platform_users.two_factor_secret (platform)',
            'procurement_tenders.seal_secret', 'procurement_tenders.public_token_secret'] as $expected) {
            $this->assertContains($expected, $found);
        }
    }

    public function test_only_reviewed_files_encrypt_by_hand(): void
    {
        $callers = [];
        foreach ([app_path(), base_path('modules')] as $root) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/tests/')) {
                    continue;
                }

                // Code only: comments that mention Crypt do not count.
                $code = implode('', array_map(
                    fn ($token) => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token,
                    token_get_all((string) file_get_contents($file->getPathname())),
                ));

                if (preg_match('/Crypt::|(?<![\w>:$])(en|de)crypt\(/', $code) === 1) {
                    $callers[] = substr($file->getPathname(), strlen(base_path()) + 1);
                }
            }
        }
        sort($callers);
        $reviewed = array_keys(EncryptedColumns::CRYPT_CALLERS);
        sort($reviewed);

        $this->assertSame($reviewed, $callers, 'A file encrypts with the app key by hand: add its stored columns to EncryptedColumns::MANUAL and the file to CRYPT_CALLERS.');
    }

    public function test_a_rotated_key_re_encrypts_every_tenant_and_the_platform_without_the_owner(): void
    {
        $before = $this->stored();
        $new = $this->rotate();
        $this->withoutOwnerConnection();

        Artisan::call('app:reencrypt', ['--chunk' => 1]);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/users\.two_factor_secret\s*\|\s*3\s*\|\s*0\s*\|\s*0/', $output);
        $this->assertMatchesRegularExpression('/platform_users\.two_factor_secret\s*\|\s*'.$this->platformValues.'\s*\|\s*0\s*\|\s*0/', $output);
        $this->assertStringContainsString("Re-encrypted: {$this->total()}; already current: 0; failed: 0; tenants: 2.", $output);
        // Counts only: no secret, no ciphertext, no row id.
        foreach ([...array_values($this->secrets), $this->staffSecret, ...array_values($before), ...array_keys($before)] as $value) {
            $this->assertStringNotContainsString($value, $output);
        }

        $this->restoreOwnerConnection();
        $this->assertNotSame($before, $this->stored());
        $this->assertReadableWithTheNewKeyAlone($new);
    }

    public function test_a_stopped_run_resumes_and_a_second_run_changes_nothing(): void
    {
        $new = $this->rotate();

        $this->artisan('app:reencrypt', ['--stop-after' => 2, '--chunk' => 1])->assertSuccessful()
            ->expectsOutputToContain('Re-encrypted: 2;')->expectsOutputToContain('Run the command again to go on');

        // Resumed: the two done are now current, the others are re-encrypted.
        $rest = $this->total() - 2;
        $this->artisan('app:reencrypt')->assertSuccessful()->expectsOutputToContain("Re-encrypted: {$rest}; already current: 2; failed: 0");
        $after = $this->stored();

        $this->artisan('app:reencrypt')->assertSuccessful()->expectsOutputToContain("Re-encrypted: 0; already current: {$this->total()}; failed: 0");
        $this->assertSame($after, $this->stored(), 'a second run writes nothing');

        $this->assertReadableWithTheNewKeyAlone($new);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $before = $this->stored();
        $this->rotate();

        $this->artisan('app:reencrypt', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain("To re-encrypt: {$this->total()};");
        $this->assertSame($before, $this->stored());
    }

    public function test_a_value_no_key_opens_fails_the_run_and_is_left_alone(): void
    {
        $stranger = new Encrypter(Encrypter::generateKey((string) config('app.cipher')), (string) config('app.cipher'));
        $foreign = $stranger->encryptString('UNKNOWNKEYDDDDDD');
        $this->inTenant(fn () => DB::table('users')->where('id', $this->owner->id)->update(['two_factor_secret' => $foreign]));
        unset($this->secrets[$this->owner->id]);
        $new = $this->rotate();

        $rotated = $this->total() - 1;
        $this->artisan('app:reencrypt')->assertFailed()->expectsOutputToContain("Re-encrypted: {$rotated}; already current: 0; failed: 1");
        $this->assertSame($foreign, $this->inTenant(fn () => DB::table('users')->where('id', $this->owner->id)->value('two_factor_secret')));
        $this->assertReadableWithTheNewKeyAlone($new);
    }
}
