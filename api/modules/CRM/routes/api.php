<?php

use App\Core\DocumentTemplates\Models\DocumentShare;
use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use Illuminate\Support\Facades\Route;
use Modules\CRM\CrmServiceProvider;
use Modules\CRM\Http\Controllers\ActivityController;
use Modules\CRM\Http\Controllers\AssignmentRuleController;
use Modules\CRM\Http\Controllers\LeadController;
use Modules\CRM\Http\Controllers\LeadFormController;
use Modules\CRM\Http\Controllers\OpportunityController;
use Modules\CRM\Http\Controllers\OptionController;
use Modules\CRM\Http\Controllers\PipelineController;
use Modules\CRM\Http\Controllers\PublicFormController;
use Modules\CRM\Http\Controllers\QuoteController;
use Modules\CRM\Http\Controllers\ReadController;
use Modules\CRM\Http\Controllers\SettingsController;
use Modules\CRM\Http\Controllers\TargetController;
use Modules\CRM\Http\Controllers\TeamController;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\AssignmentRule;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadForm;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Option;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\Quote;
use Modules\CRM\Models\Stage;
use Modules\CRM\Models\Target;
use Modules\CRM\Models\Team;

// CRM back office (docs/modules/crm.md, API), prefix /api/v1, loaded by
// CrmServiceProvider. RBAC-08: every route is closed (403
// `module_inactive`) unless the tenant has the CRM module active.
// People's tokens only, except the public lead form (CRM-06).

$models = [
    'crm_pipeline' => Pipeline::class,
    'crm_stage' => Stage::class,
    'crm_option' => Option::class,
    'crm_team' => Team::class,
    'crm_assignment_rule' => AssignmentRule::class,
    'crm_lead_form' => LeadForm::class,
    'crm_lead' => Lead::class,
    'crm_opportunity' => Opportunity::class,
    'crm_quote' => Quote::class,
    'crm_activity' => Activity::class,
    'crm_target' => Target::class,
    'document_share' => DocumentShare::class,
];

foreach ($models as $parameter => $model) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::model($parameter, $model);
}

