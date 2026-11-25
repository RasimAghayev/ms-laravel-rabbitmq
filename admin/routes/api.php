<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use \App\Http\Controllers\InternalConsistencyController;
use \App\Http\Controllers\ProductController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::apiResource('products',ProductController::class);

// Internal reconciliation endpoint - see VerifyInternalToken and
// App\Services\ProductFingerprint. Polled by main's `sync:reconcile`
// artisan command, never by a public client.
Route::get('internal/consistency/products', [InternalConsistencyController::class, 'products'])
    ->middleware('internal.token');