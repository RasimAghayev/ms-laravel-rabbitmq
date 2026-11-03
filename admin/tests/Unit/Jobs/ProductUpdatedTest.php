<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProductUpdated;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductUpdatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_is_a_no_op_on_admins_side()
    {
        $product = Product::factory()->create(['title' => 'Untouched']);

        $job = new ProductUpdated(['id' => $product->id, 'title' => 'Should not land', 'image' => 'x.png']);
        $job->handle();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'title' => 'Untouched',
        ]);
    }
}
