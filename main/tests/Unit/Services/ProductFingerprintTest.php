<?php

namespace Tests\Unit\Services;

use App\Models\Product;
use App\Services\ProductFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(int $id, string $updatedAt): Product
    {
        // main has no ProductFactory (its `id` is not auto-increment - it
        // always arrives from admin via the sync jobs) - same explicit
        // Product::create() convention already used by
        // tests/Unit/Jobs/ProductCreatedTest.php etc.
        return Product::create([
            'id' => $id,
            'title' => "Product {$id}",
            'image' => "product-{$id}.png",
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    public function test_it_is_independent_of_insertion_order()
    {
        // admin and main each query their own database, with no
        // guarantee their storage engines return rows in the same
        // physical order - the whole point of ->orderBy('id') inside
        // ProductFingerprint::compute() is to make the checksum agree
        // regardless of insertion order on either side.
        $this->createProduct(2, '2026-01-01 00:00:00');
        $this->createProduct(1, '2026-01-02 00:00:00');

        $fingerprint = (new ProductFingerprint())->compute();

        Product::query()->delete();

        $this->createProduct(1, '2026-01-02 00:00:00');
        $this->createProduct(2, '2026-01-01 00:00:00');

        $reinserted = (new ProductFingerprint())->compute();

        $this->assertSame($fingerprint['checksum'], $reinserted['checksum']);
        $this->assertSame(2, $fingerprint['count']);
    }

    public function test_an_empty_table_still_produces_a_stable_checksum()
    {
        $fingerprint = (new ProductFingerprint())->compute();

        $this->assertSame(0, $fingerprint['count']);
        $this->assertSame(hash('sha256', ''), $fingerprint['checksum']);
    }

    public function test_changing_updated_at_changes_the_checksum()
    {
        $product = $this->createProduct(1, '2026-01-01 00:00:00');

        $before = (new ProductFingerprint())->compute();

        $product->forceFill(['updated_at' => '2026-01-02 00:00:00'])->save();

        $after = (new ProductFingerprint())->compute();

        $this->assertNotSame($before['checksum'], $after['checksum']);
    }
}
