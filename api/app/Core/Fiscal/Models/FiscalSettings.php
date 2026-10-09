<?php

namespace App\Core\Fiscal\Models;

use App\Core\Audit\Audited;
use App\Core\Audit\Auditor;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's fiscal transmission settings (concept note 7.2): the
 * authority driver, whether transmission is on, the taxpayer PIN (`tin`),
 * branch id and device serial the authority registered, plain `settings`
 * (what initialisation returned, default codes) and `credentials` (keys
 * the authority issued: encrypted, hidden from serialisation and from the
 * audit log; a change is audited as `core.fiscal_settings.credentials_change`
 * naming only the keys). `next_invoice_no` numbers the company's fiscal
 * invoices in order.
 */
class FiscalSettings extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'company_fiscal_settings';

    protected string $auditResource = 'fiscal_settings';

    protected $fillable = ['company_id', 'country', 'driver', 'enabled', 'tin', 'branch_code', 'device_serial', 'settings', 'credentials', 'initialized_at'];

    protected $hidden = ['credentials'];

    protected array $auditHidden = ['credentials', 'next_invoice_no'];

    protected $attributes = ['settings' => '{}', 'enabled' => false];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
            'credentials' => 'encrypted:array',
            'initialized_at' => 'datetime',
            'next_invoice_no' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $marker = function (self $settings): void {
            if (! $settings->wasChanged('credentials') && ! ($settings->wasRecentlyCreated && $settings->credentials)) {
                return;
            }

            $before = $settings->wasRecentlyCreated ? [] : (array) ($settings->getOriginal('credentials') ?? []);
            $after = (array) ($settings->credentials ?? []);
            $changed = array_values(array_filter(array_keys($before + $after), fn (string $key) => ($before[$key] ?? null) !== ($after[$key] ?? null)));
            sort($changed);

            if ($changed !== []) {
                app(Auditor::class)->record('core.fiscal_settings.credentials_change', $settings, null, ['credentials_changed' => $changed]);
            }
        };

        static::created($marker);
        static::updated($marker);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return array<string, true> credential keys that hold a value; never the values */
    public function credentialsSet(): array
    {
        return array_map(fn () => true, array_filter((array) ($this->credentials ?? []), fn ($value) => filled($value)));
    }

    public function credential(string $key): ?string
    {
        $value = ((array) ($this->credentials ?? []))[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    public function setting(string $key): ?string
    {
        $value = ((array) ($this->settings ?? []))[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
