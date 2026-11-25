<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Health endpoints
|--------------------------------------------------------------------------
|
| Split liveness from readiness (standing decision: dual-mode/
| distributed-systems hardening) - the container's own HEALTHCHECK
| (admin/Dockerfile) hits /health/ready, so an admin that's up but can't
| reach its database is correctly reported unhealthy rather than "fine"
| just because nginx/php-fpm answer.
|
*/

Route::get('/health/live', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/health/ready', function () {
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        return response()->json(['status' => 'unavailable', 'dependency' => 'database'], 503);
    }

    return response()->json(['status' => 'ok']);
});
