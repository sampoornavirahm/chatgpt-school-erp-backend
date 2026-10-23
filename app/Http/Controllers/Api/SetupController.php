<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LicensePlan;
use App\Models\School;
use App\Models\User;
use App\Services\ActivationProvider;
use App\Services\GoogleIdentityVerifier;
use App\Services\SchoolLicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SetupController extends Controller
{
    public function status(): JsonResponse
    {
        $state = DB::table('installation_state')->where('id', 1)->first();
        $trialEnabled = (bool) config('school_erp.trial_enabled');
        $school = School::query()->first();
        $configured = $state?->school_id !== null || $school !== null;
        $acceptedGoogleStates = $trialEnabled ? ['verified', 'bypassed_trial'] : ['verified'];
        $acceptedActivationStates = $trialEnabled ? ['active', 'bypassed_trial'] : ['active'];
        $accessReady = ! $configured || ($school !== null
            && in_array($school->google_link_status, $acceptedGoogleStates, true)
            && in_array($school->activation_status, $acceptedActivationStates, true));

        return response()->json([
            'configured' => $configured,
            'access_ready' => $configured && $accessReady,
            'trial_enabled' => $trialEnabled,
            'google_account_required' => ! $trialEnabled,
            'server_activation_required' => ! $trialEnabled,
            'google_link_status' => $school?->google_link_status,
            'activation_status' => $school?->activation_status,
            'license_plan' => $school?->license_plan_key,
            'license_status' => $school?->license_status,
            'trial_started_at' => $school?->trial_started_at?->toIso8601String(),
            'trial_expires_at' => $school?->trial_expires_at?->toIso8601String(),
        ]);
    }

    public function complete(
        Request $request,
        GoogleIdentityVerifier $googleVerifier,
        ActivationProvider $activationProvider,
        SchoolLicenseService $licenses,
    ): JsonResponse {
        $data = $request->validate([
            'school_name' => ['required', 'string', 'min:2', 'max:160'],
            'admin_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'google_id_token' => ['nullable', 'string', 'max:8192'],
            'activation_code' => ['nullable', 'string', 'max:256'],
        ]);

        if (DB::table('installation_state')->where('id', 1)->value('school_id') !== null
            || School::query()->exists()) {
            return response()->json([
                'error' => ['code' => 'SETUP_ALREADY_COMPLETED', 'message' => 'School setup has already been completed.'],
            ], 409);
        }

        $trialEnabled = (bool) config('school_erp.trial_enabled');
        $schoolId = (string) Str::uuid();
        $serverId = (string) Str::uuid();
        $googleIdentity = null;
        $activation = null;

        if (! $trialEnabled) {
            if (empty($data['google_id_token']) || empty($data['activation_code'])) {
                throw ValidationException::withMessages([
                    'setup' => ['Attach the school administrator Google account and enter the server activation code.'],
                ]);
            }

            try {
                $googleIdentity = $googleVerifier->verify($data['google_id_token']);
                if (strcasecmp($googleIdentity['email'], $data['email']) !== 0) {
                    throw new RuntimeException('Use the verified Google account email.');
                }
                $activation = $activationProvider->activate($data['activation_code'], $schoolId, $serverId);
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'setup' => ['Google account verification or server activation failed. Check the setup details and try again.'],
                ]);
            }
        }

        $result = DB::transaction(function () use (
            $data,
            $trialEnabled,
            $schoolId,
            $serverId,
            $googleIdentity,
            $activation,
            $licenses,
        ): array {
            $state = DB::table('installation_state')->where('id', 1)->lockForUpdate()->first();
            if ($state?->school_id !== null || School::query()->exists()) {
                return ['conflict' => true];
            }

            $school = School::query()->create([
                'id' => $schoolId,
                'name' => $data['school_name'],
                'server_id' => $serverId,
                'google_subject' => $googleIdentity['sub'] ?? null,
                'google_email' => $googleIdentity['email'] ?? null,
                'google_link_status' => $trialEnabled ? 'bypassed_trial' : 'verified',
                'activation_status' => $trialEnabled ? 'bypassed_trial' : 'active',
                'activation_id' => $activation['activation_id'] ?? null,
                'activated_at' => $trialEnabled ? null : now(),
                'trial_started_at' => $trialEnabled ? now() : null,
                'license_plan_key' => 'starter',
                'license_status' => $trialEnabled ? 'trial' : 'active',
                'active_student_limit' => 100,
                'active_teacher_limit' => 1,
            ]);

            $starter = LicensePlan::query()->where('key', 'starter')->firstOrFail();
            if ($trialEnabled) {
                $license = $licenses->startTrial($school);
            } else {
                $license = $licenses->issue($school, $starter, $starter->student_limit, $starter->teacher_limit, null, null);
                $school->forceFill(['activation_id' => $activation['activation_id']])->save();
            }

            $user = User::query()->create([
                'school_id' => $school->id,
                'name' => $data['admin_name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'role' => 'school_admin',
                'is_active' => true,
            ]);

            DB::table('installation_state')->where('id', 1)->update([
                'school_id' => $school->id,
                'setup_completed_at' => now(),
                'updated_at' => now(),
            ]);

            return ['conflict' => false, 'school' => $school->refresh(), 'user' => $user, 'license' => $license];
        });

        if ($result['conflict']) {
            return response()->json([
                'error' => ['code' => 'SETUP_ALREADY_COMPLETED', 'message' => 'School setup has already been completed.'],
            ], 409);
        }

        /** @var User $user */
        $user = $result['user'];
        $token = $user->createToken('initial-setup', ['school-admin'])->plainTextToken;

        return response()->json([
            'configured' => true,
            'trial_enabled' => $trialEnabled,
            'google_link_status' => $result['school']->google_link_status,
            'activation_status' => $result['school']->activation_status,
            'trial_expires_at' => $result['school']->trial_expires_at?->toIso8601String(),
            'license_status' => $result['school']->license_status,
            'activation_id' => $result['school']->activation_id,
            'license_token' => $result['license']->signed_token,
            'token' => $token,
            'user' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role],
        ], 201);
    }

    public function activate(
        Request $request,
        GoogleIdentityVerifier $googleVerifier,
        SchoolLicenseService $licenses,
    ): JsonResponse {
        $data = $request->validate([
            'google_id_token' => ['required', 'string', 'max:8192'],
            'activation_code' => ['required', 'string', 'max:256'],
        ]);

        $school = School::query()->first();
        $admin = $school?->users()->where('role', 'school_admin')->first();
        if ($school === null || $admin === null) {
            return response()->json([
                'error' => ['code' => 'SCHOOL_SETUP_REQUIRED', 'message' => 'Complete school setup before activation.'],
            ], 409);
        }

        try {
            $identity = $googleVerifier->verify($data['google_id_token']);
            if (strcasecmp($identity['email'], $admin->email) !== 0) {
                throw new RuntimeException('Account mismatch.');
            }
            $enrollment = $licenses->requestEnrollment($data['activation_code'], $school->server_id, $identity);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'activation' => ['Google account verification or school code validation failed. Check the details and try again.'],
            ]);
        }

        $payload = [
            'configured' => true,
            'access_ready' => $enrollment['status'] === 'approved',
            'google_link_status' => 'verified',
            'activation_status' => $enrollment['status'] === 'approved' ? 'active' : 'pending_approval',
            'enrollment_status' => $enrollment['status'],
        ];
        if ($enrollment['status'] === 'approved') {
            $payload['license_status'] = $enrollment['school']->currentLicense?->status;
            $payload['license_plan'] = $enrollment['school']->currentLicense?->plan_key;
            $payload['license_token'] = $enrollment['school']->currentLicense?->signed_token;
        }

        return response()->json($payload, $enrollment['status'] === 'approved' ? 200 : 202);
    }
}
