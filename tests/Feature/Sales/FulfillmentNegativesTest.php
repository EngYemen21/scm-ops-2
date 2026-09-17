<?php

namespace Tests\Feature\Sales;

use App\Models\Batch;
use App\Models\FulfillmentOrder;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/** Mandatory negative tests of the reference (negatives.e2e-spec) that belong to fulfillment. */
class FulfillmentNegativesTest extends ApiTestCase
{
    use SalesFixtures;

    public function test_temperature_mismatch_at_loading(): void
    {
        $frozen = $this->makeProduct('frozen');
        $this->stock($frozen, 'FZ-01-1-B3', 30, 90);
        $so = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $frozen->sku, 'qty' => 5, 'price' => 5]]));
        $fo = $this->packedOrder($so['number'], 5);

        // a dry vehicle forced onto the trip: loading checks the goods' storage class against the vehicle kind
        $dry = $this->makeVehicle('dry');
        $trip = $this->makeTrip([$fo], $dry, $this->makeDriver());
        $movements = InventoryMovement::count();
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo, 'scannedVehicle' => $dry->code]), 'TEMP_MISMATCH', [422]);
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame(['packed', false], [FulfillmentOrder::where('number', $fo)->value('status'), (bool) FulfillmentOrder::where('number', $fo)->value('loaded')]);
        $this->assertSame(['dassigned', 'available'], [Trip::find($trip->id)->status, Vehicle::find($dry->id)->state]);
        $this->assertSame(5, $this->onHand($frozen, 'STG-OUT'));

        // a chilled truck is not enough for frozen goods either; a reefer is
        $trip->update(['vehicle_id' => $this->makeVehicle('chill')->id]);
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo]), 'TEMP_MISMATCH', [422]);
        $reefer = $this->makeVehicle('reefer');
        $trip->update(['vehicle_id' => $reefer->id]);
        $this->expectOk($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo, 'scannedVehicle' => $reefer->code]));
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo]), 'ALREADY_LOADED', [409]);
        $this->assertSame(0, $this->onHand($frozen, 'STG-OUT'));
        $this->assertReconciles();
    }

    public function test_chilled_goods_need_a_temperature_controlled_vehicle(): void
    {
        $chilled = $this->makeProduct('chilled');
        $this->stock($chilled, 'CH-01-1-B2', 12, 20);
        $so = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $chilled->sku, 'qty' => 4, 'price' => 5]]));
        $fo = $this->packedOrder($so['number'], 4);
        $trip = $this->makeTrip([$fo], $this->makeVehicle('dry'), $this->makeDriver());
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo]), 'TEMP_MISMATCH', [422]);
        $trip->update(['vehicle_id' => $this->makeVehicle('chill')->id]);
        $this->expectOk($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo]));
        $this->assertReconciles();
    }

    public function test_a_batch_that_expired_or_was_quarantined_after_allocation_cannot_be_picked(): void
    {
        $product = $this->makeProduct();
        $batch = $this->stock($product, 'A-07-3-B1', 10, 5);
        $so = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 4, 'price' => 5]]));
        $fo = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/fulfill"));
        $task = $this->expectOk($this->getAs('worker', "/api/fulfillment/orders/{$fo['number']}"))['pickLists'][0]['tasks'][0];
        $scan = ['scannedBin' => 'A-07-3-B1', 'scannedProduct' => $product->sku];

        Batch::whereKey($batch->id)->update(['expiry_date' => now()->subDay()]);
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/confirm", $scan), 'EXPIRED_BATCH', [422]);
        Batch::whereKey($batch->id)->update(['expiry_date' => now()->addDays(30)]);

        $bin = $this->inventory()->bin($this->ryd()->id, 'A-07-3-B1');
        DB::transaction(fn () => $this->inventory()->setQuarantine($product->id, $bin->id, $batch->id, true));
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/confirm", $scan), 'QUARANTINED', [422]);
        $this->assertSame([10, 4], [$this->onHand($product, 'A-07-3-B1'), $this->reserved($product)]);
        DB::transaction(fn () => $this->inventory()->setQuarantine($product->id, $bin->id, $batch->id, false));

        $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/confirm", $scan));
        // once the order left the picking stage its tasks are closed for scanning
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/confirm", $scan), 'FO_STATE', [422]);
        $this->assertSame(0, (int) InventoryBalance::where('product_id', $product->id)->sum('reserved'));
        $this->assertReconciles();
    }
}
