<?php

namespace App\Http\Middleware;

use App\Services\LicenseTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSchoolAccess
{
    public function __construct(private LicenseTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $school = $user?->school;
        $trialEnabled = (bool) config('school_erp.trial_enabled');
        $googleStates = $trialEnabled ? ['verified', 'bypassed_trial'] : ['verified'];
        $activationStates = $trialEnabled ? ['active', 'bypassed_trial'] : ['active'];

        if ($school === null
            || ! in_array($school->google_link_status, $googleStates, true)
            || ! in_array($school->activation_status, $activationStates, true)) {
            return response()->json([
                'error' => ['code' => 'SCHOOL_ACCESS_NOT_ACTIVE', 'message' => 'School setup or activation is not complete.'],
            ], 403);
        }

        $trialEnded = $school->currentLicense?->status === 'trial'
            && $school->trial_expires_at !== null
            && $school->trial_expires_at->isPast();
        if ($school->service_on_hold || $trialEnded) {
            $allowedWhileHeld = $request->routeIs(
                'auth.me',
                'auth.logout',
                'licensing.status',
                'licensing.trial-extension-requests.store',
                'billing.orders.index',
                'billing.orders.store',
                'billing.orders.simulate',
            );
            if (! $allowedWhileHeld) {
                return response()->json([
                    'error' => [
                        'code' => 'SCHOOL_ON_HOLD',
                        'message' => 'School services are paused. Sign in and billing renewal remain available.',
                    ],
                ], 423);
            }
        }

        $license = $school->currentLicense;
        if ($license !== null) {
            $verification = $this->tokens->verify($license->signed_token);
            $revoked = $license->status === 'revoked' || $school->license_status === 'suspended';
            $expired = ! ($verification['valid'] ?? false) || ($verification['state'] ?? null) === 'expired';
            $canRenew = $request->routeIs(
                'auth.me', 'auth.logout', 'licensing.status',
                'billing.orders.index', 'billing.orders.store', 'billing.orders.simulate',
                'licensing.trial-extension-requests.store',
            );
            if (! $request->isMethodSafe() && ! $canRenew && ($revoked || $expired)) {
                $code = $revoked ? 'LICENSE_REVOKED_READ_ONLY' : 'LICENSE_EXPIRED_READ_ONLY';

                return response()->json([
                    'error' => [
                        'code' => $code,
                        'message' => $revoked
                            ? 'The license was revoked. Existing records remain available for review and export.'
                            : 'The license has expired. Renew to resume changes; existing records remain available for review and export.',
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
