<?php

namespace App\Core\Numbering;

use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Where and when a document is numbered (NUM-01): its company, and its
 * branch, location and device when it has them, and its date. Place
 * tokens print the place's code, or, while it has none, the last six hex
 * digits of its id (the random part of a UUID v7). Date tokens use the
 * branch's time zone, else the company's, else UTC.
 */
final class NumberContext
{
    public readonly CarbonImmutable $at;

    public function __construct(
        public readonly Company $company,
        public readonly ?Branch $branch = null,
        public readonly ?Location $location = null,
        public readonly ?Device $device = null,
        ?DateTimeInterface $at = null,
    ) {
        $this->at = CarbonImmutable::instance($at ?? now())->utc();
    }

    /** A device's place: its location, branch and company (loaded under RLS). */
    public static function forDevice(Device $device, ?DateTimeInterface $at = null): self
    {
        $location = $device->location()->with('branch.company')->firstOrFail();

        return new self($location->branch->company, $location->branch, $location, $device, $at);
    }

    public function timezone(): string
    {
        return $this->branch?->timezone ?: ($this->company->timezone ?: 'UTC');
    }

    public function local(): CarbonImmutable
    {
        return $this->at->setTimezone($this->timezone());
    }

    /** @return array<string, string> place token => code (only the places known) */
    public function placeValues(): array
    {
        $values = [];

        if ($this->branch !== null) {
            $values['BRANCH'] = (string) $this->branch->code;
        }

        if ($this->location !== null) {
            $values['LOCATION'] = $this->location->code ?? self::fallback($this->location->id);
        }

        if ($this->device !== null) {
            $values['DEVICE'] = $this->device->code ?? self::fallback($this->device->id);
        }

        return $values;
    }

    /** @return array<string, string> */
    public function dateValues(): array
    {
        $local = $this->local();

        return ['YYYY' => $local->format('Y'), 'YY' => $local->format('y'), 'MM' => $local->format('m')];
    }

    /** @return array<string, string> */
    public function values(): array
    {
        return [...$this->placeValues(), ...$this->dateValues()];
    }

    public static function fallback(string $id): string
    {
        return strtoupper(substr(str_replace('-', '', $id), -6));
    }
}
