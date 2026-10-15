<?php

namespace Modules\Payroll\Payments;

use InvalidArgumentException;

/** PAY-20: the payment file formats (a bank's own format registers here, from a module or a connector). */
class PaymentFormats
{
    /** @var array<string, PaymentFormat> */
    private array $formats = [];

    public function register(PaymentFormat $format): void
    {
        $this->formats[$format->key()] = $format;
    }

    /** @return array<string, PaymentFormat> */
    public function all(): array
    {
        return $this->formats;
    }

    public function find(string $key): ?PaymentFormat
    {
        return $this->formats[$key] ?? null;
    }

    public function get(string $key): PaymentFormat
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Unknown payment format [{$key}].");
    }
}
