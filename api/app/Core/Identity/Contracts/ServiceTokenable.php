<?php

namespace App\Core\Identity\Contracts;

/**
 * A machine that holds core bearer tokens without being a POS device (a
 * module's own device, e.g. HR's biometric attendance terminals,
 * docs/modules/hr.md HR-24). Its token is accepted only while it answers
 * true here (suspended or retired machines are refused), never idles out,
 * and its tenant is the token's own (TEN-01). Routes must still check the
 * token's ability and the tokenable's class themselves.
 */
interface ServiceTokenable
{
    public function acceptsTokens(): bool;
}
