<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_always_reports_ok()
    {
        $response = $this->getJson('/health/live');

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);
    }

    public function test_ready_reports_ok_when_the_database_is_reachable()
    {
        $response = $this->getJson('/health/ready');

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);
    }

    public function test_ready_reports_503_when_the_database_is_unreachable()
    {
        // Point the default connection at a name config/database.php has
        // no entry for, so DB::connection()->getPdo() throws rather than
        // actually needing a dead database to test against. Restore the
        // original default before returning: RefreshDatabase's tearDown
        // still needs a real connection to roll back its transaction, and
        // leaving the default pointed at a nonexistent connection makes
        // that teardown crash with an uncaught InvalidArgumentException
        // instead of the test simply passing or failing on its own merits.
        $originalDefault = config('database.default');
        config(['database.default' => 'nonexistent-connection']);

        try {
            $response = $this->getJson('/health/ready');

            $response->assertStatus(503);
            $response->assertJson(['status' => 'unavailable', 'dependency' => 'database']);
        } finally {
            config(['database.default' => $originalDefault]);
        }
    }
}
