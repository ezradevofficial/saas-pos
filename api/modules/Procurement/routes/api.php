<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use Illuminate\Support\Facades\Route;
use Modules\Procurement\Http\Controllers\BidController;
use Modules\Procurement\Http\Controllers\InvoiceController;
use Modules\Procurement\Http\Controllers\LandedCostController;
use Modules\Procurement\Http\Controllers\OrderController;
use Modules\Procurement\Http\Controllers\PerformanceController;
use Modules\Procurement\Http\Controllers\ReceiptController;
use Modules\Procurement\Http\Controllers\RequisitionController;
use Modules\Procurement\Http\Controllers\RfqController;
use Modules\Procurement\Http\Controllers\SettingsController;
use Modules\Procurement\Http\Controllers\TenderController;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\BidFile;
use Modules\Procurement\Models\Invoice;
use Modules\Procurement\Models\LandedCost;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\Receipt;
use Modules\Procurement\Models\Requisition;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\Tender;

// Procurement back office (docs/modules/procurement.md, API), prefix
// /api/v1, loaded by ProcurementServiceProvider. RBAC-08: every route is
// closed (403 `module_inactive`) unless the tenant has the Procurement
// module active. People's tokens only; suppliers use routes/portal.php.

$models = [
    'procurement_requisition' => Requisition::class,
    'procurement_rfq' => Rfq::class,
    'procurement_tender' => Tender::class,
    'procurement_bidder' => Bidder::class,
    'procurement_bid' => Bid::class,
    // PRC-12: a bid with an outcome (its award or regret letter).
    'procurement_decided_bid' => Bid::class,
    'procurement_bid_file' => BidFile::class,
    'procurement_order' => Order::class,
    'procurement_receipt' => Receipt::class,
    'procurement_invoice' => Invoice::class,
    'procurement_landed_cost' => LandedCost::class,
];

