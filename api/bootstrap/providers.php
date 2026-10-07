<?php

use App\Core\Identity\IdentityServiceProvider;
use App\Core\Rbac\RbacServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CoreServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,
    IdentityServiceProvider::class,
    RbacServiceProvider::class,
];
