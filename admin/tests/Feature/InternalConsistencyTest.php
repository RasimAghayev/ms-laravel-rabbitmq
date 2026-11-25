<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.internal_sync_token' => 'test-shared-secret']);
    }

    public function test_it_rejects_a_request_with_no_token()
    {
        $response = $this->getJson('/api/internal/consistency/products');

        $response->assertForbidden();
    }

    public function test_it_rejects_a_request_with_the_wrong_token()
    {
        $response = $this->withHeaders(['X-Internal-Token' => 'the-wrong-secret'])
            ->getJson('/api/internal/consistency/products');

        $response->assertForbidden();
    }

    public function test_it_rejects_every_request_when_no_token_is_configured()
    {
        config(['services.internal_sync_token' => null]);

        $response = $this->withHeaders(['X-Internal-Token' => 'anything'])
            ->getJson('/api/internal/consistency/products');

        $response->assertForbidden();
    }

    public function test_it_returns_the_fingerprint_for_a_valid_token()
    {
        Product::factory()->count(3)->create();

        $response = $this->withHeaders(['X-Internal-Token' => 'test-shared-secret'])
            ->getJson('/api/internal/consistency/products');

        $response->assertOk();
        $response->assertJsonStructure(['count', 'checksum', 'generated_at']);
        $response->assertJson(['count' => 3]);
    }

    public function test_the_checksum_changes_when_a_product_is_updated()
    {
        $product = Product::factory()->create();

        $before = $this->withHeaders(['X-Internal-Token' => 'test-shared-secret'])
            ->getJson('/api/internal/consistency/products')
            ->json();

        $product->forceFill(['updated_at' => now()->addMinute()])->save();

        $after = $this->withHeaders(['X-Internal-Token' => 'test-shared-secret'])
            ->getJson('/api/internal/consistency/products')
            ->json();

        $this->assertSame(1, $before['count']);
        $this->assertSame($before['count'], $after['count']);
        $this->assertNotSame($before['checksum'], $after['checksum']);
    }
}
