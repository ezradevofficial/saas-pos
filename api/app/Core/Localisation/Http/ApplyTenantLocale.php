<?php

namespace App\Core\Localisation\Http;

/**
 * SetLocale again, after authentication: the token has now set the tenant,
 * so the tenant's default language applies when Accept-Language does not
 * choose one (L10N-01). A separate class because the router drops a
 * middleware listed twice on one route.
 */
class ApplyTenantLocale extends SetLocale {}
