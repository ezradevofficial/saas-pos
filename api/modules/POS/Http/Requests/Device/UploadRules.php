<?php

namespace Modules\POS\Http\Requests\Device;

use App\Core\Currency\Rate;
use Closure;
use Modules\POS\Models\SalePayment;

/**
 * Shapes shared by the till's upload requests (POS-09). Only the shape is
 * checked here; references are checked per record by the upload services,
 * so one bad record never refuses the whole batch.
 */
trait UploadRules
{
    /** UUID v7: records made on a till keep the id the device gave them (ADR 004). */
    protected function deviceId(): array
    {
        return ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i'];
    }

    /** Minor units (ADR 003): a whole number, as digits or an integer, never a float. */
    protected function minor(bool $required = true, bool $positive = false): array
    {
        return [$required ? 'required' : 'nullable', function (string $attribute, mixed $value, Closure $fail) use ($positive) {
            if ($value === null) {
                return;
            }

            if (! (is_int($value) || is_string($value)) || preg_match('/^\d{1,18}$/', (string) $value) !== 1 || ($positive && (string) $value === str_repeat('0', strlen((string) $value)))) {
                $fail(__('pos.validation.minor_units'));
            }
        }];
    }

    /** A quantity: a positive decimal string with up to 6 decimals (numeric(18,6)). */
    protected function quantity(): array
    {
        return ['required', function (string $attribute, mixed $value, Closure $fail) {
            if (! (is_string($value) || is_int($value)) || preg_match('/^\d{1,12}(\.\d{1,6})?$/', (string) $value) !== 1 || preg_match('/[1-9]/', (string) $value) !== 1) {
                $fail(__('pos.validation.quantity'));
            }
        }];
    }

    protected function currencyCode(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'regex:/^[A-Z]{3}$/'];
    }

    /**
     * A tender's rate (CUR-09): 1 base = rate quote.
     *
     * @return array<string, list<mixed>>
     */
    protected function rateRules(string $prefix): array
    {
        return [
            $prefix => ['nullable', 'array'],
            "{$prefix}.rate" => ["required_with:{$prefix}", 'string', 'regex:/^\d{1,10}(\.\d{1,8})?$/'],
            "{$prefix}.base" => ["required_with:{$prefix}", 'string', 'regex:/^[A-Z]{3}$/'],
            "{$prefix}.quote" => ["required_with:{$prefix}", 'string', 'regex:/^[A-Z]{3}$/'],
            "{$prefix}.kind" => ['nullable', 'string', 'in:'.implode(',', Rate::KINDS)],
            "{$prefix}.effective_at" => ['nullable', 'date'],
        ];
    }

    /**
     * Money given or returned: method, currency, amount, amount in the
     * sale currency, the rate used, the provider's reference.
     *
     * @return array<string, list<mixed>>
     */
    protected function paymentRules(string $prefix): array
    {
        return [
            $prefix => ['required', 'array', 'min:1', 'max:20'],
            "{$prefix}.*.id" => $this->deviceId(),
            "{$prefix}.*.payment_method_id" => ['required', 'uuid'],
            "{$prefix}.*.currency" => $this->currencyCode(),
            "{$prefix}.*.amount_minor" => $this->minor(positive: true),
            "{$prefix}.*.amount_in_sale_minor" => $this->minor(),
            ...$this->rateRules("{$prefix}.*.rate"),
            "{$prefix}.*.provider_reference" => ['nullable', 'string', 'max:100'],
            "{$prefix}.*.status" => ['sometimes', 'string', 'in:'.implode(',', SalePayment::STATUSES)],
        ];
    }

    /**
     * AUTH-08: a manager's override (their id and the proof from the till).
     *
     * @return array<string, list<mixed>>
     */
    protected function overrideRules(string $prefix): array
    {
        return [
            $prefix => ['nullable', 'array'],
            "{$prefix}.manager_id" => ["required_with:{$prefix}", 'uuid'],
            "{$prefix}.proof" => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** The device signed in (EnsureDeviceToken has checked the token). */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
