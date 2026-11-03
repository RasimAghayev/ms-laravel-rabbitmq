<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProductCreated;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCreatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_is_a_no_op_on_admins_side()
    {
        // admin only ever dispatches this job (for main's worker to
        // consume); its own handle() is deliberately empty. Asserting
        // that explicitly so a future accidental implementation here
        // doesn't silently start double-writing admin's own database.
        $job = new ProductCreated(['id' => 1, 'title' => 'x', 'image' => 'x.png']);

        $job->handle();

        $this->assertSame(0, Product::count());
    }
}
