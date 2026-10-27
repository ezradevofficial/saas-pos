<?php

namespace Modules\Salon\Support;

use Modules\Salon\Models\Setting;

/** SAL-10, SAL-11, SAL-14, SAL-26: a company's settings with their defaults (no row is the defaults). */
final class Settings
{
    public const DEFAULTS = [
        'slot_minutes' => 15,
        'cancel_window_hours' => 24,
        'late_cancel_fee_percent' => '0',
        'no_show_fee_percent' => '0',
        'deposit_percent' => '0',
        'deposit_online_required' => false,
        'hold_minutes' => 30,
        'reminder_hours' => 24,
        'guest_retention_days' => 365,
        'tip_item_id' => null,
        'fee_item_id' => null,
        'commission_item_code' => null,
    ];

    /** @return array{company_id: string, slot_minutes: int, cancel_window_hours: int, late_cancel_fee_percent: string, no_show_fee_percent: string, deposit_percent: string, deposit_online_required: bool, hold_minutes: int, reminder_hours: int, guest_retention_days: int, tip_item_id: ?string, fee_item_id: ?string, commission_item_code: ?string} */
    public static function for(string $companyId): array
    {
        $row = Setting::query()->where('company_id', $companyId)->first();
        $out = ['company_id' => $companyId];

        foreach (self::DEFAULTS as $key => $default) {
            $value = $row?->{$key} ?? $default;
            $out[$key] = is_string($default) && $key !== 'commission_item_code' ? self::percent($value) : $value;
        }

        return $out;
    }

    /** "15.00" -> "15", "7.50" -> "7.5": a percentage without trailing zeros. */
    private static function percent(mixed $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
