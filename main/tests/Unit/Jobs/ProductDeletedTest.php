<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProductDeleted;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDeletedTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_deletes_an_existing_product()
    {
        Product::create([
            'id' => 3,
            'title' => 'Going away',
            'image' => 'bye.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        (new ProductDeleted(3))->handle();

        $this->assertDatabaseMissing('products', ['id' => 3]);
        $this->assertSame(0, Product::count());
    }

    public function test_handle_on_a_missing_product_is_a_no_op()
    {
        // Product::destroy() silently ignores an id that doesn't exist -
        // matches admin's own destroy() behavior (see
        // admin/tests/Feature/ProductControllerTest.php), so a delete
        // racing ahead of main's own create for the same id doesn't crash.
        (new ProductDeleted(999))->handle();

        $this->assertSame(0, Product::count());
    }
}
