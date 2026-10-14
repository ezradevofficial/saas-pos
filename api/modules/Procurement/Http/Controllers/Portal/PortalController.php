<?php

namespace Modules\Procurement\Http\Controllers\Portal;

use App\Core\Http\ApiException;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Procurement\Http\Requests\Portal\PortalRequest;
use Modules\Procurement\Http\Requests\Portal\RegisterBidderRequest;
use Modules\Procurement\Http\Requests\Portal\StartSessionRequest;
use Modules\Procurement\Http\Requests\Portal\SubmitBidRequest;
use Modules\Procurement\Http\Requests\Portal\UploadBidFileRequest;
use Modules\Procurement\Http\Resources\Refs;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\BidFile;
use Modules\Procurement\Models\PortalSession;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Models\TenderCriterion;
use Modules\Procurement\Models\TenderLine;
use Modules\Procurement\Portal\PortalAuth;
use Modules\Procurement\Portal\PortalBids;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Tenders\Bidders;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRC-07 to PRC-09, PRC-23: the supplier portal. Everything it answers is
 * about the session's own tender and bid: the tender's public details
 * (never other bidders, not even their number), and the bidder's own bid
 * receipt (status, version, fingerprint, time; its content stays sealed
 * until the opening, PRC-09).
 */
class PortalController
{
    public function __construct(
        private readonly PortalAuth $auth,
        private readonly PortalBids $bids,
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
    ) {}

    /** Exchange the emailed link for a session. */
    public function start(StartSessionRequest $request): JsonResponse
    {
        try {
            [$token, $session] = $this->auth->start((string) $request->validated('token'), $request->ip(), $request->userAgent());
            $this->assertModule();

            return response()->json(['data' => ['session_token' => $token, 'expires_at' => Refs::at($session->expires_at)]], 201);
        } finally {
            $this->tenants->set(null);
        }
    }

    public function end(PortalRequest $request): Response
    {
        $this->auth->end($request->session(), $request->ip());

        return response()->noContent();
    }

    public function tender(PortalRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->view($request->session())]);
    }

    public function submit(SubmitBidRequest $request): JsonResponse
    {
        $this->bids->submit($request->session(), $request->validated(), $request->ip());

        return response()->json(['data' => $this->view($request->session())]);
    }

    public function upload(UploadBidFileRequest $request): JsonResponse
    {
        $file = $this->bids->upload($request->session(), $request->file('file'), $request->ip());

        return response()->json(['data' => ['id' => $file->id, 'name' => $file->name, 'mime' => $file->mime, 'size' => $file->size]], 201);
    }

    public function withdraw(PortalRequest $request): JsonResponse
    {
        $this->bids->withdraw($request->session(), $request->ip());

        return response()->json(['data' => $this->view($request->session())]);
    }

    /** PRC-08: an open tender's public notice. */
    public function notice(string $notice): JsonResponse
    {
        try {
            $tender = $this->noticeTender($notice);

            return response()->json(['data' => [
                ...$this->publicTender($tender),
                'open_for_bids' => ! $tender->closedForBids(),
            ]]);
        } finally {
            $this->tenants->set(null);
        }
    }

    /** PRC-08: an outsider registers on an open tender; the link goes to the email typed. */
    public function register(RegisterBidderRequest $request, string $notice, Bidders $bidders): JsonResponse
    {
        $key = 'procurement-register:'.hash('sha256', strtolower((string) $request->validated('email')));

        if (RateLimiter::tooManyAttempts($key, (int) config('procurement.portal.registrations_per_hour', 5))) {
            throw ProcurementError::make('too_many_registrations', status: 429);
        }

        RateLimiter::hit($key, 3600);

        try {
            $tender = $this->noticeTender($notice);
            $bidders->register($tender, $request->validated(), $request->ip());

            return response()->json(['data' => ['sent' => true]], 202);
        } finally {
            $this->tenants->set(null);
        }
    }

    private function noticeTender(string $notice): Tender
    {
        $tenantId = $this->auth->tenantOf('notice', hash('sha256', $notice));

        if ($tenantId === null) {
            throw ProcurementError::make('portal_link_invalid', status: 404);
        }

        $this->tenants->set($tenantId);
        $this->assertModule();
        $tender = Tender::query()->where('public_token_hash', hash('sha256', $notice))->first();

        if ($tender === null || $tender->kind !== Tender::OPEN || ! in_array($tender->status, [Tender::PUBLISHED, Tender::OPENED, Tender::EVALUATED, Tender::AWARDED], true)) {
            throw ProcurementError::make('portal_link_invalid', status: 404);
        }

        return $tender;
    }

    private function assertModule(): void
    {
        if (! $this->modules->isActive('procurement')) {
            throw new ApiException(403, 'module_inactive', __('rbac.errors.module_inactive'));
        }
    }

    /** @return array<string, mixed> what any bidder may know of a tender */
    private function publicTender(Tender $tender): array
    {
        $company = Company::query()->find($tender->company_id);

        return [
            'number' => $tender->number,
            'title' => $tender->title,
            'description' => $tender->description,
            'kind' => $tender->kind,
            'company' => $company?->name,
            'branch' => Branch::query()->whereKey($tender->branch_id)->value('name'),
            'currency' => $tender->currency,
            'deadline_at' => Refs::at($tender->deadline_at),
            'opening_at' => Refs::at($tender->opening_at),
            'technical_weight' => $tender->technical_weight,
            'financial_weight' => $tender->financial_weight,
            'lines' => TenderLine::query()->with('uom')->where('tender_id', $tender->id)->orderBy('line_no')->get()->map(fn (TenderLine $line) => [
                'id' => $line->id, 'line_no' => $line->line_no, 'description' => $line->description, 'uom' => Refs::uom($line->uom), 'qty' => Refs::qty($line->qty),
            ])->all(),
            'criteria' => TenderCriterion::query()->where('tender_id', $tender->id)->orderBy('line_no')->get()
                ->map(fn (TenderCriterion $c) => ['name' => $c->name, 'weight' => $c->weight, 'max_score' => $c->max_score])->all(),
        ];
    }

    /** @return array<string, mixed> the session's tender and its own bid receipt */
    private function view(PortalSession $session): array
    {
        $tender = Tender::query()->findOrFail($session->tender_id);
        $bid = Bid::query()->where('tender_id', $tender->id)->where('bidder_id', $session->bidder_id)->first();
        $files = BidFile::query()->where('tender_id', $tender->id)->where('bidder_id', $session->bidder_id)->whereNull('removed_at')->orderBy('created_at')->get();

        return [
            'tender' => [...$this->publicTender($tender), 'open_for_bids' => $tender->status === Tender::PUBLISHED && ! $tender->closedForBids()],
            'bidder' => ['name' => $session->bidder->name, 'email' => $session->bidder->email],
            'bid' => $bid === null ? null : [
                'status' => $bid->status,
                'version' => $bid->version,
                'submitted_at' => Refs::at($bid->submitted_at),
                'withdrawn_at' => Refs::at($bid->withdrawn_at),
                'fingerprint' => $bid->fingerprint,
                'outcome' => $tender->status === Tender::AWARDED ? $bid->outcome : null,
            ],
            'files' => $files->map(fn (BidFile $file) => ['id' => $file->id, 'name' => $file->name, 'mime' => $file->mime, 'size' => $file->size, 'attached' => $file->bid_id !== null])->all(),
            'session' => ['expires_at' => Refs::at($session->expires_at)],
        ];
    }
}
