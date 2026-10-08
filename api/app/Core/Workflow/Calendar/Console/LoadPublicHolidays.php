<?php

namespace App\Core\Workflow\Calendar\Console;

use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Workflow\Calendar\HolidayFile;
use App\Core\Workflow\Calendar\PublicHoliday;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Load a country pack's public holidays (CP-01; WF-09, APR-05) into the
 * global `public_holidays` table as the schema owner (the runtime role
 * may only read it): `country-packs:holidays KE` reads
 * `country-packs/KE/holidays.json`. The country's rows are replaced, so
 * running it again is safe. The file's todo list is printed: those days
 * are not counted as holidays until someone confirms them.
 */
class LoadPublicHolidays extends Command
{
    protected $signature = 'country-packs:holidays {code : The pack country code, e.g. KE} {--file= : A holiday file other than country-packs/{code}/holidays.json}';

    protected $description = "Load a country pack's public holidays";

    public function handle(): int
    {
        $code = strtoupper((string) $this->argument('code'));

        try {
            $file = HolidayFile::read($this->option('file') ?: HolidayFile::path($code));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($file->code() !== $code) {
            $this->components->error("The file holds the holidays of {$file->code()}, not {$code}.");

            return self::FAILURE;
        }

        foreach ($file->holidays() as $holiday) {
            if (trans()->has("holidays.{$code}.{$holiday['key']}", 'en', false) === false) {
                $this->components->warn("Label missing: holidays.{$code}.{$holiday['key']}; the key is shown instead.");
            }
        }

        DB::connection(SyncPermissions::OWNER_CONNECTION)->transaction(function () use ($file, $code) {
            PublicHoliday::on(SyncPermissions::OWNER_CONNECTION)->where('country', $code)->delete();

            foreach ($file->holidays() as $holiday) {
                PublicHoliday::on(SyncPermissions::OWNER_CONNECTION)->create(['country' => $code, ...$holiday]);
            }
        });

        $this->components->info(sprintf('%s: %d public holidays loaded.', $code, count($file->holidays())));

        foreach ($file->todo() as $item) {
            $this->components->warn("{$code} to confirm: {$item}");
        }

        return self::SUCCESS;
    }
}
