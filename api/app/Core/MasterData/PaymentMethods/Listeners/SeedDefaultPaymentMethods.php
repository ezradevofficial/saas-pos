<?php

namespace App\Core\MasterData\PaymentMethods\Listeners;

use App\Core\MasterData\PaymentMethods\DefaultPaymentMethods;
use App\Core\Tenancy\Events\CompanyCreated;

/** MD-04: a new company gets its default payment methods, in the creating transaction (after its currencies). */
class SeedDefaultPaymentMethods
{
    public function __construct(private readonly DefaultPaymentMethods $defaults) {}

    public function handle(CompanyCreated $event): void
    {
        $this->defaults->seed($event->company);
    }
}
