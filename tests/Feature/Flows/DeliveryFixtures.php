<?php

namespace Tests\Feature\Flows;

use App\Models\Batch;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\PickList;
use App\Models\PickTask;
use App\Services\Core\SettingsService;

/**
 * What a proof of delivery needs since the delivery policies (`delivery.requireScan`, `delivery.requireSignature`):
 * a real image for the signature / photo, the scan of the stop's order, and — for the batch of a return — the pick
 * tasks picking would have left behind. Used together with FlowFixtures.
 */
trait DeliveryFixtures
{
    /** A real 1×1 PNG. */
    protected const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function signatureImage(): string
    {
        return 'data:image/png;base64,'.self::PNG_1PX;
    }

    /** A JPEG by its file signature, padded to the wanted size (decoded bytes). */
    protected function photoImage(int $bytes = 900): string
    {
        return 'data:image/jpeg;base64,'.base64_encode("\xFF\xD8\xFF\xE0".str_repeat("\x07", max(0, $bytes - 4)));
    }

    /** A POD body that satisfies the default policies: receiver + signature + the scan of the stop's own order. */
    protected function podBody(FulfillmentOrder $fo, array $extra = []): array
    {
        return $extra + ['receiverName' => 'أبو فهد', 'signature' => $this->signatureImage(), 'scans' => [$fo->number]];
    }

    /**
     * Pick tasks of one order line, as picking leaves them: one task per [batchNo | null, pickedQty] pair, in the
     * order they were picked (the first pair is the oldest pick). A batch number creates the product's batch.
     *
     * @param  array<int, array{0:?string, 1:int}>  $picks
     */
    protected function picked(FulfillmentOrder $fo, int $lineNo, array $picks): void
    {
        $line = FoLine::where('fo_id', $fo->id)->where('line_no', $lineNo)->firstOrFail();
        $list = PickList::firstOrCreate(['fo_id' => $fo->id], ['number' => self::code('PL-T'), 'warehouse_id' => $fo->warehouse_id, 'status' => 'done']);
        $bin = $this->inv()->bin($fo->warehouse_id, 'A-07-1-B1');
        $start = PickTask::where('pick_list_id', $list->id)->count();
        foreach (array_values($picks) as $i => [$batchNo, $qty]) {
            $batch = $batchNo ? Batch::firstOrCreate(['product_id' => $line->product_id, 'batch_no' => $batchNo], ['expiry_date' => now()->addYear()]) : null;
            PickTask::create(['pick_list_id' => $list->id, 'fo_line_id' => $line->id, 'seq' => $start + $i + 1, 'product_id' => $line->product_id, 'bin_id' => $bin->id,
                'batch_id' => $batch?->id, 'batch_no' => $batchNo, 'qty' => $qty, 'picked_qty' => $qty, 'status' => 'done', 'picked_by' => 'worker',
                'picked_at' => now()->subMinutes(60 - $start - $i)]);
        }
    }

    /** Runs $fn with a policy set to $value and puts the previous value back, whatever happens. */
    protected function withSetting(string $key, mixed $value, callable $fn): mixed
    {
        $settings = $this->app->make(SettingsService::class);
        $before = $settings->get($key);
        $settings->set($key, $value);
        try {
            return $fn();
        } finally {
            $settings->set($key, $before);
        }
    }
}
