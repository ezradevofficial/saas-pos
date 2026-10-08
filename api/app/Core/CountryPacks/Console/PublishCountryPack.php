<?php

namespace App\Core\CountryPacks\Console;

use App\Core\CountryPacks\CountryPacks;
use App\Core\CountryPacks\PackFile;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Publish a country pack data file (CP-01, CP-03) as the schema owner:
 * `country-packs:publish KE` reads `country-packs/KE/pack.json`;
 * `--file=` publishes another file (staff loading a legal change, CP-02).
 * Idempotent: the same content keeps the current version; changed content
 * becomes the next version. Tenants get the codes when a company is
 * created or when they apply the pack (`tax-codes/apply-pack`).
 */
class PublishCountryPack extends Command
{
    protected $signature = 'country-packs:publish {code : The pack country code, e.g. KE} {--file= : A pack file other than country-packs/{code}/pack.json}';

    protected $description = 'Publish a country pack data file as a new version when its content changed';

    public function handle(CountryPacks $packs): int
    {
        $code = strtoupper((string) $this->argument('code'));

        try {
            $file = PackFile::read($this->option('file') ?: PackFile::path($code));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($file->code() !== $code) {
            $this->components->error("The file is the pack for {$file->code()}, not {$code}.");

            return self::FAILURE;
        }

        [$pack, $created] = $packs->publish($file);

        $created
            ? $this->components->info("Published {$code} version {$pack->version}.")
            : $this->components->info("{$code} is unchanged: version {$pack->version} stays in force.");

        $pending = $pack->summary['needs_confirmation'] ?? [];

        if ($pending !== []) {
            $this->components->warn("{$code}: rates needing confirmation: ".implode(', ', $pending).'.');
        }

        return self::SUCCESS;
    }
}
