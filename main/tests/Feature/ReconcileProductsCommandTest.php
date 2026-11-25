<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReconcileProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.admin.base_url' => 'http://admin-under-test',
            'services.admin.internal_sync_token' => 'test-shared-secret',
        ]);
    }

    public function test_it_reports_success_and_exits_zero_when_both_sides_agree()
    {
        Product::create([
            'id' => 1,
            'title' => 'Product 1',
            'image' => 'product-1.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $local = (new \App\Services\ProductFingerprint())->compute();

        Http::fake([
            'http://admin-under-test/api/internal/consistency/products' => Http::response([
                'count' => $local['count'],
                'checksum' => $local['checksum'],
                'generated_at' => now()->toIso8601String(),
            ]),
        ]);

        $this->artisan('sync:reconcile')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Internal-Token', 'test-shared-secret')
                && $request->url() === 'http://admin-under-test/api/internal/consistency/products';
        });
    }

    public function test_it_exits_non_zero_and_logs_a_warning_on_drift()
    {
        Product::create([
            'id' => 1,
            'title' => 'Product 1',
            'image' => 'product-1.png',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        Http::fake([
            'http://admin-under-test/*' => Http::response([
                'count' => 999,
                'checksum' => 'totally-different-checksum',
                'generated_at' => now()->toIso8601String(),
            ]),
        ]);

        Log::spy();

        $this->artisan('sync:reconcile')->assertExitCode(1);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => $message === 'sync:reconcile detected drift between admin and main.')
            ->once();
    }

    public function test_it_fails_cleanly_when_admin_is_unreachable()
    {
        Http::fake([
            'http://admin-under-test/*' => Http::response('', 500),
        ]);

        $this->artisan('sync:reconcile')->assertExitCode(1);
    }

    public function test_it_fails_cleanly_when_not_configured()
    {
        config([
            'services.admin.base_url' => null,
            'services.admin.internal_sync_token' => null,
        ]);

        $this->artisan('sync:reconcile')->assertExitCode(1);
    }
}
