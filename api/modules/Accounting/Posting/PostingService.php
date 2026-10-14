<?php

namespace Modules\Accounting\Posting;

use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Ledger\LineInput;
use Modules\Accounting\Models\AccountingSettings;
use Modules\Accounting\Models\BankAccount;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\PostingRule;
use Throwable;

/**
 * ACC-07: posts a source document through its company's posting rule.
 *
 * - Idempotent: the journal is keyed by (company, source type, source id)
 *   (a unique index); posting the same source again returns the journal it
 *   already has, even when two posts race.
 * - Configuration, not code: each rule line takes one amount field of the
 *   source to an account on a side (a negative amount to the other side),
 *   with the source's dimensions and, where the line says so, its party.
 * - Never lost: a source that cannot post (no rule, a closed period, an
 *   archived account, a missing rate, unbalanced amounts) is kept as a
 *   posting exception with the reason, and retried (retry()).
 * - Base amounts use the source's own rate when it gives one (CUR-04),
 *   else the core rate on its date; for a source in another currency only,
 *   a rounding difference of at most one minor unit per line goes to the
 *   rounding account (a base-currency source must balance exactly).
 * - Stage 7B (ACC-24): a rule line `prefix.*` posts every field
 *   `prefix.{key}` of the source, to the account its map names for the
 *   key (or the payment method's linked account), else its default; a
 *   source in a foreign currency needs Accounting Pro, else it waits as an
 *   exception (`accounting_pro_required`); refunds and voids fall back to
 *   the sale rule with its sides swapped (`accounting.rule_fallbacks`).
 */
class PostingService
{
    public function __construct(
        private readonly JournalPoster $poster,
        private readonly ModuleRegistry $modules,
    ) {}

    public function post(SourceDocument $source, ?User $by = null): PostingResult
    {
        if ($existing = $this->existing($source)) {
            $this->resolve($source, $existing);

            return new PostingResult($existing, created: false);
        }

        try {
            $journal = DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->journal($source, $by));
        } catch (UniqueConstraintViolationException) {
            // Another post of the same source won the race: answer with its journal.
            $existing = $this->existing($source) ?? throw LedgerError::make('posting_conflict');

            return new PostingResult($existing, created: false);
        } catch (ApiException $e) {
            return new PostingResult(null, created: false, exception: $this->keep($source, $e->errorCode, $e->getMessage()));
        } catch (Throwable $e) {
            // Never lost: whatever went wrong, the source is kept to retry, and the fault is logged.
            report($e);

            return new PostingResult(null, created: false, exception: $this->keep($source, 'posting_failed', __('accounting.errors.posting_failed')));
        }

        $this->resolve($source, $journal);

