<?php

namespace Tests\Feature;

use App\Jobs\ProductCreated;
use App\Jobs\ProductDeleted;
use App\Jobs\ProductUpdated;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    // ProductCreated/ProductUpdated/ProductDeleted keep their constructor
    // argument in a private property with no getter - read it via
    // reflection rather than widening the class's public API just for tests.
    private function jobProperty(object $job, string $property)
    {
        $ref = new \ReflectionProperty($job, $property);
        $ref->setAccessible(true);

        return $ref->getValue($job);
    }

    public function test_index_returns_all_products()
    {
        Product::factory()->count(3)->create();

        $response = $this->getJson('/api/products');

        $response->assertOk();
        $this->assertCount(3, $response->json());
    }

    public function test_show_returns_a_product()
    {
        $product = Product::factory()->create();

        $response = $this->getJson("/api/products/{$product->id}");

        $response->assertOk();
        $response->assertJson([
            'id' => $product->id,
            'title' => $product->title,
            'image' => $product->image,
        ]);
    }

    public function test_show_returns_a_404_for_a_missing_product()
    {
        // Fixed: show() now uses findOrFail() instead of find(), so a
        // missing id yields a clean 404 JSON error instead of a 200 with
        // a null body. See docs/TECHNICAL_DEBT.md for the history.
        $response = $this->getJson('/api/products/999');

        $response->assertNotFound();
    }

    public function test_store_creates_a_product_and_dispatches_product_created()
    {
        Queue::fake();

        $response = $this->postJson('/api/products', [
            'title' => 'New product',
            'image' => 'new-product.png',
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['title' => 'New product', 'image' => 'new-product.png']);

        $this->assertDatabaseHas('products', [
            'title' => 'New product',
            'image' => 'new-product.png',
        ]);

        Queue::assertPushed(ProductCreated::class, function (ProductCreated $job) {
            $data = $this->jobProperty($job, 'data');

            return $data['title'] === 'New product'
                && $data['image'] === 'new-product.png';
        });
    }

    public function test_store_without_a_title_fails_with_a_validation_error()
    {
        // Fixed: store() now validates the request before touching the
        // database, so a request missing 'title' is a clean 422 instead
        // of an unhandled 500 QueryException. See docs/TECHNICAL_DEBT.md.
        $response = $this->postJson('/api/products', [
            'image' => 'no-title.png',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title']);
        $this->assertDatabaseMissing('products', ['image' => 'no-title.png']);
    }

    public function test_store_without_an_image_fails_with_a_validation_error()
    {
        $response = $this->postJson('/api/products', [
            'title' => 'No image',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image']);
    }

    public function test_update_on_a_missing_product_returns_a_404()
    {
        // Fixed: update() now uses findOrFail() before applying the
        // request, so a missing id is a clean 404 instead of a fatal
        // "Call to a member function update() on null" error - the same
        // class of bug already documented (and fixed) on main's side for
        // ProductUpdated::handle() (see docs/TECHNICAL_DEBT.md #11).
        $response = $this->putJson('/api/products/999', [
            'title' => 'Does not exist',
            'image' => 'missing.png',
        ]);

        $response->assertNotFound();
    }

    public function test_update_without_a_title_fails_with_a_validation_error()
    {
        $product = Product::factory()->create();

        $response = $this->putJson("/api/products/{$product->id}", [
            'image' => 'still-here.png',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title']);
    }

    public function test_update_updates_a_product_and_dispatches_product_updated()
    {
        Queue::fake();
        $product = Product::factory()->create(['title' => 'Old title']);

        $response = $this->putJson("/api/products/{$product->id}", [
            'title' => 'New title',
            'image' => $product->image,
        ]);

        $response->assertStatus(202);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'title' => 'New title',
        ]);

        Queue::assertPushed(ProductUpdated::class, function (ProductUpdated $job) use ($product) {
            $data = $this->jobProperty($job, 'data');

            return $data['id'] === $product->id && $data['title'] === 'New title';
        });
    }

    public function test_destroy_deletes_a_product_and_dispatches_product_deleted()
    {
        Queue::fake();
        $product = Product::factory()->create();

        $response = $this->deleteJson("/api/products/{$product->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);

        // destroy($id) dispatches the raw route parameter, which Laravel
        // hands in as a string, not the model's integer id.
        Queue::assertPushed(ProductDeleted::class, function (ProductDeleted $job) use ($product) {
            return $this->jobProperty($job, 'id') === (string) $product->id;
        });
    }

    public function test_destroy_on_a_missing_product_still_dispatches_product_deleted()
    {
        // Product::destroy() on a non-existent id is a silent no-op in
        // Laravel, and destroy() dispatches unconditionally afterwards -
        // so main is told to delete a product that was never there. Real,
        // pre-existing behavior, documented rather than changed here.
        Queue::fake();

        $response = $this->deleteJson('/api/products/999');

        $response->assertStatus(204);
        Queue::assertPushed(ProductDeleted::class, function (ProductDeleted $job) {
            return $this->jobProperty($job, 'id') === '999';
        });
    }
}
