<?php

namespace App\Core\CustomForms;

use App\Core\CustomFields\CustomFieldEntities;
use App\Core\CustomForms\Entities\CustomFormEntity;
use App\Core\CustomForms\Entities\CustomFormLineEntity;
use App\Core\CustomForms\Listeners\SettleCustomFormRecord;
use App\Core\Layouts\Forms\FormCatalogue;
use App\Core\Layouts\Forms\FormDefinition;
use App\Core\MasterData\History\HistoryTypes;
use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * CF-04, CF-05 (docs/adr/012): custom forms. Each of the tenant's form
 * types is registered at run time, for that tenant only, as
 *
 * - custom field entities `custom_form:<key>` and, with lines,
 *   `custom_form_line:<key>` (ADR 011);
 * - a numbered document type `core.custom_form_<key>` (NUM-01), default
 *   format `<KEY>-{YYYY}-{00001}` reset yearly, editable in Numbering;
 * - a workflow document type of the same key (WF-01), when its workflow
 *   is on;
 * - a form layout `custom_form.<key>` (LAY-03).
 */
class CustomFormsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomFormTypes::class);
    }

    public function boot(): void
    {
        $types = fn () => $this->app->make(CustomFormTypes::class)->all();

        $this->app->make(CustomFieldEntities::class)->registerSource(fn () => $types()->flatMap(fn (CustomFormType $type) => [
            new CustomFormEntity($type),
            ...($type->has_lines ? [new CustomFormLineEntity($type)] : []),
        ])->all());

        $this->app->make(DocumentNumberTypes::class)->registerSource(fn () => $types()->map(fn (CustomFormType $type) => new DocumentNumberType(
            $type->number_type, 'core', self::defaultPattern($type->key), NumberFormat::RESET_YEARLY, ['BRANCH', 'LOCATION'], label: $type->name,
        ))->all());

        $this->app->make(DocumentTypeRegistry::class)->registerSource(fn () => $types()
            ->filter(fn (CustomFormType $type) => $type->workflow)
            ->map(fn (CustomFormType $type) => new CustomFormDocumentType($type))->values()->all());

        $this->app->make(FormCatalogue::class)->registerSource(fn () => $types()
            ->filter(fn (CustomFormType $type) => ! $type->isArchived())
            ->map(fn (CustomFormType $type) => self::layout($type))->values()->all());

        Event::listen(WorkflowCompleted::class, SettleCustomFormRecord::class);
        Event::listen(WorkflowCancelled::class, SettleCustomFormRecord::class);

        // MD-07: a form type's history, for whoever manages them.
        $this->app->make(HistoryTypes::class)->register(
            'custom_form_type', CustomFormType::class, null,
            fn ($user) => $this->app->make(CustomFormAccess::class)->manages($user),
        );

        // CF-04: attachment uploads, per user (10 MB each).
        RateLimiter::for('custom-form-files', fn (Request $request) => Limit::perMinute(20)->by('user|'.($request->user()?->id ?? $request->ip())));
    }

    /** NUM-01: `PETTY_CASH-{YYYY}-{00001}` for `petty_cash`. */
    public static function defaultPattern(string $key): string
    {
        return strtoupper($key).'-{YYYY}-{00001}';
    }

    /** LAY-03: the form of a type: its details (the place and header fields), its lines and its attachments. */
    public static function layout(CustomFormType $type): FormDefinition
    {
        $sections = [['id' => 'main', 'title' => 'layouts.forms.custom_form.details', 'columns' => 2]];
        $fields = [['id' => 'place', 'label' => 'core.custom_form.fields.place', 'required' => true, 'has_default' => true, 'wide' => true, 'group' => 'main']];

        if ($type->has_lines) {
            $sections[] = ['id' => 'lines', 'title' => 'layouts.forms.custom_form.lines', 'columns' => 1];
            $fields[] = ['id' => 'lines', 'label' => 'layouts.forms.custom_form.lines', 'wide' => true, 'group' => 'lines'];
        }

        if ($type->attachments) {
            $sections[] = ['id' => 'attachments', 'title' => 'layouts.forms.custom_form.attachments', 'columns' => 1];
            $fields[] = ['id' => 'attachments', 'label' => 'layouts.forms.custom_form.attachments', 'wide' => true, 'group' => 'attachments'];
        }

        return new FormDefinition($type->layoutKey(), $type->entity(), $sections, $fields, $type->name, customGroup: 'main');
    }
}
