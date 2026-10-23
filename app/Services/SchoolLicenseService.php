<?php

namespace App\Services;

use App\Models\ActivationCode;
use App\Models\LicensePlan;
use App\Models\School;
use App\Models\SchoolLicense;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SchoolLicenseService
{
    public function __construct(private LicenseTokenService $tokens) {}

    public function startTrial(School $school, ?User $actor = null): SchoolLicense
    {
        $plan = LicensePlan::query()->where('key', 'starter')->firstOrFail();
        $start = $school->trial_started_at ?? now();
        $expires = $start->copy()->addDays((int) config('school_erp.trial_days', 15));
        $school->forceFill([
            'trial_started_at' => $start,
            'trial_expires_at' => $expires,
            'license_plan_key' => 'starter',
            'license_status' => 'trial',
            'active_student_limit' => $plan->student_limit ?? 100,
            'active_teacher_limit' => $plan->teacher_limit ?? 1,
        ])->save();

        return $this->issue($school, $plan, $plan->student_limit, $plan->teacher_limit, $expires, $actor, true);
    }

    public function extendTrial(School $school, User $actor): SchoolLicense
    {
        if ($school->license_status !== 'trial' || $school->trial_started_at === null || $school->trial_extended_at !== null) {
            throw ValidationException::withMessages(['trial' => ['This trial cannot be extended again.']]);
        }

        $plan = LicensePlan::query()->where('key', 'starter')->firstOrFail();
        $expires = $school->trial_started_at->copy()->addDays((int) config('school_erp.trial_max_days', 30));
        $school->forceFill(['trial_expires_at' => $expires, 'trial_extended_at' => now()])->save();

        return $this->issue($school, $plan, $plan->student_limit, $plan->teacher_limit, $expires, $actor, true);
    }

    public function refreshServiceAccessToken(School $school, ?bool $serviceOnHold = null): ?SchoolLicense
    {
        $license = $school->currentLicense;
        if ($license === null) {
            return null;
        }
        $plan = LicensePlan::query()->where('key', $license->plan_key)->first();
        if ($plan === null) {
            return null;
        }
        $claims = $license->claims ?? [];

        return $this->issue(
            $school,
            $plan,
            $claims['limits']['students'] ?? null,
            $claims['limits']['teachers'] ?? null,
            $license->valid_until,
            null,
            $license->status === 'trial',
            $claims['feature_flags'] ?? null,
            $claims['custom_services'] ?? null,
            $serviceOnHold ?? (bool) $school->service_on_hold,
        );
    }

    public function createActivationCode(
        School $school,
        User $actor,
        LicensePlan $plan,
        ?int $studentSeats,
        ?int $teacherSeats,
        ?array $featureFlags,
        ?string $licenseExpiresAt,
    ): string {
        if (! $plan->is_enabled || in_array($plan->key, ['professional_distributed', 'elite'], true)) {
            throw ValidationException::withMessages(['plan_key' => ['Choose an enabled school plan.']]);
        }
        if ($plan->key === 'professional_school' && ($studentSeats === null || $studentSeats < 1)) {
            throw ValidationException::withMessages(['student_seats' => ['Enter the active student seat count.']]);
        }
        if ($plan->key === 'elite' && $licenseExpiresAt === null) {
            throw ValidationException::withMessages(['license_expires_at' => ['Set the custom Elite license end date.']]);
        }
        if ($plan->student_limit !== null && $studentSeats !== null && $studentSeats > $plan->student_limit) {
            throw ValidationException::withMessages(['student_seats' => ['The student seat count exceeds this plan limit.']]);
        }
        if ($plan->teacher_limit !== null && $teacherSeats !== null && $teacherSeats > $plan->teacher_limit) {
            throw ValidationException::withMessages(['teacher_seats' => ['The teacher seat count exceeds this plan limit.']]);
        }

        $code = 'SCHOOL-'.Str::upper(Str::random(48));
        ActivationCode::query()->create([
            'id' => (string) Str::uuid(),
            'school_id' => $school->id,
            'plan_key' => $plan->key,
            'code_hash' => hash('sha256', $code),
            'student_seats' => $studentSeats ?? $plan->student_limit,
            'teacher_seats' => $teacherSeats ?? $plan->teacher_limit,
            'feature_flags' => $featureFlags,
            'code_expires_at' => now()->addDays(30),
            'license_expires_at' => $licenseExpiresAt,
            'created_by' => $actor->id,
        ]);

        return $code;
    }

    public function activate(string $schoolId, string $serverId, string $code): array
    {
        return DB::transaction(function () use ($schoolId, $serverId, $code): array {
            $activationCode = ActivationCode::query()->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
            $school = School::query()->whereKey($schoolId)->lockForUpdate()->first();
            if ($activationCode === null || $school === null || $activationCode->school_id !== $school->id || $school->server_id !== $serverId) {
                throw ValidationException::withMessages(['activation_code' => ['The activation code could not be validated for this school server.']]);
            }

            if ($activationCode->consumed_at !== null) {
                if ($activationCode->consumed_server_id !== $serverId || $activationCode->school_license_id === null) {
                    throw new ConflictHttpException('This activation code has already been used.');
                }
                $license = SchoolLicense::query()->whereKey($activationCode->school_license_id)->first();
                if ($license === null || $license->status === 'revoked') {
                    throw new ConflictHttpException('This activation code has already been used.');
                }

                return ['license' => $license, 'idempotent' => true];
            }

            if ($activationCode->approved_at === null) {
                throw new ConflictHttpException('This activation is waiting for operator approval.');
            }

            if ($activationCode->code_expires_at !== null && $activationCode->code_expires_at->isPast()) {
                throw ValidationException::withMessages(['activation_code' => ['This activation code has expired.']]);
            }

            $plan = LicensePlan::query()->where('key', $activationCode->plan_key)->where('is_enabled', true)->first();
            if ($plan === null) {
                throw ValidationException::withMessages(['activation_code' => ['This license plan is no longer available.']]);
            }
            $validUntil = $activationCode->license_expires_at;
            if ($validUntil === null && $plan->billing_period === 'annual') {
                $validUntil = now()->addYear();
            }
            $license = $this->issue(
                $school,
                $plan,
                $activationCode->student_seats,
                $activationCode->teacher_seats,
                $validUntil,
                null,
                false,
                $activationCode->feature_flags,
            );
            $activationCode->forceFill([
                'consumed_at' => now(),
                'consumed_server_id' => $serverId,
                'school_license_id' => $license->id,
            ])->save();

            return ['license' => $license, 'idempotent' => false];
        });
    }

    /**
     * @param  array{sub: string, email: string, email_verified: true}  $identity
     * @return array{activation_code: ActivationCode, school: School, status: string}
     */
    public function requestEnrollment(string $code, string $serverId, array $identity): array
    {
        return DB::transaction(function () use ($code, $serverId, $identity): array {
            $activationCode = ActivationCode::query()
                ->where('code_hash', hash('sha256', $code))
                ->lockForUpdate()
                ->first();
            if ($activationCode === null) {
                throw ValidationException::withMessages(['activation_code' => ['The school code is invalid or expired.']]);
            }

            $school = School::query()->whereKey($activationCode->school_id)->lockForUpdate()->firstOrFail();
            if ($activationCode->code_expires_at?->isPast()) {
                throw ValidationException::withMessages(['activation_code' => ['The school code is invalid or expired.']]);
            }
            if (strcasecmp($identity['email'], (string) $school->users()->where('role', 'school_admin')->orderBy('id')->value('email')) !== 0) {
                throw ValidationException::withMessages(['google_id_token' => ['Sign in with the Google account registered for this school administrator.']]);
            }
            if ($activationCode->consumed_at !== null) {
                if ($activationCode->consumed_server_id !== $serverId || $activationCode->school_license_id === null) {
                    throw new ConflictHttpException('This school code has already been used.');
                }

                return ['activation_code' => $activationCode, 'school' => $school, 'status' => 'approved'];
            }
            if ($activationCode->requested_server_id !== null && $activationCode->requested_server_id !== $serverId) {
                throw new ConflictHttpException('This school code is already being used by another installation.');
            }
            if ($activationCode->requested_google_subject !== null
                && $activationCode->requested_google_subject !== $identity['sub']) {
                throw new ConflictHttpException('This school code is already linked to another Google account.');
            }
            if ($school->google_subject !== null && $school->google_subject !== $identity['sub']) {
                throw new ConflictHttpException('This school is linked to another Google account. Contact the operator.');
            }
            $claimedByAnotherSchool = School::query()->where('server_id', $serverId)->where('id', '!=', $school->id)->exists();
            if ($claimedByAnotherSchool) {
                throw new ConflictHttpException('This installation is already linked to another school.');
            }
            if ($school->activation_status === 'active' && $school->server_id !== $serverId) {
                throw new ConflictHttpException('This school already has an active installation. Contact the operator to transfer it.');
            }

            $serverIdChanged = $school->server_id !== $serverId;
            $school->forceFill([
                'server_id' => $serverId,
                'google_subject' => $identity['sub'],
                'google_email' => strtolower($identity['email']),
                'google_link_status' => 'verified',
                'activation_status' => 'pending_approval',
                'activation_id' => $activationCode->id,
            ])->save();
            $activationCode->forceFill([
                'requested_server_id' => $serverId,
                'requested_google_subject' => $identity['sub'],
                'requested_google_email' => strtolower($identity['email']),
                'enrollment_requested_at' => $activationCode->enrollment_requested_at ?? now(),
            ])->save();

            if ($serverIdChanged && $school->currentLicense?->status === 'trial') {
                $this->refreshServiceAccessToken($school->fresh());
                School::query()->whereKey($school->id)->update(['activation_status' => 'pending_approval', 'updated_at' => now()]);
            }

            return ['activation_code' => $activationCode, 'school' => $school->fresh(), 'status' => 'pending_operator_approval'];
        });
    }

    public function approveActivationCode(ActivationCode $activationCode, User $actor): SchoolLicense
    {
        return DB::transaction(function () use ($activationCode, $actor): SchoolLicense {
            $lockedCode = ActivationCode::query()->whereKey($activationCode->id)->lockForUpdate()->firstOrFail();
            $school = School::query()->whereKey($lockedCode->school_id)->lockForUpdate()->firstOrFail();

            if ($lockedCode->consumed_at !== null && $lockedCode->school_license_id !== null) {
                $this->ensureRelayCredential($school);

                return SchoolLicense::query()->whereKey($lockedCode->school_license_id)->firstOrFail();
            }
            if ($lockedCode->enrollment_requested_at === null
                || $lockedCode->requested_server_id === null
                || $school->activation_status !== 'pending_approval') {
                throw ValidationException::withMessages(['activation' => ['There is no pending school enrollment to approve.']]);
            }
            if ($lockedCode->code_expires_at?->isPast()) {
                throw ValidationException::withMessages(['activation' => ['This enrollment code has expired. Issue a new code for the school.']]);
            }

            $plan = LicensePlan::query()->where('key', $lockedCode->plan_key)->where('is_enabled', true)->first();
            if ($plan === null) {
                throw ValidationException::withMessages(['activation' => ['This license plan is no longer available.']]);
            }
            $validUntil = $lockedCode->license_expires_at;
            if ($validUntil === null && $plan->billing_period === 'annual') {
                $validUntil = now()->addYear();
            }
            $license = $this->issue(
                $school,
                $plan,
                $lockedCode->student_seats,
                $lockedCode->teacher_seats,
                $validUntil,
                $actor,
                false,
                $lockedCode->feature_flags,
            );
            $this->ensureRelayCredential($school);
            $lockedCode->forceFill([
                'approved_at' => now(),
                'approved_by' => $actor->id,
                'consumed_at' => now(),
                'consumed_server_id' => $lockedCode->requested_server_id,
                'school_license_id' => $license->id,
            ])->save();

            return $license;
        });
    }

    private function ensureRelayCredential(School $school): void
    {
        $credential = DB::table('school_relay_credentials')->where('school_id', $school->id)->first();
        if ($credential !== null && $credential->is_active) {
            return;
        }

        $now = now();
        DB::table('school_relay_credentials')->updateOrInsert(
            ['school_id' => $school->id],
            [
                'key_id' => (string) Str::uuid(),
                'secret_ciphertext' => Crypt::encryptString(Str::random(64)),
                'is_active' => true,
                'last_used_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function issue(
        School $school,
        LicensePlan $plan,
        ?int $studentSeats,
        ?int $teacherSeats,
        mixed $validUntil,
        ?User $actor,
        bool $trial = false,
        ?array $featureFlags = null,
        ?string $servicesSummary = null,
        bool $serviceOnHold = false,
    ): SchoolLicense {
        $id = (string) Str::uuid();
        $issuedAt = now();
        $expiresAt = $validUntil === null ? null : Carbon::parse($validUntil);
        $graceDays = (int) config('school_erp.offline_grace_days', 7);
        $graceUntil = $expiresAt?->copy()->addDays($graceDays);
        $basicLimits = $plan->key === 'elite' ? LicensePlan::query()->where('key', 'basic_adware')->first() : null;
        $limits = [
            'students' => $studentSeats ?? $plan->student_limit ?? $basicLimits?->student_limit,
            'teachers' => $teacherSeats ?? $plan->teacher_limit ?? $basicLimits?->teacher_limit,
        ];
        $claims = [
            'license_id' => $id,
            'school_id' => $school->id,
            'server_id' => $school->server_id,
            'plan' => $plan->key,
            'plan_name' => $plan->name,
            'limits' => $limits,
            'feature_flags' => $featureFlags ?? $plan->feature_flags ?? [],
            'ad_supported' => $plan->ad_supported,
            'base_plan' => $plan->key === 'elite' ? 'basic_adware' : null,
            'custom_services' => $servicesSummary,
            'currency' => $plan->currency,
            'trial' => $trial,
            'trial_extended' => $school->trial_extended_at !== null,
            'service_on_hold' => $serviceOnHold,
            'issuer' => config('school_erp.license_issuer'),
            'key_id' => config('school_erp.license_key_id'),
            'token_version' => 1,
            'issued_at' => $issuedAt->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'offline_grace_days' => $graceDays,
            'grace_until' => $graceUntil?->toIso8601String(),
        ];
        $token = $this->tokens->sign($claims);
        SchoolLicense::query()->where('school_id', $school->id)
            ->whereIn('status', ['active', 'trial'])
            ->update(['status' => 'superseded', 'updated_at' => $issuedAt]);
        $license = SchoolLicense::query()->create([
            'id' => $id,
            'school_id' => $school->id,
            'plan_key' => $plan->key,
            'status' => $trial ? 'trial' : 'active',
            'claims' => $claims,
            'signed_token' => $token,
            'token_hash' => hash('sha256', $token),
            'issued_at' => $issuedAt,
            'valid_until' => $expiresAt,
            'grace_until' => $graceUntil,
            'issued_by' => $actor?->id,
        ]);

        $school->forceFill([
            'license_plan_key' => $plan->key,
            'license_status' => $trial ? 'trial' : 'active',
            'active_student_limit' => $limits['students'] ?? $school->active_student_limit,
            'active_teacher_limit' => $limits['teachers'] ?? $school->active_teacher_limit,
            'license_expires_at' => $expiresAt,
            'activation_status' => $trial ? 'bypassed_trial' : 'active',
            'activation_id' => $trial ? $school->activation_id : $id,
            'current_license_id' => $id,
            'activated_at' => $trial ? null : $issuedAt,
            'service_on_hold' => $serviceOnHold,
            'service_on_hold_at' => $serviceOnHold ? ($school->service_on_hold_at ?? $issuedAt) : null,
            'relay_status' => $serviceOnHold ? 'paused' : ($school->hold_previous_relay_status ?? $school->relay_status ?? 'active'),
            'hold_previous_relay_status' => $serviceOnHold ? ($school->hold_previous_relay_status ?? $school->relay_status ?? 'active') : null,
        ])->save();

        return $license;
    }
}
