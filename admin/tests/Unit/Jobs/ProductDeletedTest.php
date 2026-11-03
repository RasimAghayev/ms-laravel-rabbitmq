<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProductDeleted;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDeletedTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_is_a_no_op_on_admins_side()
    {
        $product = Product::factory()->create();

        (new ProductDeleted($product->id))->handle();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
