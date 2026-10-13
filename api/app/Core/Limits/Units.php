<?php

namespace App\Core\Limits;

/**
 * LIM-01: what is metered against the plan. The catalogue sells outlets,
 * devices and employees as quote units (ADR 013); users have no catalogue
 * unit, so a plan limits them only when billing says so.
 */
final class Units
{
    public const OUTLET = 'outlet';

    public const DEVICE = 'device';

    public const USER = 'user';

    public const EMPLOYEE = 'employee';

    public const ALL = [self::OUTLET, self::DEVICE, self::USER, self::EMPLOYEE];

    /** Unit => the catalogue quote unit (QuoteRequest quantities) it is priced by. */
    public const QUOTE_UNITS = [self::OUTLET => 'outlet', self::DEVICE => 'device', self::EMPLOYEE => 'employee'];
}
