<?php

namespace App\Http\Controllers;

use App\Services\ProductFingerprint;

/**
 * Internal-only (see VerifyInternalToken) endpoint `main`'s sync:reconcile
 * command polls to detect drift between the two databases. Not part of
 * the public products API.
 */
class InternalConsistencyController extends Controller
{
    public function products(ProductFingerprint $fingerprint)
    {
        return response()->json($fingerprint->compute());
    }
}