        return new PostingResult($journal, created: true);
    }

    /** Try a kept source again (after a rule, an account or a period was fixed). */
    public function retry(PostingException $exception, ?User $by = null): PostingResult
    {
        return $this->post(SourceDocument::fromArray($exception->payload), $by);
    }

    private function journal(SourceDocument $source, ?User $by): Journal
    {
        $company = Company::query()->find($source->companyId) ?? throw LedgerError::make('company_unknown');

        // Decision 1: foreign-currency postings need Accounting Pro; with Essentials the source waits as an exception.
        if ($source->currency !== $company->base_currency && ! $this->modules->isActive(AccountingServiceProvider::PRO)) {
            throw LedgerError::make('accounting_pro_required');
        }

        [$rule, $reverse] = $this->rule($company, $source);
        $dimensions = array_intersect_key($source->dimensions, array_flip(['branch_id', 'cost_centre_id', 'department_id', 'project_id']));
        $lines = [];

        foreach ($rule->lines as $line) {
            $side = (string) $line['side'];
            $side = $reverse ? ($side === LineInput::DEBIT ? LineInput::CREDIT : LineInput::DEBIT) : $side;
            $lineDimensions = [...$dimensions, 'party_id' => ($line['party'] ?? false) ? ($source->dimensions['party_id'] ?? null) : null];

            foreach ($this->fields($source, (string) $line['field']) as $field => $key) {
                $amount = $source->amount($field);

                if ($amount->isZero()) {
                    continue;
                }

                $account = $this->account($company, $line, $field, $key);
                $foreign = $source->foreign[$field] ?? null;
                $lines[] = is_array($foreign)
                    ? $this->foreignLine($company, $account, $side, $amount, $foreign, $source, $lineDimensions)
                    : LineInput::signed($account, $side, $amount, $source->fx, $source->reference, $lineDimensions);
            }
        }

        if ($lines === []) {
            throw LedgerError::make('journal_empty');
        }

        if ($source->foreign !== []) {
            $lines = $this->balanceFx($company, $source, $lines, $dimensions);
        }

        return $this->poster->record($company, [
            'date' => $this->localDate($company, $source->date),
            'kind' => 'system',
            'source_type' => $source->sourceType,
            'source_id' => $source->sourceId,
            'source_link' => $source->link,
            'reference' => $source->reference === null ? null : mb_substr($source->reference, 0, 80),
            'memo' => $source->memo,
            'created_by' => $by?->id,
        ], $lines, $by, rounding: $source->currency !== $company->base_currency || $source->foreign !== []);
    }

    /**
     * Review #1: a field paid in another currency posts that amount in that
     * currency, with its base amount as the source worked it out (the rate
     * the payment used). Its sign follows the field's value in the source
     * currency.
     *
     * @param  array{currency: string, amount_minor: string, base_minor: string, fx?: ?array}  $foreign
     */
    private function foreignLine(Company $company, string $account, string $side, Money $amount, array $foreign, SourceDocument $source, array $dimensions): LineInput
    {
        $sign = $amount->isNegative() ? '-' : '';
        $fx = is_array($foreign['fx'] ?? null) ? new FxSnapshot((string) $foreign['fx']['rate'], (string) $foreign['fx']['base'], (string) $foreign['fx']['quote'], $foreign['fx']['kind'] ?? null, null) : null;

        return LineInput::signed($account, $side, Money::ofMinor($sign.ltrim((string) $foreign['amount_minor'], '-'), (string) $foreign['currency']), $fx,
            $source->reference, $dimensions, Money::ofMinor($sign.ltrim((string) $foreign['base_minor'], '-'), (string) $company->base_currency));
    }

    /**
     * Review #1: when tenders post in their own currencies, the base amounts
     * no longer add up to the sale's exactly: what the payments' rates make
     * them differ by goes to exchange gains or losses (beyond a minor unit
     * per line, which the rounding account takes).
     *
     * @param  list<LineInput>  $lines
     * @return list<LineInput>
     */
    private function balanceFx(Company $company, SourceDocument $source, array $lines, array $dimensions): array
    {
        $base = (string) $company->base_currency;
        $difference = BigInteger::zero();

        foreach ($lines as $line) {
            $value = match (true) {
                $line->base !== null => $line->base,
                $line->amount->currency() === $base => $line->amount,
                $line->fx !== null => $line->fx->convert($line->amount),
                default => null,
            };

            if ($value === null) {
                return $lines;
            }

            $difference = $line->side === LineInput::DEBIT ? $difference->plus($value->minor()) : $difference->minus($value->minor());
        }

        if ($difference->abs()->isLessThanOrEqualTo(count($lines))) {
            return $lines;
        }

        // Debits short: a loss; debits over: a gain.
        $loss = $difference->isNegative();
        $settings = AccountingSettings::query()->where('company_id', $company->id)->first();
        $account = $settings?->accountFor($loss ? 'fx_loss' : 'fx_gain') ?? throw LedgerError::make('setting_missing', ['role' => __('accounting.roles.'.($loss ? 'fx_loss' : 'fx_gain'))]);
        $lines[] = new LineInput($account, $loss ? LineInput::DEBIT : LineInput::CREDIT, Money::ofMinor((string) $difference->abs(), $base),
            description: __('accounting.integrations.fx_difference'), dimensions: $dimensions);

        return $lines;
    }

    /**
     * The active rule the source posts with, and whether its sides are
     * swapped: the source's own type (or the type it names), else the
     * configured fallback (`accounting.rule_fallbacks`: a refund or void
     * posts with the sale rule reversed, a daily summary with the sale rule).
     *
     * @return array{0: PostingRule, 1: bool}
     */
    private function rule(Company $company, SourceDocument $source): array
    {
        $type = $source->rule ?? $source->sourceType;
        $active = fn (string $type) => PostingRule::query()->where('company_id', $company->id)->where('event_type', $type)->whereNull('archived_at')->first();

        if ($rule = $active($type)) {
            return [$rule, $source->reverse];
        }

        // Keys hold dots (`pos.refund`): read the map whole, never by a dotted path.
        [$fallback, $reversed] = ((array) config('accounting.rule_fallbacks'))[$type] ?? [null, false];

        if ($fallback !== null && ($rule = $active($fallback))) {
            return [$rule, $source->reverse !== (bool) $reversed];
        }

        throw LedgerError::make('posting_rule_missing', ['type' => $source->sourceType]);
    }

    /**
     * The source fields a rule line takes: itself, or for `prefix.*` every
     * field `prefix.{key}` of the source, with its key.
     *
     * @return array<string, ?string> field => key
     */
    private function fields(SourceDocument $source, string $field): array
    {
        if (! str_ends_with($field, '.*')) {
            return [$field => null];
        }

        $prefix = substr($field, 0, -1);
        $fields = [];

        foreach (array_keys($source->amounts) as $name) {
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                $fields[$name] = substr($name, strlen($prefix));
            }
        }

        ksort($fields);

        return $fields;
    }

    /**
     * ACC-24: the account a rule line posts a field to. For a `prefix.*`
     * line, the line's map for the key, then (lines `by: payment_method`)
     * the bank, mobile money or cash account linked to that payment method
     * (ACC-12) and the map's `type:{method type}`, then the line's default
     * account. None of them: `posting_account_missing`, kept as an
     * exception until the rule names one.
     *
     * @param  array<string, mixed>  $line
     */
    private function account(Company $company, array $line, string $field, ?string $key): string
    {
        $map = is_array($line['accounts'] ?? null) ? $line['accounts'] : [];

        if ($key !== null && isset($map[$key])) {
            return (string) $map[$key];
        }

        if ($key !== null && ($line['by'] ?? null) === 'payment_method' && Str::isUuid($key)) {
            $linked = BankAccount::query()->where('company_id', $company->id)->where('payment_method_id', $key)->whereNull('archived_at')->value('account_id');

            if ($linked !== null) {
                return (string) $linked;
            }

            $type = PaymentMethod::query()->whereKey($key)->value('type');

            if ($type !== null && isset($map["type:{$type}"])) {
                return (string) $map["type:{$type}"];
            }
        }

        return (string) (($line['account_id'] ?? null) ?: throw LedgerError::make('posting_account_missing', ['field' => $field]));
    }

    /** The day the source happened in the company's time zone. */
    private function localDate(Company $company, CarbonImmutable $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at->setTimezone($company->timezone ?: 'UTC')->toDateString());
    }

    private function existing(SourceDocument $source): ?Journal
    {
        return Journal::query()->where('company_id', $source->companyId)
            ->where('source_type', $source->sourceType)->where('source_id', $source->sourceId)->first();
    }

    private function keep(SourceDocument $source, string $code, string $message): PostingException
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($source, $code, $message) {
            $row = PostingException::query()->where('company_id', $source->companyId)
                ->where('source_type', $source->sourceType)->where('source_id', $source->sourceId)->lockForUpdate()->first();

            if ($row === null) {
                return PostingException::query()->create([
                    'company_id' => $source->companyId,
                    'source_type' => $source->sourceType,
                    'source_id' => $source->sourceId,
                    'payload' => $source->toArray(),
                    'error_code' => mb_substr($code, 0, 40),
                    'error_message' => $message,
                    'status' => PostingException::OPEN,
                    'last_attempt_at' => CarbonImmutable::now(),
                ]);
            }

            $row->fill([
                'payload' => $source->toArray(),
                'error_code' => mb_substr($code, 0, 40),
                'error_message' => $message,
                'attempts' => $row->attempts + 1,
                'status' => PostingException::OPEN,
                'last_attempt_at' => CarbonImmutable::now(),
            ])->save();

            return $row;
        });
    }

    private function resolve(SourceDocument $source, Journal $journal): void
    {
        PostingException::query()->where('company_id', $source->companyId)->where('source_type', $source->sourceType)
            ->where('source_id', $source->sourceId)->where('status', PostingException::OPEN)
            ->get()->each(fn (PostingException $row) => $row->fill(['status' => PostingException::RESOLVED, 'journal_id' => $journal->id])->save());
    }
}
