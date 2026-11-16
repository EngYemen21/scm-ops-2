<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Services\Core\SettingsService;
use Tests\Feature\Sales\SalesFixtures;

/** Helpers of the picking / packing / loading tests, on top of the sales fixtures (products, stock, fleet, trips). */
trait FulfillmentFixtures
{
    use SalesFixtures;

    /** Runs $fn with the given policies and puts the defaults back whatever happens (the database outlives the test). */
    protected function withPolicy(array $policies, callable $fn): mixed
    {
        $settings = $this->app->make(SettingsService::class);
        foreach ($policies as $key => $value) {
            $settings->set($key, $value);
        }
        try {
            return $fn();
        } finally {
            foreach (array_keys($policies) as $key) {
                $settings->set($key, SettingsService::DEFAULTS[$key]['value']);
            }
        }
    }

    /** A sales order of ours for a new customer; returns the order document. */
    protected function order(array $lines, ?Customer $customer = null): array
    {
        return $this->expectOk($this->orderFor($customer ?? $this->makeCustomer(), $lines));
    }

    /** Creates the fulfilment order of a sales order; returns its document (with pick lists and tasks). */
    protected function fulfil(string $soNumber): array
    {
        $fo = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$soNumber}/fulfill"));

        return $this->fo($fo['number']);
    }

    protected function fo(string $number): array
    {
        return $this->expectOk($this->getAs('worker', "/api/fulfillment/orders/{$number}"));
    }

    /** Every task of the order, in pick sequence. */
    protected function tasks(string $foNumber): array
    {
        return collect($this->fo($foNumber)['pickLists'])->flatMap(fn ($pl) => $pl['tasks'])->sortBy('seq')->values()->all();
    }

    /** Confirms one task (full remaining quantity unless $qty is given). */
    protected function pickTask(array $task, ?int $qty = null, string $user = 'worker', array $extra = [])
    {
        return $this->postAs($user, "/api/fulfillment/pick-tasks/{$task['id']}/confirm", ['scannedBin' => $task['bin']['code'], 'scannedProduct' => $task['product']['sku']] + ($qty ? ['qty' => $qty] : []) + $extra);
    }

    /** Fulfil + pick every task (not packed); returns the fulfilment-order number. */
    protected function pickedOrder(string $soNumber): string
    {
        $fo = $this->fulfil($soNumber);
        foreach ($fo['pickLists'][0]['tasks'] as $t) {
            $this->expectOk($this->pickTask($t));
        }

        return $fo['number'];
    }

    /** POST load with the order scanned by its own number. */
    protected function loadScanned(string $trip, string $fo, string $user = 'worker', array $extra = [])
    {
        return $this->postAs($user, "/api/fulfillment/trips/{$trip}/load", ['foNumber' => $fo, 'scannedOrder' => $fo] + $extra);
    }

    /** Marks a sales order as received from another system (the integration writes these two columns at intake). */
    protected function fromAnotherSystem(string $soNumber): string
    {
        $ref = self::code('ORD-T');
        SalesOrder::where('number', $soNumber)->update(['source_system' => 'sales', 'external_ref' => $ref]);

        return $ref;
    }

    protected function row(Product $product, string $binCode): ?InventoryBalance
    {
        return InventoryBalance::where('product_id', $product->id)->where('bin_id', $this->inventory()->bin($this->ryd()->id, $binCode)->id)->first();
    }
}
