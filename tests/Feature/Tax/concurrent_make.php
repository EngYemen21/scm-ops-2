<?php

use App\Finance\Models\CustomerInvoice;
use App\Tax\Services\EinvoiceService;
use Illuminate\Contracts\Console\Kernel;

// A helper process of EinvoiceChainTest: makes the e-invoices of the given OPS invoices in its OWN connection, at
// the same moment as other processes do, so the row lock of the unit is proven against real concurrency.
// usage: php concurrent_make.php <project root> <start-at unix float> <invoice id> [<invoice id> …]
$root = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$start = (float) $argv[2];
while (microtime(true) < $start) {
    usleep(2000); // every process starts at the same instant
}
$made = [];
foreach (array_slice($argv, 3) as $id) {
    $invoice = CustomerInvoice::findOrFail($id);
    $e = app(EinvoiceService::class)->make($invoice);
    $made[] = $e->icv;
}
echo json_encode($made);
