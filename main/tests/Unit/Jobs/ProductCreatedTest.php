<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProductCreated;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCreatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_creates_a_product_from_the_dispatched_payload()
    {
        $job = new ProductCreated([
            'id' => 42,
            'title' => 'Synced product',
            'image' => 'synced-product.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $job->handle();

        $this->assertDatabaseHas('products', [
            'id' => 42,
            'title' => 'Synced product',
            'image' => 'synced-product.png',
        ]);
        $this->assertSame(1, Product::count());
    }
}
