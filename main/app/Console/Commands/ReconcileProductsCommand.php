<?php

namespace App\Console\Commands;

use App\Services\ProductFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drift detector for the admin -> main RabbitMQ replication (see
 * docs/ARCHITECTURE.md). The queue gives at-least-once delivery, not
 * exactly-once or guaranteed-eventually-delivered: a message stuck in
 * `failed_jobs` after exhausting its retries, a worker crash before ack,
 * or a bug in a future job class can all leave main quietly diverged from
 * admin with nothing in either app's logs to say so.
 *
 * This command is the cheap, read-only check: it asks admin for its own
 * App\Services\ProductFingerprint (count + an order-independent checksum
 * of every `id:updated_at` pair) over the internal-only endpoint guarded
 * by VerifyInternalToken, computes the identical fingerprint locally, and
 * logs a warning when they disagree. It does NOT attempt to resolve the
 * drift itself - reconciling would mean either replaying admin's history
 * (which this architecture never stores) or a full resync (a product-level
 * decision, not an automated one), so this command only raises the alarm.
 *
 * Scheduled (see App\Console\Kernel::schedule) rather than queued, because
 * it must run even if the queue itself is the thing that's broken.
 */
class ReconcileProductsCommand extends Command
{
    protected $signature = 'sync:reconcile';

    protected $description = 'Compare main\'s product fingerprint against admin\'s to detect replication drift';

    public function handle(ProductFingerprint $fingerprint): int
    {
        $baseUrl = config('services.admin.base_url');
        $token = config('services.admin.internal_sync_token');

        if (empty($baseUrl) || empty($token)) {
            $this->error('ADMIN_BASE_URL / INTERNAL_SYNC_TOKEN are not configured - skipping.');
            Log::warning('sync:reconcile skipped: admin base URL or internal sync token not configured.');

            return self::FAILURE;
        }

        $local = $fingerprint->compute();

        try {
            $response = Http::withHeaders(['X-Internal-Token' => $token])
                ->timeout(10)
                ->get(rtrim($baseUrl, '/').'/api/internal/consistency/products');
        } catch (\Throwable $e) {
            $this->error("Could not reach admin: {$e->getMessage()}");
            Log::error('sync:reconcile could not reach admin.', ['exception' => $e->getMessage()]);

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("admin returned HTTP {$response->status()}");
            Log::error('sync:reconcile got a non-2xx response from admin.', ['status' => $response->status()]);

            return self::FAILURE;
        }

        $remote = $response->json();

        if ($remote['count'] === $local['count'] && $remote['checksum'] === $local['checksum']) {
            $this->info("In sync: {$local['count']} products, checksum {$local['checksum']}.");

            return self::SUCCESS;
        }

        $this->warn('Drift detected between admin and main.');
        $this->line("  admin: count={$remote['count']} checksum={$remote['checksum']}");
        $this->line("  main:  count={$local['count']} checksum={$local['checksum']}");

        Log::warning('sync:reconcile detected drift between admin and main.', [
            'admin_count' => $remote['count'],
            'admin_checksum' => $remote['checksum'],
            'main_count' => $local['count'],
            'main_checksum' => $local['checksum'],
        ]);

        // Non-zero so a human/alerting pipeline watching this scheduled
        // command's exit code notices - deliberately not a crash/exception,
        // since drift is an expected, handled condition of an
        // at-least-once system, not a bug in this command.
        return self::FAILURE;
    }
}