// CRM-06: the public lead form; the 40-character token is the credential (its tenant found by a security-definer function).
Route::post('crm/forms/{token}', PublicFormController::class)->where('token', '[A-Za-z0-9]{1,64}')->middleware('throttle:'.CrmServiceProvider::FORM_LIMITER);

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:crm'])->group(function () {
    // CRM-01: a company's settings.
    Route::get('crm/companies/{company}/settings', [SettingsController::class, 'show']);
    Route::put('crm/companies/{company}/settings', [SettingsController::class, 'update']);

    // CRM-02: pipelines and stages.
    Route::get('crm/pipelines', [PipelineController::class, 'index']);
    Route::post('crm/pipelines', [PipelineController::class, 'store']);
    Route::patch('crm/pipelines/{crm_pipeline}', [PipelineController::class, 'update']);
    Route::post('crm/pipelines/{crm_pipeline}/archive', [PipelineController::class, 'archive']);
    Route::post('crm/pipelines/{crm_pipeline}/restore', [PipelineController::class, 'restore']);
    Route::post('crm/pipelines/{crm_pipeline}/stages', [PipelineController::class, 'addStage']);
    Route::patch('crm/stages/{crm_stage}', [PipelineController::class, 'updateStage']);
    Route::post('crm/stages/{crm_stage}/archive', [PipelineController::class, 'archiveStage']);

    // CRM-03: sources and reasons.
    Route::get('crm/options', [OptionController::class, 'index']);
    Route::post('crm/options', [OptionController::class, 'store']);
    Route::patch('crm/options/{crm_option}', [OptionController::class, 'update']);
    Route::post('crm/options/{crm_option}/archive', [OptionController::class, 'archive']);
    Route::post('crm/options/{crm_option}/restore', [OptionController::class, 'restore']);

    // CRM-04: teams.
    Route::get('crm/teams', [TeamController::class, 'index']);
    Route::post('crm/teams', [TeamController::class, 'store']);
    Route::patch('crm/teams/{crm_team}', [TeamController::class, 'update']);
    Route::post('crm/teams/{crm_team}/archive', [TeamController::class, 'archive']);
    Route::post('crm/teams/{crm_team}/restore', [TeamController::class, 'restore']);

    // CRM-11: assignment rules.
    Route::get('crm/assignment-rules', [AssignmentRuleController::class, 'rules']);
    Route::post('crm/assignment-rules', [AssignmentRuleController::class, 'store']);
    Route::patch('crm/assignment-rules/{crm_assignment_rule}', [AssignmentRuleController::class, 'update']);
    Route::post('crm/assignment-rules/{crm_assignment_rule}/archive', [AssignmentRuleController::class, 'archive']);

    // CRM-06: lead forms.
    Route::get('crm/lead-forms', [LeadFormController::class, 'forms']);
    Route::post('crm/lead-forms', [LeadFormController::class, 'store']);
    Route::patch('crm/lead-forms/{crm_lead_form}', [LeadFormController::class, 'update']);
    Route::post('crm/lead-forms/{crm_lead_form}/rotate', [LeadFormController::class, 'rotate']);
    Route::post('crm/lead-forms/{crm_lead_form}/archive', [LeadFormController::class, 'archive']);

    // CRM-05 to CRM-13: leads.
    Route::get('crm/leads', [LeadController::class, 'index']);
    Route::post('crm/leads', [LeadController::class, 'store']);
    Route::get('crm/leads/{crm_lead}', [LeadController::class, 'show']);
    Route::patch('crm/leads/{crm_lead}', [LeadController::class, 'update']);
    Route::post('crm/leads/{crm_lead}/assign', [LeadController::class, 'assign']);
    Route::post('crm/leads/{crm_lead}/convert', [LeadController::class, 'convert']);
    Route::post('crm/leads/{crm_lead}/disqualify', [LeadController::class, 'disqualify']);
    Route::post('crm/leads/{crm_lead}/archive', [LeadController::class, 'archive']);
    Route::post('crm/leads/{crm_lead}/restore', [LeadController::class, 'restore']);
    Route::get('crm/assignees', [ReadController::class, 'assignees']);

    // CRM-14 to CRM-16: opportunities.
    Route::get('crm/opportunities', [OpportunityController::class, 'index']);
    Route::post('crm/opportunities', [OpportunityController::class, 'store']);
    Route::get('crm/opportunities/{crm_opportunity}', [OpportunityController::class, 'show']);
    Route::patch('crm/opportunities/{crm_opportunity}', [OpportunityController::class, 'update']);
    Route::post('crm/opportunities/{crm_opportunity}/win', [OpportunityController::class, 'win']);
    Route::post('crm/opportunities/{crm_opportunity}/lose', [OpportunityController::class, 'lose']);
    Route::post('crm/opportunities/{crm_opportunity}/reopen', [OpportunityController::class, 'reopen']);
    Route::post('crm/opportunities/{crm_opportunity}/archive', [OpportunityController::class, 'archive']);
    Route::post('crm/opportunities/{crm_opportunity}/restore', [OpportunityController::class, 'restore']);
    Route::get('crm/pipeline-summary', [ReadController::class, 'summary']);

    // CRM-17 to CRM-24: quotes.
    Route::get('crm/quotes', [QuoteController::class, 'index']);
    Route::post('crm/quotes', [QuoteController::class, 'store']);
    Route::post('crm/quotes/preview', [QuoteController::class, 'preview']);
    Route::get('crm/quotes/{crm_quote}', [QuoteController::class, 'show']);
    Route::patch('crm/quotes/{crm_quote}', [QuoteController::class, 'update']);
    Route::post('crm/quotes/{crm_quote}/submit', [QuoteController::class, 'submit']);
    Route::post('crm/quotes/{crm_quote}/accept', [QuoteController::class, 'accept']);
    Route::post('crm/quotes/{crm_quote}/decline', [QuoteController::class, 'decline']);
    Route::post('crm/quotes/{crm_quote}/convert', [QuoteController::class, 'convert']);
    Route::post('crm/quotes/{crm_quote}/cancel', [QuoteController::class, 'cancel']);
    Route::post('crm/quotes/{crm_quote}/send', [QuoteController::class, 'send']);
    Route::post('crm/quotes/{crm_quote}/share', [QuoteController::class, 'share']);
    Route::get('crm/quotes/{crm_quote}/shares', [QuoteController::class, 'shares']);
    Route::post('crm/quotes/{crm_quote}/shares/{document_share}/revoke', [QuoteController::class, 'revokeShare']);
    Route::get('crm/quotes/{crm_quote}/pdf', [QuoteController::class, 'pdf']);

    // CRM-25: the customer 360 view.
    Route::get('crm/customers/{party}/overview', [ReadController::class, 'overview']);

    // CRM-26 to CRM-29: activities and emails.
    Route::get('crm/activities', [ActivityController::class, 'index']);
    Route::post('crm/activities', [ActivityController::class, 'store']);
    Route::patch('crm/activities/{crm_activity}', [ActivityController::class, 'update']);
    Route::post('crm/activities/{crm_activity}/complete', [ActivityController::class, 'complete']);
    Route::post('crm/activities/{crm_activity}/cancel', [ActivityController::class, 'cancel']);
    Route::post('crm/activities/{crm_activity}/reopen', [ActivityController::class, 'reopen']);
    Route::post('crm/emails', [ActivityController::class, 'email']);

    // CRM-30: targets.
    Route::get('crm/targets', [TargetController::class, 'index']);
    Route::post('crm/targets', [TargetController::class, 'store']);
    Route::patch('crm/targets/{crm_target}', [TargetController::class, 'update']);
    Route::post('crm/targets/{crm_target}/archive', [TargetController::class, 'archive']);
});
