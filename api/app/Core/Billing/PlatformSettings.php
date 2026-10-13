<?php

namespace App\Core\Billing;

use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\PlatformDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Platform settings staff change in the console (global
 * `platform_settings`, no tenant data): the grace period and the POS
 * window of dunning (SUB-05) and the trial length (ONB-04). A key never
 * set reads its default from config/billing.php. Changes are audited
 * through PlatformAuditor.
 */
class PlatformSettings
{
    /** key => [min, max] (whole days). */
    public const KEYS = [
        'grace_days' => [0, 90],
        'pos_window_days' => [0, 30],
        'trial_days' => [1, 90],
    ];

    /** @var array<string, int>|null */
    private ?array $loaded = null;

    public function get(string $key): int
    {
        if (! isset(self::KEYS[$key])) {
            throw new InvalidArgumentException("Unknown platform setting [{$key}].");
        }

        return $this->all()[$key];
    }

    /** @return array<string, int> every setting, defaults filled in */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $stored = DB::table('platform_settings')->whereIn('key', array_keys(self::KEYS))->pluck('value', 'key');
        $values = [];

        foreach (array_keys(self::KEYS) as $key) {
            $value = isset($stored[$key]) ? json_decode((string) $stored[$key], true) : null;
            // One trial length: onboarding's setting is the default the staff setting overrides.
            $default = $key === 'trial_days' ? config('onboarding.trial_days', 14) : config("billing.defaults.{$key}");
            $values[$key] = is_int($value) ? $value : (int) $default;
        }

        return $this->loaded = $values;
    }

    /** @param array<string, int> $values */
    public function update(array $values, PlatformUser $by): array
    {
        $before = $this->all();

        PlatformDatabase::transaction(function () use ($values, $by) {
            foreach ($values as $key => $value) {
                [$min, $max] = self::KEYS[$key] ?? throw new InvalidArgumentException("Unknown platform setting [{$key}].");

                if (! is_int($value) || $value < $min || $value > $max) {
                    throw new InvalidArgumentException("Platform setting [{$key}] is out of range.");
                }

                PlatformDatabase::connection()->table('platform_settings')->upsert(
                    [['key' => $key, 'value' => json_encode($value), 'updated_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]],
                    ['key'],
                    ['value', 'updated_by', 'updated_at'],
                );
            }
        });

        $this->loaded = null;
        $after = $this->all();
        app(PlatformAuditor::class)->record('platform.billing_settings.update', null, $before, $after, $by);

        return $after;
    }
}
