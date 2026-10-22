<?php

namespace App\Core\Workflow\Calendar;

use App\Core\CountryPacks\ResearchStatus;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * A country pack's public holidays (`country-packs/{CODE}/holidays.json`,
 * CP-01): yearly fixed dates (`month`, `day`, optional `effective_from` /
 * `effective_to`) and single dates (`date`). Labels live in
 * lang/{locale}/holidays.php keyed `{CODE}.{key}`, never in the file.
 * Movable or unconfirmed holidays are listed in `todo`, never guessed.
 *
 * CP-01 (owner decision 2026-10-22): a row may carry its research status
 * (`status`, `needs_confirmation`, `confidence`, `researched_on`,
 * `source`: ResearchStatus, checked when `status` is given) and a
 * `sunday_rule` (`next_free_day`: on a Sunday, the first following day
 * that is not itself a public holiday is the holiday instead).
 */
final class HolidayFile
{
    /** How a holiday on a Sunday moves (null: it does not). */
    public const SUNDAY_RULES = ['next_free_day'];

    /** @param array<string, mixed> $data */
    private function __construct(public readonly array $data) {}

    public static function path(string $code): string
    {
        return base_path('country-packs/'.$code.'/holidays.json');
    }

    public static function read(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Holiday file [{$path}] does not exist.");
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException("Holiday file [{$path}] is not valid JSON: {$e->getMessage()}");
        }

        return self::fromArray(is_array($data) ? $data : []);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $validator = Validator::make($data, [
            'code' => ['required', 'string', 'regex:/^[A-Z]{2}\z/'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'sources' => ['present', 'array'],
            'sources.*' => ['string'],
            'todo' => ['present', 'array'],
            'todo.*' => ['string'],
            'holidays' => ['present', 'array'],
            'holidays.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,59}\z/'],
            'holidays.*.month' => ['required_without:holidays.*.date', 'prohibits:holidays.*.date', 'integer', 'between:1,12'],
            'holidays.*.day' => ['required_with:holidays.*.month', 'integer', 'between:1,31'],
            'holidays.*.date' => ['sometimes', 'date_format:Y-m-d'],
            'holidays.*.effective_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'holidays.*.effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'holidays.*.sunday_rule' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::SUNDAY_RULES)],
            'holidays.*.needs_confirmation' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('Invalid holiday file: '.implode(' ', $validator->errors()->all()));
        }

        foreach ($data['holidays'] as $i => $holiday) {
            foreach (array_keys($holiday) as $key) {
                if (! in_array($key, ['key', 'month', 'day', 'date', 'effective_from', 'effective_to', 'sunday_rule', 'needs_confirmation', ...ResearchStatus::FIELDS], true)) {
                    throw new InvalidArgumentException("Invalid holiday file: holidays.{$i}.{$key} is not allowed (labels live in lang/{locale}/holidays.php).");
                }
            }

            if (isset($holiday['month']) && ! checkdate($holiday['month'], $holiday['day'], 2024)) {
                throw new InvalidArgumentException("Invalid holiday file: holidays.{$i} ({$holiday['key']}) is not a calendar day.");
            }

            if (array_key_exists('status', $holiday)) {
                ResearchStatus::check($holiday, "Invalid holiday file: holidays.{$i} ({$holiday['key']})");
            }
        }

        return new self($data);
    }

    public function code(): string
    {
        return $this->data['code'];
    }

    /** @return list<array{key: string, month: ?int, day: ?int, date: ?string, effective_from: ?string, effective_to: ?string, sunday_rule: ?string}> */
    public function holidays(): array
    {
        return array_map(fn (array $h) => [
            'key' => $h['key'],
            'month' => $h['month'] ?? null,
            'day' => $h['day'] ?? null,
            'date' => $h['date'] ?? null,
            'effective_from' => $h['effective_from'] ?? null,
            'effective_to' => $h['effective_to'] ?? null,
            'sunday_rule' => $h['sunday_rule'] ?? null,
        ], array_values($this->data['holidays']));
    }

    /** @return list<string> */
    public function todo(): array
    {
        return array_values($this->data['todo']);
    }
}
