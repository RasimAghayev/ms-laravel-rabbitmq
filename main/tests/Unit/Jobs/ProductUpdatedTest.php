<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProductUpdated;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductUpdatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_updates_an_existing_product_from_the_dispatched_payload()
    {
        Product::create([
            'id' => 7,
            'title' => 'Old title',
            'image' => 'old.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $job = new ProductUpdated([
            'id' => 7,
            'title' => 'New title',
            'image' => 'new.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ]);

        $job->handle();

        $this->assertDatabaseHas('products', [
            'id' => 7,
            'title' => 'New title',
            'image' => 'new.png',
        ]);
        $this->assertSame(1, Product::count());
    }

    public function test_handle_creates_the_product_when_an_update_arrives_before_its_create()
    {
        // Fixed (see docs/TECHNICAL_DEBT.md #11): this used to be
        // Product::find($id)->update(...) with no null check, which
        // threw a fatal Error whenever an Updated message was processed
        // before its Created - a real possibility since a RabbitMQ
        // requeue/retry can reorder messages across consumers, and
        // main_worker can run multiple replicas. The shared
        // ProductSynchronizer upserts, so main converges on the correct
        // end state regardless of which message happens to arrive first.
        $job = new ProductUpdated([
            'id' => 999,
            'title' => 'Update arrived first',
            'image' => 'out-of-order.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $job->handle();

        $this->assertDatabaseHas('products', [
            'id' => 999,
            'title' => 'Update arrived first',
            'image' => 'out-of-order.png',
        ]);
        $this->assertSame(1, Product::count());
    }

    public function test_handle_ignores_a_stale_redelivered_update_older_than_what_is_stored()
    {
        // Last-write-wins by admin's own updated_at: a retried/redelivered
        // Updated job carrying an older timestamp than what main already
        // has must not overwrite the fresher state with stale data.
        Product::create([
            'id' => 7,
            'title' => 'Latest title',
            'image' => 'latest.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-03 00:00:00',
        ]);

        $staleUpdate = new ProductUpdated([
            'id' => 7,
            'title' => 'Stale title',
            'image' => 'stale.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ]);

        $staleUpdate->handle();

        $this->assertDatabaseHas('products', [
            'id' => 7,
            'title' => 'Latest title',
            'image' => 'latest.png',
        ]);
    }

    public function test_handle_is_idempotent_against_redelivery_of_the_same_update()
    {
        Product::create([
            'id' => 7,
            'title' => 'Old title',
            'image' => 'old.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $payload = [
            'id' => 7,
            'title' => 'New title',
            'image' => 'new.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ];

        (new ProductUpdated($payload))->handle();
        (new ProductUpdated($payload))->handle();

        $this->assertSame(1, Product::count());
        $this->assertDatabaseHas('products', ['id' => 7, 'title' => 'New title']);
    }
}
