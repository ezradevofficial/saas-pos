<?php

namespace Modules\CRM\Leads;

use App\Core\Identity\Models\User;
use App\Core\Imports\DomainRequests;
use App\Core\Imports\RowRejected;
use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\Parties\Http\Requests\StorePartyRequest;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyPolicy;
use App\Core\MasterData\Parties\PartyWriter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\CRM\Events\LeadConverted;
use Modules\CRM\Models\Lead;
use Modules\CRM\Opportunities\Opportunities;
use Modules\CRM\Support\CrmError;
use Modules\CRM\Support\Records;
use Modules\CRM\Support\Tx;

/**
 * CRM-12: a lead becomes a customer and, when asked, an opportunity.
 *
 * - The customer is an existing party the user reaches (`core.party.view`
 *   at its company, or anywhere when shared) with the customer role, of
 *   the lead's company or shared; or a new party made through the core
 *   party API's own Form Request and writer (`core.party.create`, the
 *   sharing mode, phone rules, custom fields), so a conversion never
 *   meets weaker rules than typing the customer in the app.
 * - The opportunity takes the lead's branch, owner and team, its
 *   estimated value and currency unless given, in the pipeline named (the
 *   default) at the stage named (the first).
 * - Once only: a lead that is converted, disqualified or archived is
 *   refused (`lead_closed`). `LeadConverted` after commit.
 */
class Conversion
{
    public function __construct(
        private readonly Records $records,
        private readonly Opportunities $opportunities,
        private readonly PartyWriter $parties,
        private readonly DomainRequests $requests,
        private readonly CompanyReach $reach,
    ) {}

    /** @param array<string, mixed> $data validated input */
    public function convert(Lead $lead, array $data, User $by): Lead
    {
        return Tx::run(function () use ($lead, $data, $by) {
            $lead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if (! $lead->isOpen()) {
                throw CrmError::make('lead_closed', status: 409);
            }

            $party = isset($data['party_id']) ? $this->existing($lead, (string) $data['party_id'], $by) : $this->newParty($lead, (array) ($data['party'] ?? []), $by);
            $opportunityId = null;

            if (is_array($data['opportunity'] ?? null)) {
                $input = $data['opportunity'];
                $opportunity = $this->opportunities->create([
                    'branch_id' => $lead->branch_id,
                    'party_id' => $party->id,
                    'name' => $input['name'] ?? ($lead->organisation ?: $lead->name),
                    'pipeline_id' => $input['pipeline_id'] ?? null,
                    'stage_id' => $input['stage_id'] ?? null,
                    'value_minor' => $input['value_minor'] ?? $lead->estimated_value_minor ?? 0,
                    'currency' => $input['currency'] ?? $lead->currency ?? $party->currency,
                    'expected_close_on' => $input['expected_close_on'] ?? null,
                    'owner_id' => $lead->owner_id,
                    'team_id' => $lead->team_id,
                    'notes' => $lead->notes,
                ], $by, $lead->id);
                $opportunityId = $opportunity->id;
            }

            $lead->fill([
                'status' => Lead::CONVERTED,
                'party_id' => $party->id,
                'opportunity_id' => $opportunityId,
                'converted_at' => CarbonImmutable::now(),
                'converted_by' => $by->id,
            ])->save();

            LeadConverted::dispatch((string) $lead->tenant_id, $lead->id, $lead->company_id, $lead->branch_id, $party->id, $opportunityId, $by->id);

            return $lead->refresh();
        });
    }

    private function existing(Lead $lead, string $partyId, User $by): Party
    {
        $party = $this->records->customer($lead->company_id, $partyId);

        if (! $this->reach->reachesRecord($by, $party->company_id, PartyPolicy::PERMISSIONS)) {
            throw CrmError::make('party_not_customer', field: 'party_id');
        }

        return $party;
    }

    /** @param array<string, mixed> $input */
    private function newParty(Lead $lead, array $input, User $by): Party
    {
        $body = array_filter([
            'kind' => $input['kind'] ?? ($lead->organisation ? 'organisation' : 'person'),
            'name' => $input['name'] ?? ($lead->organisation ?: $lead->name),
            'company_id' => $input['company_id'] ?? $lead->company_id,
            'phones' => $input['phones'] ?? ($lead->phone ? [['number' => $lead->phone]] : null),
            'emails' => $input['emails'] ?? ($lead->email ? [['address' => $lead->email]] : null),
            'tax_id' => $input['tax_id'] ?? null,
            'roles' => ['customer'],
        ], fn ($value) => $value !== null);

        // MD-01: making a customer needs core.party.create (403, as in the party API).
        if (! $this->reach->anywhere($by, ['core.party.create'])) {
            throw new AuthorizationException(__('crm.errors.party_create_forbidden'));
        }

        try {
            $validated = $this->requests->validate(StorePartyRequest::class, $body, $by);
        } catch (RowRejected $e) {
            $errors = [];

            foreach ($e->errors as $field => $messages) {
                $errors[$field === '_' ? 'party' : "party.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors ?: ['party' => [__('crm.errors.party_invalid')]]);
        }

        return $this->parties->create($validated);
    }
}
