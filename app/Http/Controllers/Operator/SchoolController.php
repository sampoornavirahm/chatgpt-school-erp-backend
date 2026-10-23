<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\ActivationCode;
use App\Models\LicensePlan;
use App\Models\School;
use App\Models\User;
use App\Services\SchoolLicenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SchoolController extends Controller
{
    public function index(): View
    {
        $schools = School::query()->withCount('users')->orderBy('name')->get()->map(function (School $school): School {
            $school->setAttribute('storage_used_bytes', (int) DB::table('relay_snapshots')->where('school_id', $school->id)->sum('payload_bytes'));
            $school->setAttribute('snapshot_count', (int) DB::table('relay_snapshots')->where('school_id', $school->id)->count());
            $school->setAttribute('queued_commands', (int) DB::table('relay_parent_commands')->where('school_id', $school->id)->where('status', 'queued')->count());

            return $school;
        });

        return view('operator.schools', ['schools' => $schools]);
    }

    public function create(Request $request, SchoolLicenseService $licenses): RedirectResponse
    {
        abort_unless((bool) config('school_erp.trial_enabled'), 409, 'Create school tenants after the Google and activation provisioning path is configured.');
        $data = $request->validate([
            'school_name' => ['required', 'string', 'min:2', 'max:160'],
            'server_id' => ['nullable', 'uuid'],
            'admin_name' => ['required', 'string', 'min:2', 'max:120'],
            'admin_email' => ['required', 'email:rfc', 'max:255'],
            'admin_password' => ['required', 'string', 'min:12', 'max:128'],
        ]);
        $school = DB::transaction(function () use ($data, $request, $licenses): School {
            $school = School::query()->create([
                'id' => (string) Str::uuid(),
                'name' => $data['school_name'],
                'server_id' => $data['server_id'] ?? (string) Str::uuid(),
                'google_link_status' => 'bypassed_trial',
                'activation_status' => 'bypassed_trial',
                'trial_started_at' => now(),
                'relay_status' => 'active',
                'license_plan_key' => 'trial',
                'license_status' => 'trial',
                'active_student_limit' => 100,
                'active_teacher_limit' => 1,
            ]);
            $licenses->startTrial($school, $request->user());
            User::query()->create([
                'school_id' => $school->id,
                'name' => $data['admin_name'],
                'email' => strtolower($data['admin_email']),
                'password' => $data['admin_password'],
                'role' => 'school_admin',
                'is_active' => true,
            ]);
            $this->audit($request, $school, 'school_created', 'school', $school->id, ['trial' => true]);

            return $school;
        });

        return redirect()->route('operator.schools.show', $school)->with('status', 'School tenant and initial administrator created. Share the school ID with its mobile users.');
    }

    public function show(School $school): View
    {
        $school->setAttribute('storage_used_bytes', (int) DB::table('relay_snapshots')->where('school_id', $school->id)->sum('payload_bytes'));
        $school->setAttribute('snapshot_count', (int) DB::table('relay_snapshots')->where('school_id', $school->id)->count());
        $school->setAttribute('queued_commands', (int) DB::table('relay_parent_commands')->where('school_id', $school->id)->where('status', 'queued')->count());
        $school->setAttribute('linked_parents', DB::table('relay_parent_links')->where('school_id', $school->id)->distinct()->count('user_id'));
        $credential = DB::table('school_relay_credentials')->where('school_id', $school->id)->first();
        $mainPasswordChange = DB::table('school_main_password_changes')->where('school_id', $school->id)->orderByDesc('created_at')->first(['status', 'expires_at', 'applied_at', 'result_message']);
        $parents = User::query()->where('school_id', $school->id)->where('role', 'parent')->orderBy('name')->get(['id', 'name', 'email']);
        $students = DB::table('relay_snapshots')->where('school_id', $school->id)->where('is_deleted', false)->orderBy('student_public_id')->get(['student_public_id', 'payload'])->map(function (object $row): array {
            $snapshot = json_decode($row->payload, true) ?: [];
            $student = $snapshot['student'] ?? [];

            return ['id' => $row->student_public_id, 'name' => $student['fullName'] ?? $student['name'] ?? 'Student', 'admissionNo' => $student['admissionNo'] ?? ''];
        });
        $links = DB::table('relay_parent_links as links')->join('users', 'users.id', '=', 'links.user_id')
            ->leftJoin('relay_snapshots as snapshots', function ($join): void {
                $join->on('snapshots.school_id', '=', 'links.school_id')->on('snapshots.student_public_id', '=', 'links.student_public_id');
            })
            ->where('links.school_id', $school->id)->orderBy('users.name')
            ->get(['links.id', 'links.student_public_id', 'users.name as parent_name', 'users.email', 'snapshots.payload'])
            ->map(function (object $row): array {
                $snapshot = json_decode($row->payload ?? '{}', true) ?: [];
                $student = $snapshot['student'] ?? [];

                return ['id' => $row->id, 'studentId' => $row->student_public_id, 'studentName' => $student['fullName'] ?? $student['name'] ?? $row->student_public_id, 'parentName' => $row->parent_name, 'email' => $row->email];
            });
        $audit = DB::table('relay_audit_logs')->where('school_id', $school->id)->orderByDesc('id')->limit(30)->get();
        $licenses = $school->licenses()->latest('issued_at')->get();
        $activationRequests = ActivationCode::query()->where('school_id', $school->id)
            ->whereNotNull('enrollment_requested_at')->latest('enrollment_requested_at')->get();
        $orders = DB::table('subscription_orders as orders')
            ->leftJoin('payment_reconciliations as payments', 'payments.subscription_order_id', '=', 'orders.id')
            ->where('orders.school_id', $school->id)->orderByDesc('orders.created_at')->limit(50)
            ->get(['orders.*', 'payments.method as reconciliation_method', 'payments.reference as reconciliation_reference', 'payments.paid_at as reconciled_paid_at']);
        $contracts = DB::table('subscription_contracts')->where('school_id', $school->id)->orderByDesc('created_at')->limit(20)->get();
        $trialExtensionRequests = DB::table('trial_extension_requests as requests')
            ->join('users as requester', 'requester.id', '=', 'requests.requested_by')
            ->leftJoin('users as handler', 'handler.id', '=', 'requests.handled_by')
            ->where('requests.school_id', $school->id)->orderByDesc('requests.requested_at')->limit(10)
            ->get(['requests.*', 'requester.name as requester_name', 'handler.name as handler_name']);
        $plans = LicensePlan::query()->where('is_enabled', true)->orderBy('sort_order')->get();

        return view('operator.school', compact('school', 'credential', 'mainPasswordChange', 'parents', 'students', 'links', 'audit', 'licenses', 'orders', 'contracts', 'plans', 'trialExtensionRequests', 'activationRequests'));
    }

    public function changeMainUserPassword(Request $request, School $school): RedirectResponse
    {
        if (! Str::isUuid($school->server_id)) {
            return back()->withErrors(['main_user_password' => 'This school has no registered desktop installation yet.']);
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ]);
        $changeId = (string) Str::uuid();
        $expiresAt = now()->addDays(7);
        DB::transaction(function () use ($request, $school, $data, $changeId, $expiresAt): void {
            DB::table('school_main_password_changes')
                ->where('school_id', $school->id)
                ->where('status', 'pending')
                ->update(['status' => 'superseded', 'password_ciphertext' => '', 'updated_at' => now()]);
            DB::table('school_main_password_changes')->insert([
                'id' => $changeId,
                'school_id' => $school->id,
                'server_id' => $school->server_id,
                'password_ciphertext' => Crypt::encryptString($data['password']),
                'status' => 'pending',
                'created_by' => $request->user()->id,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, $school, 'school_main_password_change_queued', 'school_account', $changeId, [
                'expiresAt' => $expiresAt->toIso8601String(),
            ]);
        });

        return back()->with('status', 'Main school account password change queued. It will apply when the school desktop app next connects, within 7 days.');
    }

    public function updateServiceAccess(Request $request, School $school, SchoolLicenseService $licenses): RedirectResponse
    {
        $data = $request->validate(['service_access' => ['required', 'in:active,on_hold'], 'support_note' => ['nullable', 'string', 'max:500']]);
        DB::transaction(function () use ($request, $school, $data, $licenses): void {
            $locked = School::query()->whereKey($school->id)->lockForUpdate()->firstOrFail();
            $holding = $data['service_access'] === 'on_hold';
            if ($holding && ! $locked->service_on_hold) {
                $locked->forceFill([
                    'service_on_hold' => true,
                    'service_on_hold_at' => now(),
                    'hold_previous_relay_status' => $locked->relay_status,
                    'relay_status' => 'paused',
                ])->save();
            } elseif (! $holding && $locked->service_on_hold) {
                $locked->forceFill([
                    'service_on_hold' => false,
                    'service_on_hold_at' => null,
                    'relay_status' => $locked->hold_previous_relay_status ?? 'active',
                    'hold_previous_relay_status' => null,
                ])->save();
            }
            $licenses->refreshServiceAccessToken($locked);
            $this->audit($request, $locked, $holding ? 'school_services_held' : 'school_services_resumed', 'school', $locked->id, ['supportNote' => $data['support_note'] ?? '']);
        });

        return back()->with('status', $data['service_access'] === 'on_hold'
            ? 'School services are on hold. Sign-in and renewal remain available.'
            : 'School service hold removed. The previous relay setting was restored.');
    }

    public function updateArchiveAccess(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate([
            'archive_access_enabled' => ['sometimes', 'boolean'],
            'archive_access_starts_at' => ['required_if:archive_access_enabled,1', 'nullable', 'date'],
            'archive_access_ends_at' => ['required_if:archive_access_enabled,1', 'nullable', 'date', 'after:archive_access_starts_at'],
            'archive_free_reads_limit' => ['required', 'integer', 'min:0', 'max:1000000'],
            'archive_query_price' => ['nullable', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'archive_access_note' => ['nullable', 'string', 'max:500'],
        ]);
        $enabled = $request->boolean('archive_access_enabled');
        $price = isset($data['archive_query_price']) ? $this->rupeesToPaise($data['archive_query_price']) : null;
        $school->forceFill([
            'archive_access_enabled' => $enabled,
            'archive_access_starts_at' => $enabled ? Carbon::parse($data['archive_access_starts_at'])->startOfDay() : null,
            'archive_access_ends_at' => $enabled ? Carbon::parse($data['archive_access_ends_at'])->endOfDay() : null,
            'archive_free_reads_limit' => $data['archive_free_reads_limit'],
            'archive_query_price_paise' => $price,
            'archive_access_note' => $data['archive_access_note'] ?? null,
        ])->save();
        $this->audit($request, $school, 'archive_access_window_updated', 'school', $school->id, [
            'enabled' => $enabled,
            'startsAt' => $school->archive_access_starts_at?->toIso8601String(),
            'endsAt' => $school->archive_access_ends_at?->toIso8601String(),
            'freeReadsLimit' => $school->archive_free_reads_limit,
            'queryPricePaise' => $price,
            'supportNote' => $data['archive_access_note'] ?? '',
        ]);

        return back()->with('status', $enabled ? 'Archive access window saved.' : 'Archive access disabled for this school.');
    }

    public function rotateRelayKey(Request $request, School $school): RedirectResponse
    {
        $secret = Str::random(64);
        $keyId = (string) Str::uuid();
        DB::transaction(function () use ($request, $school, $secret, $keyId): void {
            DB::table('school_relay_credentials')->updateOrInsert(
                ['school_id' => $school->id],
                ['key_id' => $keyId, 'secret_ciphertext' => Crypt::encryptString($secret), 'is_active' => true, 'last_used_at' => null, 'created_at' => now(), 'updated_at' => now()],
            );
            $this->audit($request, $school, 'relay_key_rotated', 'credential', $keyId);
        });

        return redirect()->route('operator.schools.show', $school)->with('relay_credentials', ['school_id' => $school->id, 'key_id' => $keyId, 'secret' => $secret]);
    }

    public function linkParent(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer'], 'student_public_id' => ['required', 'uuid']]);
        $parentExists = User::query()->where('id', $data['user_id'])->where('school_id', $school->id)->where('role', 'parent')->exists();
        $studentExists = DB::table('relay_snapshots')->where('school_id', $school->id)->where('student_public_id', $data['student_public_id'])->exists();
        abort_unless($parentExists && $studentExists, 404);

        $inserted = DB::table('relay_parent_links')->insertOrIgnore([
            'school_id' => $school->id,
            'user_id' => $data['user_id'],
            'student_public_id' => strtolower($data['student_public_id']),
            'linked_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request, $school, $inserted ? 'parent_link_created' : 'parent_link_reused', 'parent_link', $data['user_id'].'/'.$data['student_public_id']);

        return back()->with('status', 'Parent access link saved.');
    }

    public function unlinkParent(Request $request, School $school, int $link): RedirectResponse
    {
        $deleted = DB::table('relay_parent_links')->where('school_id', $school->id)->where('id', $link)->delete();
        abort_if($deleted === 0, 404);
        $this->audit($request, $school, 'parent_link_removed', 'parent_link', (string) $link);

        return back()->with('status', 'Parent access link removed.');
    }

    public function updateControls(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate([
            'relay_status' => ['required', 'in:active,paused'],
            'storage_quota_gb' => ['required', 'integer', 'min:1', 'max:50'],
            'support_note' => ['nullable', 'string', 'max:500'],
        ]);
        $school->forceFill([
            'relay_status' => $data['relay_status'],
            'storage_quota_bytes' => $data['storage_quota_gb'] * 1024 * 1024 * 1024,
        ])->save();
        $this->audit($request, $school, 'school_controls_updated', 'school', $school->id, ['supportNote' => $data['support_note'] ?? '']);

        return back()->with('status', 'School relay and entitlement settings saved.');
    }

    private function audit(Request $request, School $school, string $action, string $targetType, string $targetId, array $details = []): void
    {
        DB::table('relay_audit_logs')->insert([
            'school_id' => $school->id,
            'actor_user_id' => $request->user()->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function rupeesToPaise(string $amount): int
    {
        [$rupees, $paise] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $rupees * 100) + (int) str_pad($paise, 2, '0');
    }
}
