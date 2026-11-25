<?php

namespace App\Services;

use App\Models\Product;

/**
 * main's own half of the admin/main consistency check - see
 * App\Services\ProductFingerprint in admin for the full rationale (kept
 * identical between the two apps, same convention as the App\Jobs\* sync
 * classes: no shared package between these two independently deployable
 * apps, just an identical-by-convention contract).
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
