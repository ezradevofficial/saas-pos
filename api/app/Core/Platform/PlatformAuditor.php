<?php

namespace App\Core\Platform;

use App\Core\Audit\Auditor;
use App\Core\Platform\Models\PlatformUser;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * AUD-01, AUD-03 for platform staff: every staff action is appended to
 * `platform_audit_logs`, one hash chain for the whole platform, built like
 * the tenant chains (Auditor): hash = sha256(prev_hash . canonical JSON of
 * the hashed fields). Appends are serialised by a transaction-scoped
 * advisory lock; a trigger refuses edits and deletes for every role.
 */
class PlatformAuditor
{
    private const LOCK_KEY = 7_310_420_026;

    private const HASHED = [
        'action', 'after', 'before', 'ip', 'occurred_at', 'platform_user_id',
        'seq', 'subject_id', 'subject_type', 'user_agent',
    ];

    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * Append an entry. The actor is the signed-in staff member unless
     * $actor is given (sign-in, before the guard knows them); console
     * commands leave it null.
     */
    public function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?PlatformUser $actor = null): void
    {
        $actor ??= Auth::guard('platform')->user();
        $console = app()->runningInConsole() && ! app()->runningUnitTests();

        $fields = [
            'action' => $action,
            'subject_type' => $subject === null ? null : Str::afterLast($subject::class, '\\'),
            'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'platform_user_id' => $actor?->getKey(),
            'occurred_at' => CarbonImmutable::now(),
            'ip' => $console ? null : request()->ip(),
            'user_agent' => $console ? null : mb_substr((string) request()->userAgent(), 0, 1024),
        ];

        $db = PlatformDatabase::connection();

        $db->transaction(function () use ($db, $fields) {
            $db->select('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
            $last = $db->table('platform_audit_logs')->orderByDesc('seq')->first(['seq', 'hash']);

            $fields['seq'] = $last === null ? 1 : (int) $last->seq + 1;
            $row = self::normalise($fields);
            $prev = $last->hash ?? Auditor::GENESIS_HASH;

            $db->table('platform_audit_logs')->insert([
                ...$row,
                'id' => (string) Str::uuid7(),
                'before' => self::jsonColumn($row['before']),
                'after' => self::jsonColumn($row['after']),
                'prev_hash' => $prev,
                'hash' => self::hash($prev, $row),
            ]);
        });
    }

    /** First seq at which the chain is broken, or null when intact. */
    public function verify(): ?int
    {
        $expected = 1;
        $prev = Auditor::GENESIS_HASH;

        foreach (PlatformDatabase::connection()->table('platform_audit_logs')->lazyById(500, 'seq') as $entry) {
            $fields = self::normalise((array) $entry);

            if ($fields['seq'] !== $expected || $entry->prev_hash !== $prev || ! hash_equals(self::hash($prev, $fields), $entry->hash)) {
                return $expected;
            }

            $prev = $entry->hash;
            $expected++;
        }

        return null;
    }

    private static function hash(string $prev, array $fields): string
    {
        return hash('sha256', $prev.Auditor::canonicalJson(array_intersect_key($fields, array_flip(self::HASHED))));
    }

    /** One form for values written from PHP and read back from PostgreSQL. */
    private static function normalise(array $fields): array
    {
        $out = [];

        foreach (self::HASHED as $key) {
            $value = $fields[$key] ?? null;
            $out[$key] = match ($key) {
                'seq' => (int) $value,
                'occurred_at' => $value === null ? null : ($value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value))->utc()->format(self::TIMESTAMP_FORMAT),
                'before', 'after' => $value === null ? null : json_decode(is_string($value) ? $value : json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
                'ip' => $value === null || filter_var($value, FILTER_VALIDATE_IP) === false ? null : inet_ntop(inet_pton($value)),
                'platform_user_id' => $value === null ? null : strtolower((string) $value),
                default => $value === null ? null : (string) $value,
            };
        }

        return $out;
    }

    private static function jsonColumn(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
