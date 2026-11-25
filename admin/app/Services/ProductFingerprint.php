<?php

namespace App\Services;

use App\Models\Product;

/**
 * A cheap, comparable summary of this app's own `products` table, used to
 * detect drift between admin (write side) and main (read side) without
 * either app ever reading the other's database directly.
 *
 * Why this exists: the queue-based replication between admin and main has
 * no reconciliation or conflict-detection step (see docs/ARCHITECTURE.md
 * "Known, accepted limitation") - if a message is ever lost outright (not
 * redelivered, just never arrives: a worker crash that loses an unacked
 * message, a queue purge, an operator mistake), nothing before this
 * existed to notice. This class is the data half of that detection; `main`
 * fetches admin's fingerprint over HTTP (see the `sync:reconcile` command
 * in main) and compares it to its own.
 *
 * Deliberately NOT a cryptographic integrity check and NOT a full
 * row-level diff (either would be disproportionate to this repo's scale -
 * KISS/YAGNI) - a row count plus an order-independent checksum of
 * `id:updated_at` pairs is enough to catch "main is missing a product" or
 * "main has a stale copy of a product" without shipping full product
 * payloads over the reconciliation endpoint.
 */
class ProductFingerprint
{
    public function compute(): array
    {
        $rows = Product::query()
            ->orderBy('id')
            ->pluck('updated_at', 'id');

        $checksum = hash('sha256', $rows->map(
            fn ($updatedAt, $id) => $id.':'.$this->normalize($updatedAt)
        )->implode('|'));

        return [
            'count' => $rows->count(),
            'checksum' => $checksum,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function normalize($updatedAt): string
    {
        return $updatedAt instanceof \DateTimeInterface
            ? $updatedAt->format('Y-m-d H:i:s')
            : (string) $updatedAt;
    }
}
