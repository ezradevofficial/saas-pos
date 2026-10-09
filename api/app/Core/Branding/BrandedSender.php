<?php

namespace App\Core\Branding;

use Illuminate\Mail\Mailables\Address;

/**
 * BR-06: the sender of the current tenant's notification emails as a
 * mailable address: the tenant's own (name and address) while the address
 * is on one of its verified domains, else null (the platform's sender).
 */
final class BrandedSender
{
    public static function address(): ?Address
    {
        $sender = app(BrandingSettings::class)->sender();

        return $sender === null ? null : new Address($sender->getAddress(), $sender->getName() === '' ? null : $sender->getName());
    }
}
