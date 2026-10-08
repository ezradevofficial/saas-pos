<?php

namespace App\Core\MasterData\Parties;

use App\Core\MasterData\Sharing\MasterDataSharing;

/**
 * MD-01, TEN-08: a party's roles and the master data type whose sharing
 * mode each follows. A contact follows customers. A party is kept per
 * company when any of its roles' types is per_company, else it is shared.
 */
final class PartyRoles
{
    public const CUSTOMER = 'customer';

    public const SUPPLIER = 'supplier';

    public const CONTACT = 'contact';

    public const EMPLOYEE_LINK = 'employee_link';

    public const ALL = [self::CUSTOMER, self::SUPPLIER, self::CONTACT, self::EMPLOYEE_LINK];

    public const DATA_TYPES = [
        self::CUSTOMER => 'customers',
        self::SUPPLIER => 'suppliers',
        self::CONTACT => 'customers',
        self::EMPLOYEE_LINK => 'employees',
    ];

    /** Every data type a party can follow (the update path locks them all, TEN-08). */
    public const PARTY_DATA_TYPES = ['customers', 'suppliers', 'employees'];

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    public static function dataTypes(array $roles): array
    {
        return array_values(array_unique(array_map(fn (string $role) => self::DATA_TYPES[$role], array_intersect($roles, self::ALL))));
    }

    /** @return list<string> roles whose data type is $dataType */
    public static function of(string $dataType): array
    {
        return array_keys(array_filter(self::DATA_TYPES, fn (string $type) => $type === $dataType));
    }

    /** @param list<string> $roles */
    public static function perCompany(array $roles, MasterDataSharing $sharing): bool
    {
        foreach (self::dataTypes($roles) as $type) {
            if (! $sharing->isShared($type)) {
                return true;
            }
        }

        return false;
    }
}
