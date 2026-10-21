<?php

namespace App\Core\Sso;

/**
 * AUTH-11: who the IdP says signed in, after every check passed: the
 * subject (OIDC `sub`, SAML NameID), the email (lower-cased), a display
 * name, and whether it asserted multi-factor authentication (ADR 023).
 */
final class SsoAssertion
{
    public function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly ?string $name,
        public readonly bool $mfa,
    ) {}

    /** @return array{subject: string, email: string, name: ?string, mfa: bool} */
    public function toArray(): array
    {
        return ['subject' => $this->subject, 'email' => $this->email, 'name' => $this->name, 'mfa' => $this->mfa];
    }

    /** @param array{subject: string, email: string, name: ?string, mfa: bool} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['subject'], $data['email'], $data['name'] ?? null, (bool) $data['mfa']);
    }
}
