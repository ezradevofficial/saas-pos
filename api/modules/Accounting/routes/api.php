<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountController;
use Modules\Accounting\Http\Controllers\BankController;
use Modules\Accounting\Http\Controllers\BudgetController;
use Modules\Accounting\Http\Controllers\DocumentController;
use Modules\Accounting\Http\Controllers\JournalController;
use Modules\Accounting\Http\Controllers\PaymentController;
use Modules\Accounting\Http\Controllers\PeriodController;
use Modules\Accounting\Http\Controllers\PostingRuleController;
use Modules\Accounting\Http\Controllers\ReportController;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\BankAccount;
use Modules\Accounting\Models\BankRule;
use Modules\Accounting\Models\BankStatementLine;
use Modules\Accounting\Models\Budget;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\PostingRule;
use Modules\Accounting\Models\RecurringJournal;

// Accounting module routes (docs/modules/accounting.md), prefix /api/v1,
// loaded by AccountingServiceProvider. RBAC-08: every route is closed
// (403 `module_inactive`) unless the tenant has the module active; the
// Pro features also need `accounting_pro` (decision 1). People's tokens
// only. Records are found under row-level security, so another tenant's
// id is not found (404); each Form Request checks the permission at the
// record's company (RBAC-04).

$records = [
    'acc_account' => Account::class,
    'acc_posting_rule' => PostingRule::class,
    'acc_posting_exception' => PostingException::class,
    'acc_journal' => Journal::class,
    'acc_recurring_journal' => RecurringJournal::class,
    'acc_document' => Document::class,
    'acc_credit_note' => Document::class,
    'acc_payment' => Payment::class,
    'acc_bank_account' => BankAccount::class,
    'acc_bank_statement_line' => BankStatementLine::class,
    'acc_bank_rule' => BankRule::class,
    'acc_budget' => Budget::class,
    'acc_period' => Period::class,
];

