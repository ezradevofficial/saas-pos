<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Core\DocumentTemplates\DocumentOutput;
use Illuminate\Http\Response;
use Modules\FixedAssets\Http\Requests\PrintLabelsRequest;
use Modules\FixedAssets\Labels\Labels;
use Modules\FixedAssets\Support\FaError;

/** FA-25, TPL-04: asset labels as HTML for the browser's print (in a sandboxed frame) or a PDF, a page per copy. */
class LabelController
{
    public function __invoke(PrintLabelsRequest $request, Labels $labels, DocumentOutput $output): Response
    {
        $total = array_sum(array_map(fn (array $label) => (int) $label['copies'], $request->validated('labels')));

        if ($total > PrintLabelsRequest::MAX_LABELS) {
            throw FaError::make('too_many_labels', ['max' => PrintLabelsRequest::MAX_LABELS], 'labels');
        }

        $documents = $labels->documents($request->validated('labels'), $request->user());

        if ($request->validated('format', 'html') === 'pdf') {
            return new Response($output->pdfPages($documents), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="asset-labels-'.now()->format('Y-m-d').'.pdf"',
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
