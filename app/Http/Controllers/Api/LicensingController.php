<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivationCode;
use App\Models\LicensePlan;
use App\Models\School;
use App\Models\SchoolLicense;
use App\Models\TrialExtensionRequest;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use App\Services\LicenseTokenService;
use App\Services\SchoolLicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class LicensingController extends Controller
{
    public function plans(): JsonResponse
    {
        $plans = LicensePlan::query()->where('is_enabled', true)->orderBy('sort_order')->orderBy('id')->get([
            'key', 'name', 'description', 'currency', 'billing_period', 'billing_mode',
            'school_price_paise', 'student_price_paise', 'parent_price_paise', 'teacher_price_paise',
            'student_limit', 'teacher_limit', 'feature_flags', 'ad_supported',
        ]);

        return response()->json(['currency' => 'INR', 'plans' => $plans]);
    }

    public function currentForSchool(Request $request, SchoolLicenseService $licenses): JsonResponse
    {
        $data = $request->validate(['school_id' => ['required', 'uuid'], 'server_id' => ['required', 'uuid']]);
        $school = School::query()->whereKey($data['school_id'])->where('server_id', $data['server_id'])->first();
        abort_if($school === null, 404);
        $license = $school->currentLicense;

        $trialEnded = $license?->status === 'trial' && $school->trial_expires_at?->isPast();
        if ($trialEnded && ! ($license?->claims['service_on_hold'] ?? false)) {
            $license = $licenses->refreshServiceAccessToken($school, true);
        }

        return response()->json([
            'service_on_hold' => (bool) $school->service_on_hold || (bool) $trialEnded,
            'trial_extension_requested' => $school->trial_extension_requested_at !== null,
            'license' => $license === null ? null : $this->licensePayload($license),
        ]);
    }

    public function publicKey(LicenseTokenService $tokens): JsonResponse
    {
        try {
            return response()->json([
                'algorithm' => 'Ed25519',
                'key_id' => config('school_erp.license_key_id'),
                'public_key' => $tokens->publicKey(),
                'issuer' => config('school_erp.license_issuer'),
                'google_client_id' => config('school_erp.google_client_id'),
            ]);
        } catch (RuntimeException) {
            return response()->json(['error' => ['code' => 'LICENSE_KEYS_NOT_CONFIGURED', 'message' => 'License verification is not configured.']], 503);
        }
    }

    public function enrollmentConfig(): JsonResponse
    {
        $clientId = (string) config('school_erp.google_client_id');
        abort_if($clientId === '', 503, 'Google sign-in is not configured.');

        return response()->json(['google_client_id' => $clientId]);
    }

    public function verify(Request $request, LicenseTokenService $tokens): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:8192']]);
        $verification = $tokens->verify($data['token']);
        if (! $verification['valid']) {
            return response()->json(['valid' => false], 422);
        }

        return response()->json($verification);
    }

    public function activate(
        Request $request,
        SchoolLicenseService $licenses,
        GoogleIdentityVerifier $googleVerifier,
    ): JsonResponse {
        $data = $request->validate([
            'school_id' => ['required', 'uuid'],
            'server_id' => ['required', 'uuid'],
            'activation_code' => ['required', 'string', 'max:128'],
            'google_id_token' => ['nullable', 'string', 'max:8192'],
        ]);
        $school = School::query()->whereKey($data['school_id'])->where('server_id', $data['server_id'])->firstOrFail();
        abort_unless($school->google_link_status === 'verified' && $school->activation_status === 'active', 409, 'School activation is waiting for operator approval.');
        $result = $licenses->activate($data['school_id'], $data['server_id'], $data['activation_code']);

        DB::table('relay_audit_logs')->insert([
            'school_id' => $data['school_id'],
            'actor_user_id' => null,
            'action' => $result['idempotent'] ? 'license_activation_retried' : 'license_activated',
            'target_type' => 'license',
            'target_id' => $result['license']->id,
            'details' => json_encode(['plan' => $result['license']->plan_key], JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'idempotent' => $result['idempotent'],
            'license' => $this->licensePayload($result['license']),
        ]);
    }

    public function enroll(Request $request, SchoolLicenseService $licenses, GoogleIdentityVerifier $googleVerifier): JsonResponse
    {
        $data = $request->validate([
            'server_id' => ['required', 'uuid'],
            'activation_code' => ['required', 'string', 'max:128'],
            'google_id_token' => ['required', 'string', 'max:8192'],
        ]);
        try {
            $identity = $googleVerifier->verify($data['google_id_token']);
        } catch (Throwable) {
            throw ValidationException::withMessages(['google_id_token' => ['Google sign-in could not be verified. Try again.']]);
        }

        $result = $licenses->requestEnrollment($data['activation_code'], $data['server_id'], $identity);
        DB::table('relay_audit_logs')->insert([
            'school_id' => $result['school']->id,
            'actor_user_id' => null,
            'action' => 'school_enrollment_requested',
            'target_type' => 'activation_code',
            'target_id' => $result['activation_code']->id,
            'details' => json_encode(['googleEmail' => $identity['email'], 'serverId' => $data['server_id']], JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => $result['status'],
            'school' => ['id' => $result['school']->id, 'name' => $result['school']->name],
        ], $result['status'] === 'approved' ? 200 : 202);
    }

    public function enrollmentStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_id' => ['required', 'uuid'],
            'activation_code' => ['required', 'string', 'max:128'],
        ]);
        $activationCode = ActivationCode::query()->where('code_hash', hash('sha256', $data['activation_code']))->first();
        abort_if($activationCode === null || $activationCode->requested_server_id !== $data['server_id'], 404);
        abort_if($activationCode->code_expires_at?->isPast(), 404);
        $school = School::query()->whereKey($activationCode->school_id)->where('server_id', $data['server_id'])->firstOrFail();

        if ($activationCode->consumed_at === null || $activationCode->school_license_id === null) {
            return response()->json([
                'status' => 'pending_operator_approval',
                'school' => ['id' => $school->id, 'name' => $school->name],
            ], 202);
        }

        $license = SchoolLicense::query()->whereKey($activationCode->school_license_id)->firstOrFail();
        abort_if($license->status === 'revoked', 409, 'This school license has been revoked.');
        $credential = DB::table('school_relay_credentials')->where('school_id', $school->id)->where('is_active', true)->first();
        abort_if($credential === null, 503, 'School cloud sync is not ready yet.');

        return response()->json([
            'status' => 'approved',
            'school' => ['id' => $school->id, 'name' => $school->name],
            'license' => $this->licensePayload($license),
            'relay' => [
                'url' => url('/api/v1'),
                'school_id' => $school->id,
                'key_id' => $credential->key_id,
                'secret' => Crypt::decryptString($credential->secret_ciphertext),
            ],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $school = $user->school;
        $license = $school?->currentLicense;
        $verification = $license === null ? ['valid' => false] : app(LicenseTokenService::class)->verify($license->signed_token);
        $state = $license?->status ?? $school?->license_status ?? 'unlicensed';
        if ($license?->status !== 'revoked' && ($verification['state'] ?? null) === 'grace') {
            $state = 'grace';
        } elseif ($license?->status !== 'revoked' && ($verification['state'] ?? null) === 'expired') {
            $state = 'expired';
        }

        return response()->json([
            'status' => $state,
            'trial_started_at' => $school?->trial_started_at?->toIso8601String(),
            'trial_expires_at' => $school?->trial_expires_at?->toIso8601String(),
            'trial_extended' => $school?->trial_extended_at !== null,
            'trial_extension_requested' => $school?->trial_extension_requested_at !== null,
            'service_on_hold' => (bool) $school?->service_on_hold
                || ($license?->status === 'trial' && (bool) $school?->trial_expires_at?->isPast()),
            'archive_access' => [
                'enabled' => (bool) $school?->archive_access_enabled
                    && ($school?->archive_access_starts_at === null || $school->archive_access_starts_at->isPast())
                    && ($school?->archive_access_ends_at === null || $school->archive_access_ends_at->isFuture()),
                'starts_at' => $school?->archive_access_starts_at?->toIso8601String(),
                'ends_at' => $school?->archive_access_ends_at?->toIso8601String(),
                'free_reads_limit' => $school?->archive_free_reads_limit ?? 0,
                'query_price_paise' => $school?->archive_query_price_paise,
            ],
            'license' => $license === null ? null : $this->licensePayload($license),
            'data_access_retained' => true,
        ]);
    }

    public function requestTrialExtension(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === 'school_admin', 403);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $school = $user->school;
        abort_if($school === null || $school->license_status !== 'trial', 409, 'Only a school on trial may request an extension.');
        abort_if($school->trial_extended_at !== null, 409, 'The trial extension has already been used.');

        $result = DB::transaction(function () use ($school, $user, $data): array {
            $lockedSchool = School::query()->whereKey($school->id)->lockForUpdate()->firstOrFail();
            if ($lockedSchool->trial_extended_at !== null) {
                abort(409, 'The trial extension has already been used.');
            }
            $pending = TrialExtensionRequest::query()->where('school_id', $lockedSchool->id)->where('status', 'pending')->exists();
            if ($pending) {
                return ['duplicate' => true];
            }
            $extension = TrialExtensionRequest::query()->create([
                'school_id' => $lockedSchool->id,
                'requested_by' => $user->id,
                'status' => 'pending',
                'extension_days' => 15,
                'school_note' => $data['note'] ?? null,
                'requested_at' => now(),
            ]);
            $lockedSchool->forceFill(['trial_extension_requested_at' => now()])->save();

            return ['duplicate' => false, 'request' => $extension];
        });

        return response()->json(['status' => 'pending', 'duplicate' => $result['duplicate']], $result['duplicate'] ? 200 : 202);
    }

    public function requestTrialExtensionFromServer(Request $request): JsonResponse
    {
        $data = $request->validate(['school_id' => ['required', 'uuid'], 'server_id' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:500']]);
        $school = School::query()->whereKey($data['school_id'])->where('server_id', $data['server_id'])->firstOrFail();
        abort_if($school->license_status !== 'trial' || $school->trial_extended_at !== null, 409, 'This school is not eligible for another trial extension.');
        $admin = User::query()->where('school_id', $school->id)->where('role', 'school_admin')->orderBy('id')->firstOrFail();

        $result = DB::transaction(function () use ($school, $admin, $data): bool {
            $lockedSchool = School::query()->whereKey($school->id)->lockForUpdate()->firstOrFail();
            if ($lockedSchool->trial_extended_at !== null) {
                abort(409, 'This school is not eligible for another trial extension.');
            }
            if (TrialExtensionRequest::query()->where('school_id', $school->id)->where('status', 'pending')->exists()) {
                return true;
            }
            TrialExtensionRequest::query()->create([
                'school_id' => $school->id,
                'requested_by' => $admin->id,
                'status' => 'pending',
                'extension_days' => 15,
                'school_note' => $data['note'] ?? 'Requested from the local School Hub.',
                'requested_at' => now(),
            ]);
            $lockedSchool->forceFill(['trial_extension_requested_at' => now()])->save();

            return false;
        });

        return response()->json(['status' => 'pending', 'duplicate' => $result], $result ? 200 : 202);
    }

    /** @return array<string, mixed> */
    private function licensePayload(SchoolLicense $license): array
    {
        return [
            'id' => $license->id,
            'plan' => $license->plan_key,
            'status' => $license->status,
            'limits' => $license->claims['limits'] ?? [],
            'feature_flags' => $license->claims['feature_flags'] ?? [],
            'currency' => $license->claims['currency'] ?? 'INR',
            'expires_at' => $license->valid_until?->toIso8601String(),
            'grace_until' => $license->grace_until?->toIso8601String(),
            'trial' => (bool) ($license->claims['trial'] ?? false),
            'trial_extended' => (bool) ($license->claims['trial_extended'] ?? false),
            'service_on_hold' => (bool) ($license->claims['service_on_hold'] ?? false),
            'token' => $license->signed_token,
        ];
    }
}
