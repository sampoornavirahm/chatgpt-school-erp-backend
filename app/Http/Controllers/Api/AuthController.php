<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
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
        $query = User::query()->with('school')->where('email', $email)->where('phone', $phone)
            ->where('role', $data['role'])->where('is_active', true)->orderBy('school_id');
        if (! empty($data['school_id'])) {
            $query->where('school_id', $data['school_id']);
        }
        $matches = $query->get();
        $subjectConflict = $matches->contains(static fn (User $account): bool => $account->google_subject !== null && ! hash_equals($account->google_subject, $identity['sub']));
        if ($subjectConflict || $matches->isEmpty()) {
            return response()->json([
                'error' => ['code' => 'ACCOUNT_NOT_VERIFIED', 'message' => 'Those details could not be verified. Check with the school office if your parent or teacher record needs correction.'],
            ], 403);
        }

        if ($data['role'] === 'teacher' && empty($data['school_id']) && $matches->count() > 1) {
            return response()->json([
                'requiresSchoolSelection' => true,
                'schools' => $matches->map(static fn (User $account): array => [
                    'id' => $account->school_id,
                    'name' => $account->school?->name ?? 'School account',
                ])->values(),
            ]);
        }
        $readyMatches = $matches->filter(fn (User $account): bool => $this->schoolAccessReady($account))->values();
        $linkedAccountIds = $data['role'] === 'parent'
            ? DB::table('relay_parent_links')->whereIn('user_id', $readyMatches->pluck('id'))->distinct()->pluck('user_id')->map(static fn ($id): int => (int) $id)->all()
            : [];
        if ($data['role'] === 'parent' && $linkedAccountIds === []) {
            return response()->json([
                'error' => ['code' => 'SCHOOL_REVIEW_REQUIRED', 'message' => 'Your account is verified, but no child is linked yet. Ask the school to review the guardian record.'],
            ], 403);
        }
        $user = ! empty($data['school_id'])
            ? $readyMatches->firstWhere('school_id', $data['school_id'])
            : ($data['role'] === 'parent'
                ? $readyMatches->first(static fn (User $account): bool => in_array((int) $account->id, $linkedAccountIds, true))
                : $readyMatches->first());
        if ($user === null) {
            return response()->json([
                'error' => ['code' => 'SCHOOL_ACCESS_NOT_ACTIVE', 'message' => 'School setup or activation is not complete.'],
            ], 403);
        }

        if ($data['role'] === 'parent') {
            User::query()->whereIn('id', $readyMatches->pluck('id'))->whereNull('google_subject')->update([
                'google_subject' => $identity['sub'],
                'email_verified_at' => now(),
            ]);
        } elseif ($user->google_subject === null) {
            $user->forceFill(['google_subject' => $identity['sub'], 'email_verified_at' => now()])->save();
        }
        $user->google_subject = $identity['sub'];
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

    public function sessions(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['parent', 'teacher'], true), 403);

        $currentTokenId = $request->user()->currentAccessToken()?->getKey();
        $sessionsQuery = PersonalAccessToken::query()
            ->where('tokenable_type', $request->user()->getMorphClass())
            ->whereIn('tokenable_id', $this->sessionOwnerIds($request->user()))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
        $expirationMinutes = config('sanctum.expiration');
        if (is_int($expirationMinutes) && $expirationMinutes > 0) {
            $sessionsQuery->where('created_at', '>', now()->subMinutes($expirationMinutes));
        }
        $sessions = $sessionsQuery
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->limit(100)
            ->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at'])
            ->map(static fn ($token): array => [
                'id' => (string) $token->getKey(),
                'deviceName' => $token->name,
                'createdAt' => $token->created_at?->toISOString(),
                'lastUsedAt' => $token->last_used_at?->toISOString(),
                'expiresAt' => $token->expires_at?->toISOString(),
                'isCurrent' => (string) $token->getKey() === (string) $currentTokenId,
            ])->values();

        return response()->json(['sessions' => $sessions]);
    }

    public function revokeSession(Request $request, string $session): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['parent', 'teacher'], true), 403);
        abort_unless(ctype_digit($session), 404);

        $token = PersonalAccessToken::query()
            ->where('tokenable_type', $request->user()->getMorphClass())
            ->whereIn('tokenable_id', $this->sessionOwnerIds($request->user()))
            ->whereKey((int) $session)
            ->firstOrFail();
        $wasCurrent = (string) $token->getKey() === (string) $request->user()->currentAccessToken()?->getKey();
        $token->delete();
        Log::notice('A mobile session was revoked by its account owner.', [
            'user_id' => $request->user()->getKey(),
            'school_id' => $request->user()->school_id,
            'session_id' => (int) $session,
            'current_session' => $wasCurrent,
        ]);

        return response()->json(['revoked' => true, 'signedOut' => $wasCurrent]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['parent', 'teacher'], true), 403);
        $revokedCount = PersonalAccessToken::query()
            ->where('tokenable_type', $request->user()->getMorphClass())
            ->whereIn('tokenable_id', $this->sessionOwnerIds($request->user()))
            ->delete();
        Log::notice('An account owner revoked all mobile sessions.', [
            'user_id' => $request->user()->getKey(),
            'school_id' => $request->user()->school_id,
            'revoked_count' => $revokedCount,
        ]);

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

    /** @return list<int> */
    private function sessionOwnerIds(User $user): array
    {
        if ($user->google_subject === null || ! in_array($user->role, ['parent', 'teacher'], true)) {
            return [(int) $user->getKey()];
        }

        return User::query()
            ->where('role', $user->role)
            ->where('email', $user->email)
            ->where('phone', $user->phone)
            ->where('google_subject', $user->google_subject)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
