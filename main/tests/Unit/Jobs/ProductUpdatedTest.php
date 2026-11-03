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

    public function test_handle_throws_when_the_product_does_not_exist()
    {
        // handle() does Product::find($id)->update(...) with no null check -
        // a product that doesn't exist (e.g. an update racing ahead of its
        // create, or main's DB having fallen out of sync) is a fatal error,
        // not a graceful skip. Real, pre-existing gap - documented here
        // rather than silently patched (see TECHNICAL_DEBT.md).
        $job = new ProductUpdated([
            'id' => 999,
            'title' => 'Does not exist',
            'image' => 'missing.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->expectException(\Error::class);
        $job->handle();
    }
}
