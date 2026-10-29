<?php

namespace App\Core\Manual\Demo\Sections;

use App\Core\Manual\Demo\DemoData;

/**
 * Approvals are created by a queued listener, so a document that was just submitted
 * may not have its approval yet. The section approves as a role until the document
 * reaches the wanted state, waiting a moment between tries. A queue worker must be running.
 */
trait WaitsForApproval
{
    /** The id of a sample item by the last two digits of its code (05 is rice, 06 sugar, 07 maize flour, 08 cooking oil, 09 bar soap...). */
    private function sample(DemoData $demo, string $number): string
    {
        // The sample set's code prefix differs between runs (SAMPLE-CMDI-05, SAMPLE-GPRJ-05...), the number does not.
        return collect($demo->api->rows('items', ['search' => 'SAMPLE-']))->first(fn ($item) => str_ends_with($item['code'], '-'.$number))['id'];
    }

    /** @param  callable(): bool  $done  true once the document is in the wanted state */
    private function approveUntil(DemoData $demo, string $roleKey, callable $done, int $tries = 20): bool
    {
        for ($i = 0; $i < $tries; $i++) {
            $demo->approveAll($roleKey);

            if ($done()) {
                return true;
            }

            sleep(1);
        }

        return false;
    }
}
