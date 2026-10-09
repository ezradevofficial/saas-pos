<?php

namespace App\Core\Payments\Daraja;

use App\Core\MasterData\PaymentMethods\PaymentMethod;

/**
 * A company's Daraja app and shortcodes, read from its M-Pesa payment
 * method (MD-04: settings plain, secrets encrypted). Never serialised or
 * logged.
 */
final class DarajaCredentials
{
    public function __construct(
        public readonly string $methodId,
        public readonly string $tenantId,
        public readonly string $shortcode,
        public readonly string $transactionType,
        public readonly ?string $tillNumber,
        public readonly ?string $b2cShortcode,
        public readonly ?string $initiatorName,
        public readonly string $consumerKey,
        public readonly string $consumerSecret,
        public readonly string $passkey,
        public readonly ?string $securityCredential,
    ) {}

    public static function of(PaymentMethod $method): self
    {
        $settings = (array) ($method->settings ?? []);
        $secrets = (array) ($method->secrets ?? []);

        return new self(
            methodId: (string) $method->id,
            tenantId: (string) $method->tenant_id,
            shortcode: (string) ($settings['shortcode'] ?? ''),
            transactionType: ($settings['transaction_type'] ?? 'paybill') === 'till' ? 'till' : 'paybill',
            tillNumber: filled($settings['till_number'] ?? null) ? (string) $settings['till_number'] : null,
            b2cShortcode: filled($settings['b2c_shortcode'] ?? null) ? (string) $settings['b2c_shortcode'] : null,
            initiatorName: filled($settings['initiator_name'] ?? null) ? (string) $settings['initiator_name'] : null,
            consumerKey: (string) ($secrets['consumer_key'] ?? ''),
            consumerSecret: (string) ($secrets['consumer_secret'] ?? ''),
            passkey: (string) ($secrets['passkey'] ?? ''),
            securityCredential: filled($secrets['security_credential'] ?? null) ? (string) $secrets['security_credential'] : null,
        );
    }

    /** The shortcode customers pay: the Till for buy goods, else the Paybill. */
    public function payTo(): string
    {
        return $this->transactionType === 'till' && $this->tillNumber !== null ? $this->tillNumber : $this->shortcode;
    }

    /** Whether refunds (B2C) and transaction status checks can be made. */
    public function hasInitiator(): bool
    {
        return $this->initiatorName !== null && $this->securityCredential !== null;
    }
}