foreach ($records as $parameter => $model) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::model($parameter, $model);
}

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:accounting'])->group(function () {
    // ACC-01, ACC-02: the chart and settings.
    Route::get('accounting/chart-templates', [AccountController::class, 'templates']);
    Route::get('companies/{company}/accounting/accounts', [AccountController::class, 'index']);
    Route::post('companies/{company}/accounting/accounts', [AccountController::class, 'store']);
    Route::post('companies/{company}/accounting/chart', [AccountController::class, 'createChart']);
    Route::get('companies/{company}/accounting/settings', [AccountController::class, 'settings']);
    Route::put('companies/{company}/accounting/settings', [AccountController::class, 'updateSettings']);
    Route::get('accounting/accounts/{acc_account}', [AccountController::class, 'show']);
    Route::patch('accounting/accounts/{acc_account}', [AccountController::class, 'update']);
    Route::post('accounting/accounts/{acc_account}/archive', [AccountController::class, 'archive']);
    Route::post('accounting/accounts/{acc_account}/restore', [AccountController::class, 'restore']);

    // ACC-07: posting rules and exceptions.
    Route::get('companies/{company}/accounting/posting-rules', [PostingRuleController::class, 'index']);
    Route::post('companies/{company}/accounting/posting-rules', [PostingRuleController::class, 'store']);
    Route::post('accounting/posting-rules/{acc_posting_rule}/archive', [PostingRuleController::class, 'archive']);
    Route::get('companies/{company}/accounting/posting-exceptions', [PostingRuleController::class, 'exceptions']);
    Route::post('accounting/posting-exceptions/{acc_posting_exception}/retry', [PostingRuleController::class, 'retry']);

    // ACC-03 to ACC-06: journals.
    Route::get('companies/{company}/accounting/journals', [JournalController::class, 'index']);
    Route::post('companies/{company}/accounting/journals', [JournalController::class, 'store']);
    Route::get('accounting/journals/{acc_journal}', [JournalController::class, 'show']);
    Route::patch('accounting/journals/{acc_journal}', [JournalController::class, 'update']);
    Route::post('accounting/journals/{acc_journal}/submit', [JournalController::class, 'submit']);
    Route::post('accounting/journals/{acc_journal}/cancel', [JournalController::class, 'cancel']);
    Route::post('accounting/journals/{acc_journal}/post', [JournalController::class, 'post']);
    Route::post('accounting/journals/{acc_journal}/reverse', [JournalController::class, 'reverse']);
    Route::get('companies/{company}/accounting/recurring-journals', [JournalController::class, 'recurring']);
    Route::post('companies/{company}/accounting/recurring-journals', [JournalController::class, 'storeRecurring']);
    Route::patch('accounting/recurring-journals/{acc_recurring_journal}', [JournalController::class, 'updateRecurring']);
    Route::post('accounting/recurring-journals/{acc_recurring_journal}/archive', [JournalController::class, 'archiveRecurring']);

    // ACC-09, ACC-10, ACC-19: invoices, credit notes, receipts and payments.
    Route::get('companies/{company}/accounting/documents', [DocumentController::class, 'index']);
    Route::post('companies/{company}/accounting/documents', [DocumentController::class, 'store']);
    Route::get('accounting/documents/{acc_document}', [DocumentController::class, 'show']);
    Route::patch('accounting/documents/{acc_document}', [DocumentController::class, 'update']);
    Route::post('accounting/documents/{acc_document}/post', [DocumentController::class, 'post']);
    Route::post('accounting/documents/{acc_document}/cancel', [DocumentController::class, 'cancel']);
    Route::post('accounting/credit-notes/{acc_credit_note}/allocate', [DocumentController::class, 'allocate']);
    Route::get('accounting/documents/{acc_document}/pdf', [DocumentController::class, 'pdf']);
    Route::get('companies/{company}/accounting/payments', [PaymentController::class, 'index']);
    Route::post('companies/{company}/accounting/payments', [PaymentController::class, 'store']);
    Route::get('accounting/payments/{acc_payment}', [PaymentController::class, 'show']);
    Route::post('accounting/payments/{acc_payment}/allocate', [PaymentController::class, 'allocate']);

    // ACC-12 to ACC-14: bank and mobile money accounts and reconciliation.
    Route::get('companies/{company}/accounting/bank-accounts', [BankController::class, 'index']);
    Route::post('companies/{company}/accounting/bank-accounts', [BankController::class, 'store']);
    Route::patch('accounting/bank-accounts/{acc_bank_account}', [BankController::class, 'update']);
    Route::post('accounting/bank-accounts/{acc_bank_account}/archive', [BankController::class, 'archive']);
    Route::post('accounting/bank-accounts/{acc_bank_account}/statements', [BankController::class, 'import']);
    Route::get('accounting/bank-accounts/{acc_bank_account}/reconciliation', [BankController::class, 'reconciliation']);
    Route::post('accounting/bank-accounts/{acc_bank_account}/auto-match', [BankController::class, 'autoMatch']);
    Route::post('accounting/bank-statement-lines/{acc_bank_statement_line}/match', [BankController::class, 'match']);
    Route::post('accounting/bank-statement-lines/{acc_bank_statement_line}/unmatch', [BankController::class, 'unmatch']);
    Route::post('accounting/bank-statement-lines/{acc_bank_statement_line}/post', [BankController::class, 'postLine']);
    Route::get('companies/{company}/accounting/bank-rules', [BankController::class, 'rules']);
    Route::post('companies/{company}/accounting/bank-rules', [BankController::class, 'storeRule']);
    Route::post('accounting/bank-rules/{acc_bank_rule}/archive', [BankController::class, 'archiveRule']);

    // ACC-08: periods.
    Route::get('companies/{company}/accounting/periods', [PeriodController::class, 'index']);
    Route::post('accounting/periods/{acc_period}/close', [PeriodController::class, 'close']);
    Route::post('accounting/periods/{acc_period}/reopen', [PeriodController::class, 'reopen']);

    // ACC-11, ACC-17: reports.
    Route::get('companies/{company}/accounting/reports/trial-balance', [ReportController::class, 'trialBalance']);
    Route::get('companies/{company}/accounting/reports/profit-and-loss', [ReportController::class, 'profitAndLoss']);
    Route::get('companies/{company}/accounting/reports/balance-sheet', [ReportController::class, 'balanceSheet']);
    Route::get('companies/{company}/accounting/reports/cash-flow', [ReportController::class, 'cashFlow']);
    Route::get('companies/{company}/accounting/reports/general-ledger', [ReportController::class, 'generalLedger']);
    Route::get('companies/{company}/accounting/reports/ageing', [ReportController::class, 'ageing']);
    Route::get('companies/{company}/accounting/reports/party-statement', [ReportController::class, 'partyStatement']);
});

// Decision 1: Accounting Pro (revaluation, budgets, consolidation).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:accounting', 'module:accounting_pro'])->group(function () {
    Route::post('accounting/periods/{acc_period}/revalue', [PeriodController::class, 'revalue']);
    Route::get('companies/{company}/accounting/budgets', [BudgetController::class, 'index']);
    Route::post('companies/{company}/accounting/budgets', [BudgetController::class, 'store']);
    Route::get('accounting/budgets/{acc_budget}', [BudgetController::class, 'show']);
    Route::put('accounting/budgets/{acc_budget}/cells', [BudgetController::class, 'setCells']);
    Route::post('accounting/budgets/{acc_budget}/archive', [BudgetController::class, 'archive']);
    Route::get('accounting/budgets/{acc_budget}/vs-actual', [BudgetController::class, 'vsActual']);
    Route::get('accounting/consolidation', [ReportController::class, 'consolidation']);
});
