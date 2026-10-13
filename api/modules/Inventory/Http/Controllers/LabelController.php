<?php

namespace Modules\Inventory\Http\Controllers;

use App\Core\DocumentTemplates\DocumentOutput;
use App\Core\Http\ApiException;
use App\Core\MasterData\Taxes\PriceList;
use Illuminate\Http\Response;
use Modules\Inventory\Http\Requests\PrintLabelsRequest;
use Modules\Inventory\Labels\Labels;

/**
 * INV-21, TPL-04: barcode labels as HTML for the browser's print (in a
 * sandboxed frame: nothing in it runs) or a PDF download, a page per copy.
 */
class LabelController
{
    public function __invoke(PrintLabelsRequest $request, Labels $labels, DocumentOutput $output): Response
    {
        $total = array_sum(array_map(fn (array $label) => (int) $label['copies'], $request->validated('labels')));

        if ($total > PrintLabelsRequest::MAX_LABELS) {
            $message = __('inventory.errors.too_many_labels', ['max' => PrintLabelsRequest::MAX_LABELS]);

            throw new ApiException(422, 'too_many_labels', $message, ['labels' => [$message]]);
        }

        $list = $request->filled('price_list_id') ? PriceList::query()->findOrFail($request->validated('price_list_id')) : null;
        $documents = $labels->documents($request->validated('labels'), $list, $request->user());

        if ($request->validated('format', 'html') === 'pdf') {
            return new Response($output->pdfPages($documents), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="labels-'.now()->format('Y-m-d').'.pdf"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return new Response($output->htmlPages($documents), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'",
        ]);
    }
}
