<?php

namespace Modules\CRM;

use App\Core\Currency\CurrencyUsage;
use App\Core\CustomFields\CustomFieldEntities;
use App\Core\DocumentTemplates\DataSources;
use App\Core\Imports\ImportRegistry;
use App\Core\Layouts\Dashboards\DashboardSources;
use App\Core\Layouts\ListViewCatalogue;
use App\Core\MasterData\RecordUsage;
use App\Core\Notifications\EventTypes;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\CRM\Activities\Reminders;
use Modules\CRM\Console\RemindCommand;
use Modules\CRM\CustomFields\LeadEntity;
use Modules\CRM\CustomFields\OpportunityEntity;
use Modules\CRM\Dashboards\MyTargets;
use Modules\CRM\Dashboards\PipelineValue;
use Modules\CRM\Http\Lists\ActivityList;
use Modules\CRM\Http\Lists\LeadList;
use Modules\CRM\Http\Lists\OpportunityList;
use Modules\CRM\Http\Lists\QuoteList;
use Modules\CRM\Imports\LeadsImporter;
use Modules\CRM\Listeners\SettleQuote;
use Modules\CRM\Printing\QuoteSource;
use Modules\CRM\Quotes\Quotes;
use Modules\CRM\Quotes\QuoteType;
use Modules\CRM\Setup\CompanySetup;
use Modules\CRM\Support\CrmAccess;

/**
 * The CRM and sales module (docs/modules/crm.md). Registered for every
 * tenant, as Laravel boots once for all of them; what a tenant reaches
 * follows its subscription (RBAC-08): the back-office routes carry
 * `module:crm` (403 `module_inactive` otherwise) and the public lead form
 * checks the module of the token's tenant; the `crm.*` permissions grant
 * nothing and system roles get them only once the module is active; its
 * document, number and custom field types, the `sales.quote` template's
 * live data and its dashboard sources are found only then. It works with
 * only the core; POS, Loyalty and Accounting are reached through their
 * read contracts and Accounting through `QuoteConverted`.
 */
class CrmServiceProvider extends ServiceProvider
{
    public const MODULE = 'crm';

    /** CRM-06: public lead form submissions, per IP and per form token. */
    public const FORM_LIMITER = 'crm-forms';

    /** RBAC-01: the module's permission catalogue (`crm.resource.action`). */
    public const PERMISSIONS = [
        'lead' => ['view', 'create', 'edit', 'assign', 'convert', 'archive'],
        'opportunity' => ['view', 'create', 'edit', 'close', 'archive'],
        // CRM-20: `discount` within max_discount_percent; `price` changes a list price; `approve` acts on the flow (WF-08).
        'quote' => ['view', 'create', 'discount', 'price', 'approve', 'send', 'convert', 'cancel'],
        'activity' => ['view', 'manage'],
        'target' => ['view', 'manage'],
        'settings' => ['manage'],
        // CRM-08: see the records of one's teams, or every record at the branch.
        'scope' => ['team', 'branch'],
    ];

    /** LAY-04: the module's lists that may have saved views (the web list ids => their definition). */
    public const LISTS = [
        'crm-leads' => LeadList::class,
        'crm-opportunities' => OpportunityList::class,
        'crm-quotes' => QuoteList::class,
        'crm-activities' => ActivityList::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/crm.php', 'crm');
        // Per request or job: they keep what they read (teams, companies set up).
        $this->app->scoped(CrmAccess::class);
        $this->app->scoped(CompanySetup::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(self::MODULE);
        $this->app->make(PermissionRegistry::class)->register(self::MODULE, self::PERMISSIONS);

        // WF-01, WF-02, CRM-21: quotes run through their flow.
        $this->app->make(DocumentTypeRegistry::class)->register(QuoteType::class);
        Event::listen(WorkflowCompleted::class, SettleQuote::class);
        Event::listen(WorkflowCancelled::class, SettleQuote::class);

        // NUM-01, CRM-17: quote numbers.
        $this->app->make(DocumentNumberTypes::class)->register(new DocumentNumberType(
            Quotes::NUMBER_TYPE, self::MODULE, 'QT-{BRANCH}-{YYYY}-{00001}', NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: 'crm.numbering.quote',
        ));

        // CF-01, CRM-33: custom fields on leads and opportunities (offered while CRM is active, CustomFieldEntity::module()).
        $entities = $this->app->make(CustomFieldEntities::class);
        $entities->register(new LeadEntity);
        $entities->register(new OpportunityEntity);

        // TPL-01, CRM-22: the `sales.quote` template prints CRM's quotes.
        $this->app->make(DataSources::class)->register(new QuoteSource);

        // NOT-02, CRM-27: activity reminders.
        Reminders::register($this->app->make(EventTypes::class));

        // LAY-01, CRM-31: dashboard widgets.
        $dashboards = $this->app->make(DashboardSources::class);
        $dashboards->register(new PipelineValue);
        $dashboards->register(new MyTargets);

        // LAY-04: lists with saved views (field rules applied to the leads' and opportunities' views).
        $lists = $this->app->make(ListViewCatalogue::class);

        foreach (self::LISTS as $key => $definition) {
            $lists->register($key, $definition);
        }

        // IMP-01, CRM-07: the leads importer.
        $this->app->make(ImportRegistry::class)->register(new LeadsImporter);

        // CUR-01: once an amount is stored in a currency, its decimals are locked.
        $this->app->make(CurrencyUsage::class)->register(fn (string $code) => DB::table('crm_quotes')->where('currency', $code)->exists()
            || DB::table('crm_opportunities')->where('currency', $code)->exists()
            || DB::table('crm_leads')->where('currency', $code)->exists()
            || DB::table('crm_targets')->where('currency', $code)->exists());

        // IMP-02: an imported customer or item used by CRM keeps its import from being undone.
        $usage = $this->app->make(RecordUsage::class);
        $usage->register('party', 'crm_opportunity', fn (array $ids) => DB::table('crm_opportunities')->whereIn('party_id', $ids)->distinct()->pluck('party_id')->all());
        $usage->register('party', 'crm_quote', fn (array $ids) => DB::table('crm_quotes')->whereIn('party_id', $ids)->distinct()->pluck('party_id')->all());
        $usage->register('item', 'crm_quote', fn (array $ids) => DB::table('crm_quote_lines')->whereIn('item_id', $ids)->distinct()->pluck('item_id')->all());

        // CRM-06: the public lead form's rate limits, per IP and per form token.
        RateLimiter::for(self::FORM_LIMITER, fn (Request $request) => [
            Limit::perMinute((int) config('crm.forms.per_ip_per_minute', 10))->by(self::FORM_LIMITER.'|ip|'.$request->ip()),
            Limit::perMinute((int) config('crm.forms.per_form_per_minute', 60))->by(self::FORM_LIMITER.'|form|'.hash('sha256', (string) $request->route('token'))),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([RemindCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // CRM-27: activity reminders that are due.
            $schedule->command('crm:remind')->everyMinute()->withoutOverlapping()->onOneServer();
        });

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes/api.php');
        }
    }
}
