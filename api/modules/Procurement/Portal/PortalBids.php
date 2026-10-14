<?php

namespace Modules\Procurement\Portal;

use App\Core\Audit\Auditor;
use App\Core\CustomFields\CustomFieldFiles;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\BidFile;
use Modules\Procurement\Models\PortalSession;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Models\TenderLine;
use Modules\Procurement\Support\LineResolver;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;
use Modules\Procurement\Tenders\Seal;
use Throwable;

/**
 * PRC-09, PRC-23: what a bidder does in the portal, always for its own
 * session's tender and bid only.
 *
 * - submit(): before the deadline, a bid in an active currency with a unit
 *   price per tender line (lines left out are not offered), a technical
 *   response and the bidder's own uploaded files; sealed with the tender's
 *   key (Seal). A new submission replaces the previous version (one bid
 *   row per bidder, its version counted); nothing is decrypted again
 *   before the opening, not even for the bidder, who keeps the
 *   fingerprint as its receipt.
 * - upload(): one file (the core types, 10 MB, 10 per bid), its bytes
 *   sealed before they reach the `media` disk.
 * - withdraw(): before the deadline.
 * Every action is audited with the bidder and IP, never the content.
 */
class PortalBids
{
    public const MAX_FILES = 10;

    public function __construct(
        private readonly LineResolver $resolver,
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
    ) {}

    /** @param array{currency: string, lines: list<array{tender_line_id: string, unit_price_minor: string|int}>, technical_response?: ?string, file_ids?: list<string>} $data */
    public function submit(PortalSession $session, array $data, ?string $ip): Bid
    {
        return Tx::run(function () use ($session, $data, $ip) {
            $tender = $this->openTender($session);
            $currency = $this->resolver->currency($data['currency']);
            $lineIds = TenderLine::query()->where('tender_id', $tender->id)->pluck('id')->all();
            $lines = [];

            foreach (array_values($data['lines']) as $index => $line) {
                if (! in_array($line['tender_line_id'], $lineIds, true) || isset($lines[$line['tender_line_id']])) {
                    throw ProcurementError::make('line_unknown', field: "lines.{$index}.tender_line_id");
                }

                $lines[$line['tender_line_id']] = ['tender_line_id' => $line['tender_line_id'], 'unit_price_minor' => (string) $line['unit_price_minor']];
            }

            if ($lines === []) {
                throw ProcurementError::make('document_empty', field: 'lines');
            }

            $fileIds = array_values(array_unique($data['file_ids'] ?? []));
            $files = BidFile::query()->where('bidder_id', $session->bidder_id)->where('tender_id', $tender->id)->whereNull('removed_at')->whereKey($fileIds)->pluck('id')->all();

            if (count($files) !== count($fileIds)) {
                throw ProcurementError::make('file_unknown', field: 'file_ids');
            }

            [$sealed, $fingerprint] = Seal::seal($tender, [
                'currency' => $currency,
                'lines' => array_values($lines),
                'technical_response' => $data['technical_response'] ?? null,
                'file_ids' => $files,
            ]);
            $bid = Bid::query()->where('tender_id', $tender->id)->where('bidder_id', $session->bidder_id)->lockForUpdate()->first();
            $bid ??= new Bid(['tender_id' => $tender->id, 'bidder_id' => $session->bidder_id, 'version' => 0]);
            $bid->fill([
                'status' => Bid::SUBMITTED,
                'version' => $bid->version + 1,
                'submitted_at' => CarbonImmutable::now(),
                'withdrawn_at' => null,
                'fingerprint' => $fingerprint,
                'sealed_payload' => $sealed,
            ])->save();
            BidFile::query()->where('bidder_id', $session->bidder_id)->where('tender_id', $tender->id)->whereNull('removed_at')
                ->whereNotIn('id', $files)->update(['removed_at' => CarbonImmutable::now()]);
            BidFile::query()->whereKey($files)->update(['bid_id' => $bid->id]);
            $this->auditor->record('procurement.portal.bid_submitted', $bid, null,
                ['tender' => $tender->id, 'bidder' => $session->bidder_id, 'version' => $bid->version, 'fingerprint' => $fingerprint, 'files' => count($files), 'ip' => $ip], ['ip' => $ip]);

            return $bid->refresh();
        });
    }

    public function withdraw(PortalSession $session, ?string $ip): Bid
    {
        return Tx::run(function () use ($session, $ip) {
            $tender = $this->openTender($session);
            $bid = Bid::query()->where('tender_id', $tender->id)->where('bidder_id', $session->bidder_id)->lockForUpdate()->first();

            if ($bid === null || $bid->status !== Bid::SUBMITTED) {
                throw ProcurementError::make('no_bid', status: 409);
            }

            $bid->fill(['status' => Bid::WITHDRAWN, 'withdrawn_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('procurement.portal.bid_withdrawn', $bid, null, ['tender' => $tender->id, 'bidder' => $session->bidder_id, 'version' => $bid->version, 'ip' => $ip], ['ip' => $ip]);

            return $bid->refresh();
        });
    }

    public function upload(PortalSession $session, UploadedFile $file, ?string $ip): BidFile
    {
        $tender = $this->openTender($session);
        $mime = (string) $file->getMimeType();
        $extension = CustomFieldFiles::MIMES[$mime] ?? null;

        if ($extension === null) {
            throw ProcurementError::make('file_type', field: 'file');
        }

        if (BidFile::query()->where('bidder_id', $session->bidder_id)->where('tender_id', $tender->id)->whereNull('removed_at')->count() >= self::MAX_FILES) {
            throw ProcurementError::make('too_many_files', ['max' => self::MAX_FILES], 'file');
        }

        $path = sprintf('tenants/%s/procurement/bids/%s/%s.sealed', $this->tenants->require(), $tender->id, Str::uuid7());
        $disk = Storage::disk(CustomFieldFiles::DISK);

        if (! $disk->put($path, Seal::sealBytes($tender, (string) file_get_contents($file->getRealPath())))) {
            throw ProcurementError::make('file_not_stored', status: 500);
        }

        try {
            return Tx::run(function () use ($session, $tender, $path, $file, $mime, $ip) {
                $stored = BidFile::query()->create([
                    'tender_id' => $tender->id,
                    'bidder_id' => $session->bidder_id,
                    'disk' => CustomFieldFiles::DISK,
                    'path' => $path,
                    'name' => mb_substr($file->getClientOriginalName() ?: basename($path), 0, 255),
                    'mime' => $mime,
                    'size' => (int) $file->getSize(),
                ]);
                $this->auditor->record('procurement.portal.file_uploaded', $stored, null, ['tender' => $tender->id, 'bidder' => $session->bidder_id, 'size' => $stored->size, 'ip' => $ip], ['ip' => $ip]);

                return $stored;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /** The session's tender, published and before its deadline (PRC-09: no change after it). */
    private function openTender(PortalSession $session): Tender
    {
        $tender = Tender::query()->whereKey($session->tender_id)->lockForUpdate()->firstOrFail();

        if ($tender->status !== Tender::PUBLISHED) {
            throw ProcurementError::make('tender_not_open_for_bids', status: 409);
        }

        if ($tender->closedForBids()) {
            throw ProcurementError::make('deadline_passed', status: 409);
        }

        return $tender;
    }
}
