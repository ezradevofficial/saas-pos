<?php

namespace App\Core\Observability\Console;

use App\Core\Observability\Keys\KeyRotation;
use Illuminate\Console\Command;

/**
 * NFR-06: re-encrypt every encrypted column with the current APP_KEY after
 * a rotation (docs/security.md, "Rotating APP_KEY"). Values still under a
 * key in APP_PREVIOUS_KEYS are re-encrypted; values already under the
 * current key are left alone, so it is safe to run again and to resume.
 * Prints counts per column only. Exits 1 when any value opens with no key
 * (nothing is changed for those), so the old key must stay in
 * APP_PREVIOUS_KEYS until a run reports no failures.
 */
class ReencryptColumns extends Command
{
    protected $signature = 'app:reencrypt
        {--chunk=500 : Rows read per transaction}
        {--stop-after= : Re-encrypt at most this many values, then stop (run again to go on)}
        {--dry-run : Count what would be re-encrypted, change nothing}';

    protected $description = 'Re-encrypt every encrypted column with the current application key (after an APP_KEY rotation)';

    public function handle(KeyRotation $rotation): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $stopAfter = $this->option('stop-after') === null ? null : max(1, (int) $this->option('stop-after'));
        $dryRun = (bool) $this->option('dry-run');

        if ((array) config('app.previous_keys') === []) {
            $this->warn('APP_PREVIOUS_KEYS is empty: nothing can be re-encrypted, only checked against the current key.');
        }

        $result = $rotation->run($chunk, $dryRun, $stopAfter);
        $totals = ['rotated' => 0, 'current' => 0, 'failed' => 0];
        $rows = [];

        foreach ($result['counts'] as $column => $counts) {
            $rows[] = [$column, $counts['rotated'], $counts['current'], $counts['failed']];
            foreach ($totals as $kind => $total) {
                $totals[$kind] = $total + $counts[$kind];
            }
        }

        $this->table(['Column', $dryRun ? 'To re-encrypt' : 'Re-encrypted', 'Already current', 'Failed'], $rows);
        $this->info(sprintf(
            '%s: %d; already current: %d; failed: %d; tenants: %d.',
            $dryRun ? 'To re-encrypt' : 'Re-encrypted', $totals['rotated'], $totals['current'], $totals['failed'], $result['tenants'],
        ));

        if ($result['stopped']) {
            $this->warn('Stopped after --stop-after values. Run the command again to go on.');
        }

        if ($totals['failed'] > 0) {
            $this->error('Some values open with no key in APP_KEY or APP_PREVIOUS_KEYS. Keep the previous keys and investigate before removing any.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