foreach ($models as $parameter => $model) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::model($parameter, $model);
}

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:procurement'])->group(function () {
    // PRC-01: a company's tolerances.
    Route::get('procurement/companies/{company}/settings', [SettingsController::class, 'show']);
    Route::put('procurement/companies/{company}/settings', [SettingsController::class, 'update']);

    // PRC-02: requisitions.
    Route::get('procurement/requisitions', [RequisitionController::class, 'index']);
    Route::post('procurement/requisitions', [RequisitionController::class, 'store']);
    Route::get('procurement/requisitions/{procurement_requisition}', [RequisitionController::class, 'show']);
    Route::patch('procurement/requisitions/{procurement_requisition}', [RequisitionController::class, 'update']);
    Route::post('procurement/requisitions/{procurement_requisition}/submit', [RequisitionController::class, 'submit']);
    Route::post('procurement/requisitions/{procurement_requisition}/cancel', [RequisitionController::class, 'cancel']);

    // PRC-03 to PRC-05: RFQs, quotes, comparison and award.
    Route::get('procurement/rfqs', [RfqController::class, 'index']);
    Route::post('procurement/rfqs', [RfqController::class, 'store']);
    Route::get('procurement/rfqs/{procurement_rfq}', [RfqController::class, 'show']);
    Route::patch('procurement/rfqs/{procurement_rfq}', [RfqController::class, 'update']);
    Route::post('procurement/rfqs/{procurement_rfq}/issue', [RfqController::class, 'issue']);
    Route::post('procurement/rfqs/{procurement_rfq}/cancel', [RfqController::class, 'cancel']);
    Route::get('procurement/rfqs/{procurement_rfq}/comparison', [RfqController::class, 'comparison']);
    Route::post('procurement/rfqs/{procurement_rfq}/award', [RfqController::class, 'award']);
    Route::post('procurement/quotes', [RfqController::class, 'quote']);

    // PRC-06 to PRC-12: tenders, bidders, the committee opening, scores, award and letters.
    Route::get('procurement/tenders', [TenderController::class, 'index']);
    Route::post('procurement/tenders', [TenderController::class, 'store']);
    Route::get('procurement/tenders/{procurement_tender}', [TenderController::class, 'show']);
    Route::patch('procurement/tenders/{procurement_tender}', [TenderController::class, 'update']);
    Route::post('procurement/tenders/{procurement_tender}/publish', [TenderController::class, 'publish']);
    Route::post('procurement/tenders/{procurement_tender}/cancel', [TenderController::class, 'cancel']);
    Route::post('procurement/tenders/{procurement_tender}/bidders', [TenderController::class, 'addBidder']);
    Route::post('procurement/tenders/{procurement_tender}/open', [TenderController::class, 'open']);
    Route::post('procurement/tender-bidders/{procurement_bidder}/resend', [TenderController::class, 'resend']);
    Route::post('procurement/tender-bidders/{procurement_bidder}/remove', [TenderController::class, 'removeBidder']);
    Route::put('procurement/bids/{procurement_bid}/scores', [BidController::class, 'scores']);
    Route::post('procurement/bids/{procurement_bid}/award', [BidController::class, 'award']);
    Route::get('procurement/bids/{procurement_bid}/files/{procurement_bid_file}', [BidController::class, 'file']);
    Route::get('procurement/bids/{procurement_decided_bid}/letter', [BidController::class, 'letter']);
    Route::post('procurement/bids/{procurement_decided_bid}/letter/email', [BidController::class, 'emailLetter']);

    // PRC-13: purchase orders.
    Route::get('procurement/orders', [OrderController::class, 'index']);
    Route::post('procurement/orders', [OrderController::class, 'store']);
    Route::get('procurement/orders/{procurement_order}', [OrderController::class, 'show']);
    Route::patch('procurement/orders/{procurement_order}', [OrderController::class, 'update']);
    Route::post('procurement/orders/{procurement_order}/submit', [OrderController::class, 'submit']);
    Route::post('procurement/orders/{procurement_order}/cancel', [OrderController::class, 'cancel']);
    Route::post('procurement/orders/{procurement_order}/send', [OrderController::class, 'send']);
    Route::get('procurement/orders/{procurement_order}/pdf', [OrderController::class, 'pdf']);

    // PRC-14: goods received notes.
    Route::get('procurement/receipts', [ReceiptController::class, 'index']);
    Route::post('procurement/receipts', [ReceiptController::class, 'store']);
    Route::get('procurement/receipts/{procurement_receipt}', [ReceiptController::class, 'show']);
    Route::patch('procurement/receipts/{procurement_receipt}', [ReceiptController::class, 'update']);
    Route::post('procurement/receipts/{procurement_receipt}/post', [ReceiptController::class, 'post']);
    Route::post('procurement/receipts/{procurement_receipt}/cancel', [ReceiptController::class, 'cancel']);

    // PRC-15: supplier invoices and their match.
    Route::get('procurement/invoices', [InvoiceController::class, 'index']);
    Route::post('procurement/invoices', [InvoiceController::class, 'store']);
    Route::get('procurement/invoices/{procurement_invoice}', [InvoiceController::class, 'show']);
    Route::patch('procurement/invoices/{procurement_invoice}', [InvoiceController::class, 'update']);
    Route::post('procurement/invoices/{procurement_invoice}/approve', [InvoiceController::class, 'approve']);
    Route::post('procurement/invoices/{procurement_invoice}/cancel', [InvoiceController::class, 'cancel']);

    // PRC-17: landed costs.
    Route::get('procurement/landed-costs', [LandedCostController::class, 'index']);
    Route::post('procurement/landed-costs', [LandedCostController::class, 'store']);
    Route::get('procurement/landed-costs/{procurement_landed_cost}', [LandedCostController::class, 'show']);
    Route::patch('procurement/landed-costs/{procurement_landed_cost}', [LandedCostController::class, 'update']);
    Route::post('procurement/landed-costs/{procurement_landed_cost}/post', [LandedCostController::class, 'post']);
    Route::post('procurement/landed-costs/{procurement_landed_cost}/cancel', [LandedCostController::class, 'cancel']);

    // PRC-18: supplier performance.
    Route::get('procurement/supplier-performance', PerformanceController::class);
});
