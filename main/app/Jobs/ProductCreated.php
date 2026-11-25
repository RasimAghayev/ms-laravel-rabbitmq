<?php

namespace App\Jobs;

use App\Services\ProductSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProductCreated implements ShouldQueue
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
     * Idempotent upsert - see App\Services\ProductSynchronizer for why a
     * plain Product::create() is not safe against RabbitMQ's
     * at-least-once redelivery (duplicate primary key on retry) and why
     * Created/Updated share this same handling.
     *
     * @return void
     */
    public function handle()
    {
        (new ProductSynchronizer())->syncFromPayload($this->data);
    }
}
