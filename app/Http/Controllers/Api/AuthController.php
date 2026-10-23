<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
            'role' => ['required', 'in:teacher,parent,school_admin'],
            'school_id' => ['nullable', 'uuid'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        if ($data['role'] !== 'school_admin') {
            return response()->json([
                'error' => ['code' => 'GOOGLE_SIGN_IN_REQUIRED', 'message' => 'Parents and teachers must sign in with their registered Google account.'],
            ], 403);
        }

        $users = User::query()
            ->where('email', strtolower($data['email']))
            ->where('role', $data['role'])
            ->where('is_active', true);
        if (! empty($data['school_id'])) {
            $users->where('school_id', $data['school_id']);
        }
        $matches = $users->limit(2)->get();
        if (empty($data['school_id']) && $matches->count() > 1) {
            return response()->json([
                'error' => ['code' => 'SCHOOL_ID_REQUIRED', 'message' => 'Enter the school ID supplied by your school operator.'],
            ], 422);
        }
        $user = $matches->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'Email or password is incorrect.'],
            ], 401);
        }

        if (! $this->schoolAccessReady($user)) {
            return response()->json([
                'error' => ['code' => 'SCHOOL_ACCESS_NOT_ACTIVE', 'message' => 'School setup or activation is not complete.'],
            ], 403);
        }

        $token = $user->createToken($data['device_name'] ?? 'mobile-app', [$user->role])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function googleLogin(Request $request, GoogleIdentityVerifier $googleVerifier): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\\+?[0-9][0-9 .()-]{7,20}$/'],
            'role' => ['required', 'in:parent,teacher'],
            'google_id_token' => ['required', 'string', 'max:8192'],
            'school_id' => ['nullable', 'uuid'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $identity = $googleVerifier->verify($data['google_id_token']);
        } catch (Throwable) {
            throw ValidationException::withMessages(['google_id_token' => ['Google sign-in could not be verified. Please try again.']]);
        }
        $email = strtolower(trim($data['email']));
        if (strcasecmp($identity['email'], $email) !== 0) {
            throw ValidationException::withMessages(['google_id_token' => ['Choose the same Google account as the registered Gmail address.']]);
        }

        $phone = preg_replace('/\\D+/', '', $data['phone']);
        $query = User::query()->where('email', $email)->where('phone', $phone)
            ->where('role', $data['role'])->where('is_active', true);
        if (! empty($data['school_id'])) {
            $query->where('school_id', $data['school_id']);
        }
        $matches = $query->with('school')->limit(100)->get();
        if ($matches->count() > 1) {
            return response()->json([
                'requiresSchoolSelection' => true,
                'schools' => $matches->map(static fn (User $account): array => [
                    'id' => $account->school_id,
                    'name' => $account->school?->name ?? 'School account',
                ])->values(),
            ]);
        }
        $user = $matches->first();
        if ($user === null || ($user->google_subject !== null && ! hash_equals($user->google_subject, $identity['sub']))) {
            return response()->json([
                'error' => ['code' => 'ACCOUNT_NOT_VERIFIED', 'message' => 'Those details could not be verified. Check with the school office if your parent or teacher record needs correction.'],
            ], 403);
        }
        if ($data['role'] === 'parent' && ! DB::table('relay_parent_links')->where('school_id', $user->school_id)->where('user_id', $user->id)->exists()) {
            return response()->json([
                'error' => ['code' => 'SCHOOL_REVIEW_REQUIRED', 'message' => 'Your account is verified, but no child is linked yet. Ask the school to review the guardian record.'],
            ], 403);
        }
        if (! $this->schoolAccessReady($user)) {
            return response()->json([
                'error' => ['code' => 'SCHOOL_ACCESS_NOT_ACTIVE', 'message' => 'School setup or activation is not complete.'],
            ], 403);
        }
        if ($user->google_subject === null) {
            $user->forceFill(['google_subject' => $identity['sub'], 'email_verified_at' => now()])->save();
        }
        $token = $user->createToken($data['device_name'] ?? 'school-erp-mobile', [$user->role])->plainTextToken;

        return response()->json(['token' => $token, 'user' => $this->userPayload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['signed_out' => true]);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'school' => ['id' => $user->school_id, 'name' => $user->school?->name],
        ];
    }

    private function schoolAccessReady(User $user): bool
    {
        $school = $user->school;
        if ($school === null) {
            return false;
        }

        $trialEnabled = (bool) config('school_erp.trial_enabled');
        $googleStates = $trialEnabled ? ['verified', 'bypassed_trial'] : ['verified'];
        $activationStates = $trialEnabled ? ['active', 'bypassed_trial'] : ['active'];

        return in_array($school->google_link_status, $googleStates, true)
            && in_array($school->activation_status, $activationStates, true);
    }
}
