<?php

namespace App\Jobs;

use App\Services\ProductSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProductUpdated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $data;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($data)
    {
        //
        $this->data = $data;
    }

    /**
     * Idempotent upsert - see App\Services\ProductSynchronizer. Fixed:
     * this used to do Product::find($id)->update(...) with no null
     * check, which was a fatal error whenever an Updated message arrived
     * before its Created (requeue/retry can reorder messages) or the two
     * databases had already drifted (docs/TECHNICAL_DEBT.md #11). Sharing
     * the synchronizer with ProductCreated also means a redelivered
     * Updated can never apply stale data over something fresher.
     *
     * @return void
     */
    public function handle()
    {
        (new ProductSynchronizer())->syncFromPayload($this->data);
    }
}
