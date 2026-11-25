<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards admin's internal-only endpoints (currently just the consistency
 * fingerprint `main`'s sync:reconcile command calls) with a shared secret
 * - these endpoints are reachable on the same host port as the public
 * products API, so they need their own check rather than relying on
 * "only main calls this" network-topology assumptions.
 *
 * Deliberately a single shared-secret header, not Sanctum/OAuth: this is
 * service-to-service traffic between two apps under the same operator's
 * control, not a third-party-facing API - matching the scope already in
 * `docs/ARCHITECTURE.md` (no auth exists on the public products API
 * either; adding real user-facing auth there is out of scope for this
 * change, see docs/TECHNICAL_DEBT.md).
 */
class VerifyInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.internal_sync_token');

        if (empty($expected) || ! hash_equals($expected, (string) $request->header('X-Internal-Token'))) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
