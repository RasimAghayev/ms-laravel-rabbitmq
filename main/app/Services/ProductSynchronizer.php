<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies an admin-originated product payload (from ProductCreated or
 * ProductUpdated) to main's own database.
 *
 * Why this exists: RabbitMQ's at-least-once delivery means a message can
 * be redelivered (ack lost, worker crash mid-job, a `--tries` retry after
 * a transient failure) - admin has no synchronous way to know whether its
 * message already landed, and only publishes, it never retracts. Plain
 * `Product::create()` crashes on a redelivered Created (duplicate primary
 * key) and plain `Product::find($id)->update()` crashes on an Updated
 * that arrives before its Created (requeue/retry can reorder messages
 * across a RabbitMQ queue with multiple consumers). Both failure modes
 * were real, documented gaps (see docs/TECHNICAL_DEBT.md #11 and the
 * admin-side equivalent) that permanently broke the replication for that
 * product once the job exhausted its retries into `failed_jobs`.
 *
 * Fix: ProductCreated and ProductUpdated both describe the same thing -
 * "this is the current state of this product as of `updated_at`" - so
 * both call this same upsert-if-newer routine:
 *
 *   - `lockForUpdate()` inside a transaction serializes concurrent writes
 *     to the *same* product row across multiple `main_worker` replicas
 *     (the horizontal-scaling case: N queue workers consuming the same
 *     queue, two of them happening to pick up two messages for the same
 *     product at the same time).
 *   - Comparing the incoming `updated_at` (set once, by admin, the single
 *     writer) against what's already stored is a simple last-write-wins
 *     rule - no vector clocks or sequence numbers needed, because there
 *     is exactly one authority for "when did this change" in this
 *     architecture. A stale/duplicate/out-of-order redelivery is
 *     recognized and skipped instead of clobbering fresher data.
 *   - `Product::upsert()` (MySQL `INSERT ... ON DUPLICATE KEY UPDATE`) is
 *     a single atomic statement, so the write itself can't race.
 *
 * Known, accepted limitation (not solved here - see docs/ARCHITECTURE.md
 * and docs/TECHNICAL_DEBT.md): a ProductDeleted message is still a plain
 * `Product::destroy()` with no tombstone. If a Created/Updated for the
 * same product is redelivered *after* its Deleted has already run, this
 * synchronizer has no record that the product was ever deleted and will
 * resurrect it. Building a tombstone table to close that window was
 * judged disproportionate to this repo's scope (KISS/YAGNI) - documented
 * as a real, intentional gap rather than silently left unexamined.
 */
class ProductSynchronizer
{
    /**
     * @param  array{id:int,title:string,image:string,created_at:string,updated_at:string}  $data
     */
    public function syncFromPayload(array $data): void
    {
        DB::transaction(function () use ($data) {
            $existing = Product::where('id', $data['id'])->lockForUpdate()->first();

            if ($existing !== null && ! $this->incomingIsNewer($existing, $data)) {
                return;
            }

            // Product::upsert() is a raw query-builder call - unlike
            // Product::create()/->save(), it never passes through
            // Eloquent's date-cast mutators. admin's real payload (built
            // from Product::toArray() in ProductController) serializes
            // created_at/updated_at as ISO-8601 with microseconds
            // ("2026-11-25T09:10:42.000000Z"), which MySQL's datetime
            // column rejects outright with a PDOException - caught by a
            // live end-to-end smoke test (admin -> RabbitMQ -> main),
            // not by the unit tests, which all hand-construct payloads
            // already in MySQL's own "Y-m-d H:i:s" format. Normalizing
            // through Carbon here, the same way incomingIsNewer() already
            // does, makes this robust to either format on the wire.
            Product::upsert(
                [[
                    'id' => $data['id'],
                    'title' => $data['title'],
                    'image' => $data['image'],
                    'created_at' => Carbon::parse($data['created_at'])->format('Y-m-d H:i:s'),
                    'updated_at' => Carbon::parse($data['updated_at'])->format('Y-m-d H:i:s'),
                ]],
                ['id'],
                ['title', 'image', 'created_at', 'updated_at']
            );
        });
    }

    private function incomingIsNewer(Product $existing, array $data): bool
    {
        return Carbon::parse($data['updated_at'])->greaterThan($existing->updated_at);
    }
}
