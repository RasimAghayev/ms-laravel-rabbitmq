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

    public function test_handle_is_idempotent_against_redelivery_of_the_same_message()
    {
        // RabbitMQ's at-least-once delivery can redeliver a message the
        // worker already processed (ack lost, crash after processing but
        // before ack). A plain Product::create() would throw on the
        // duplicate primary key and, after --tries is exhausted, strand
        // the product in failed_jobs. The shared ProductSynchronizer
        // upserts instead, so a redelivered Created is a safe no-op.
        $payload = [
            'id' => 42,
            'title' => 'Synced product',
            'image' => 'synced-product.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];

        (new ProductCreated($payload))->handle();
        (new ProductCreated($payload))->handle();

        $this->assertSame(1, Product::count());
        $this->assertDatabaseHas('products', ['id' => 42, 'title' => 'Synced product']);
    }

    public function test_handle_does_not_overwrite_a_newer_update_with_a_stale_redelivered_create()
    {
        // Out-of-order delivery: if ProductUpdated (newer updated_at) is
        // processed first and a redelivered/retried ProductCreated for
        // the same id (older updated_at) arrives after, the stale
        // Created must not clobber the fresher data. Last-write-wins by
        // admin's own updated_at timestamp - the single source of truth
        // for "when did this change" in this architecture.
        Product::create([
            'id' => 42,
            'title' => 'Already updated title',
            'image' => 'already-updated.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ]);

        $staleCreate = new ProductCreated([
            'id' => 42,
            'title' => 'Stale original title',
            'image' => 'stale-original.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $staleCreate->handle();

        $this->assertSame(1, Product::count());
        $this->assertDatabaseHas('products', [
            'id' => 42,
            'title' => 'Already updated title',
            'image' => 'already-updated.png',
        ]);
    }

    public function test_handle_accepts_the_real_wire_format_admin_actually_sends()
    {
        // Regression test: admin dispatches ProductCreated/ProductUpdated
        // with ProductController's Product::toArray() as the payload, so
        // created_at/updated_at arrive as ISO-8601 with microseconds
        // ("2026-11-25T09:10:42.000000Z" - Eloquent's default JSON/array
        // date format), never plain "Y-m-d H:i:s". Every other test here
        // uses the plain format for readability and happened to mask a
        // real bug: ProductSynchronizer::syncFromPayload() used to pass
        // these straight into Product::upsert(), a raw query-builder
        // call with no date-cast mutator, which MySQL rejected outright
        // (PDOException: Incorrect datetime value). Caught by a live
        // admin -> RabbitMQ -> main smoke test, not by any unit test
        // until this one.
        $job = new ProductCreated([
            'id' => 42,
            'title' => 'Real wire format product',
            'image' => 'real-wire-format.png',
            'created_at' => '2026-11-25T09:10:42.000000Z',
            'updated_at' => '2026-11-25T09:10:42.000000Z',
        ]);

        $job->handle();

        $this->assertDatabaseHas('products', [
            'id' => 42,
            'title' => 'Real wire format product',
            'created_at' => '2026-11-25 09:10:42',
            'updated_at' => '2026-11-25 09:10:42',
        ]);
    }
}
