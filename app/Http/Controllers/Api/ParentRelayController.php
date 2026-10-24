<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LicenseTokenService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ParentRelayController extends Controller
{
    public function __construct(private LicenseTokenService $licenseTokens) {}

    public function children(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);
        $accounts = $this->familyAccounts($request);
        $children = DB::table('relay_parent_links as links')
            ->join('relay_snapshots as snapshots', function ($join): void {
                $join->on('snapshots.school_id', '=', 'links.school_id')
                    ->on('snapshots.student_public_id', '=', 'links.student_public_id');
            })
            ->join('schools', 'schools.id', '=', 'links.school_id')
            ->whereIn('links.user_id', $accounts->modelKeys())
            ->where('snapshots.is_deleted', false)
            ->orderBy('schools.name')->orderBy('links.student_public_id')
            ->get(['links.school_id', 'schools.name as school_name', 'links.student_public_id', 'links.access_level', 'snapshots.revision', 'snapshots.payload', 'snapshots.projected_at'])
            ->map(fn (object $row): array => [
                'studentPublicId' => $row->student_public_id,
                'schoolId' => $row->school_id,
                'schoolName' => $row->school_name,
                'linkStatus' => 'linked',
                'accessLevel' => $row->access_level,
                'revision' => (int) $row->revision,
                'snapshot' => $this->parentSnapshot($row->payload, $row->access_level),
                'projectedAt' => $row->projected_at,
            ])->all();
        $schoolIds = array_values(array_unique(array_column($children, 'schoolId')));
        $schoolCalendar = $this->schoolCalendarFor($schoolIds);

        return response()->json([
            'children' => $children,
            'calendarEvents' => $schoolCalendar['events'],
            'calendarProjectedAt' => $schoolCalendar['projectedAt'],
            'calendarProjectedAtBySchool' => $schoolCalendar['projectedAtBySchool'],
            'parentFeatures' => $this->parentFeatures($request),
            'syncedAt' => now()->toISOString(),
        ]);
    }

    public function notificationPreferences(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);
        $accounts = $this->familyAccounts($request);
        $preferences = [
            'school_notice' => true,
            'schedule_update' => true,
            'homework_update' => true,
        ];
        $rows = DB::table('parent_notification_preferences')
            ->whereIn('user_id', $accounts->modelKeys())
            ->whereIn('category', array_keys($preferences))
            ->get(['category', 'enabled']);
        foreach ($rows as $row) {
            // Family accounts share notification choices; a disabled choice remains disabled
            // if older school accounts have not yet been brought into sync.
            $preferences[$row->category] = ($preferences[$row->category] ?? true) && (bool) $row->enabled;
        }

        return response()->json(['preferences' => $preferences]);
    }

    public function updateNotificationPreference(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);
        $data = $request->validate([
            'category' => ['required', 'in:school_notice,schedule_update,homework_update'],
            'enabled' => ['required', 'boolean'],
        ]);
        $now = now();
        $actor = $request->user();
        $enabled = (bool) $data['enabled'];
        $accounts = $this->familyAccounts($request);

        DB::transaction(function () use ($accounts, $actor, $data, $enabled, $now, $request): void {
            $previousValues = DB::table('parent_notification_preferences')
                ->whereIn('user_id', $accounts->modelKeys())
                ->where('category', $data['category'])
                ->get(['user_id', 'enabled'])
                ->keyBy('user_id');

            foreach ($accounts as $account) {
                $previous = $previousValues->get($account->id);
                DB::table('parent_notification_preferences')->upsert(
                    [[
                        'user_id' => $account->id,
                        'category' => $data['category'],
                        'enabled' => $enabled,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]],
                    ['user_id', 'category'],
                    ['enabled', 'updated_at'],
                );

                if ($previous !== null && (bool) $previous->enabled === $enabled) {
                    continue;
                }

                DB::table('relay_audit_logs')->insert([
                    'school_id' => $account->school_id,
                    'actor_user_id' => $actor->id,
                    'action' => 'parent_notification_preference_changed',
                    'target_type' => 'parent_notification_preference',
                    'target_id' => $account->id.':'.$data['category'],
                    'details' => json_encode([
                        'category' => $data['category'],
                        'from' => $previous === null ? null : (bool) $previous->enabled,
                        'to' => $enabled,
                    ], JSON_THROW_ON_ERROR),
                    'ip_address' => $request->ip(),
                    'created_at' => $now,
                ]);
            }
        });

        return response()->json([
            'category' => $data['category'],
            'enabled' => (bool) $data['enabled'],
        ]);
    }

    /** @return array{courses: bool, transport: bool, ai: bool} */
    private function parentFeatures(Request $request): array
    {
        $school = $request->user()->school;
        $license = $school?->currentLicense;
        $verification = $license === null
            ? ['valid' => false]
            : $this->licenseTokens->verify($license->signed_token);
        $claims = $verification['claims'] ?? [];
        $flags = is_array($claims['feature_flags'] ?? null) ? $claims['feature_flags'] : [];
        $enabled = ($verification['valid'] ?? false)
            && ($verification['state'] ?? null) === 'active'
            && in_array($license?->status, ['active', 'trial'], true)
            && ! (bool) $school?->service_on_hold;

        return [
            'courses' => $enabled && in_array('parent.courses', $flags, true),
            'transport' => $enabled && in_array('parent.transport', $flags, true),
            'ai' => $enabled && in_array('parent.ai', $flags, true),
        ];
    }

    public function showChild(Request $request, string $student): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);
        $accounts = $this->familyAccounts($request);
        $row = DB::table('relay_parent_links as links')
            ->join('relay_snapshots as snapshots', function ($join): void {
                $join->on('snapshots.school_id', '=', 'links.school_id')
                    ->on('snapshots.student_public_id', '=', 'links.student_public_id');
            })
            ->join('schools', 'schools.id', '=', 'links.school_id')
            ->whereIn('links.user_id', $accounts->modelKeys())
            ->where('links.student_public_id', $student)
            ->where('snapshots.is_deleted', false)
            ->first(['links.school_id', 'schools.name as school_name', 'links.access_level', 'snapshots.revision', 'snapshots.payload', 'snapshots.projected_at']);
        abort_if($row === null, 404);
        $schoolCalendar = $this->schoolCalendarFor([(string) $row->school_id]);

        return response()->json([
            'studentPublicId' => $student,
            'schoolId' => $row->school_id,
            'schoolName' => $row->school_name,
            'linkStatus' => 'linked',
            'accessLevel' => $row->access_level,
            'revision' => (int) $row->revision,
            'snapshot' => $this->parentSnapshot($row->payload, $row->access_level),
            'calendarEvents' => $schoolCalendar['events'],
            'calendarProjectedAt' => $schoolCalendar['projectedAt'],
            'calendarProjectedAtBySchool' => $schoolCalendar['projectedAtBySchool'],
            'projectedAt' => $row->projected_at,
        ]);
    }

    /** @param list<string> $schoolIds
     * @return array{events: list<array<string, mixed>>, projectedAt: string|null, projectedAtBySchool: array<string, string>}
     */
    private function schoolCalendarFor(array $schoolIds): array
    {
        if ($schoolIds === []) {
            return ['events' => [], 'projectedAt' => null, 'projectedAtBySchool' => []];
        }

        $rows = DB::table('relay_school_calendars as calendars')
            ->join('schools', 'schools.id', '=', 'calendars.school_id')
            ->whereIn('calendars.school_id', $schoolIds)
            ->orderBy('schools.name')
            ->get(['calendars.school_id', 'schools.name as school_name', 'calendars.payload', 'calendars.projected_at']);
        $events = [];
        $projectedAt = null;
        $projectedAtBySchool = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                continue;
            }
            foreach ($payload as $event) {
                if (! is_array($event)) {
                    continue;
                }
                $events[] = [
                    ...$event,
                    'schoolId' => $row->school_id,
                    'schoolName' => $row->school_name,
                ];
            }
            $projectedAt = $projectedAt === null || $row->projected_at > $projectedAt
                ? $row->projected_at
                : $projectedAt;
            $projectedAtBySchool[(string) $row->school_id] = $row->projected_at;
        }
        usort($events, static fn (array $left, array $right): int => [$left['startDate'] ?? '', $left['schoolName'] ?? '', $left['id'] ?? 0]
                <=> [$right['startDate'] ?? '', $right['schoolName'] ?? '', $right['id'] ?? 0]
        );

        return ['events' => $events, 'projectedAt' => $projectedAt, 'projectedAtBySchool' => $projectedAtBySchool];
    }

    public function submit(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'student_public_id' => ['required', 'uuid'],
            'operation' => ['required', 'in:parent_request.submit'],
            'payload' => ['required', 'array:request_type,starts_on,ends_on,message'],
            'payload.request_type' => ['required', 'in:leave,profile_change,document,other,data_access,data_correction,data_deletion,attendance_correction'],
            'payload.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'payload.ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:payload.starts_on'],
            'payload.message' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        if ($data['payload']['request_type'] === 'attendance_correction'
            && (empty($data['payload']['starts_on']) || $data['payload']['starts_on'] > now()->toDateString())) {
            throw ValidationException::withMessages([
                'payload.starts_on' => ['Choose a past or current attendance date.'],
            ]);
        }
        $user = $request->user();
        $accounts = $this->familyAccounts($request);
        $owners = DB::table('relay_parent_links')
            ->whereIn('user_id', $accounts->modelKeys())
            ->where('student_public_id', $data['student_public_id'])
            ->where('access_level', 'full')
            ->limit(2)
            ->get(['school_id', 'user_id']);
        abort_unless($owners->count() === 1, 404);
        $owner = $owners->first();
        $operationId = strtolower($data['operation_id']);
        $payloadJson = json_encode($data['payload'], JSON_THROW_ON_ERROR);
        $createdAt = now();
        $inserted = DB::transaction(function () use ($owner, $operationId, $data, $payloadJson, $createdAt, $request): int {
            $inserted = DB::table('relay_parent_commands')->insertOrIgnore([
                'school_id' => $owner->school_id,
                'operation_id' => $operationId,
                'user_id' => $owner->user_id,
                'student_public_id' => $data['student_public_id'],
                'operation' => $data['operation'],
                'payload' => $payloadJson,
                'status' => 'queued',
                'created_at' => $createdAt,
            ]);

            if ($inserted === 1) {
                DB::table('relay_audit_logs')->insert([
                    'school_id' => $owner->school_id,
                    'actor_user_id' => $request->user()->id,
                    'action' => 'parent_request_submitted',
                    'target_type' => 'parent_command',
                    'target_id' => $operationId,
                    'details' => json_encode([
                        'requestType' => $data['payload']['request_type'],
                        'status' => 'queued',
                    ], JSON_THROW_ON_ERROR),
                    'ip_address' => $request->ip(),
                    'created_at' => $createdAt,
                ]);
            }

            return $inserted;
        });

        if ($inserted === 1) {
            return response()->json(['operationId' => $operationId, 'status' => 'queued'], 202);
        }

        // The unique school/operation index also makes concurrent retries safe. Reuse the
        // prior result only when the retry is byte-for-byte the same logical command.
        $command = DB::table('relay_parent_commands')
            ->where('school_id', $owner->school_id)
            ->where('operation_id', $operationId)
            ->first();
        abort_if($command === null, 409);
        $sameRequest = (int) $command->user_id === (int) $owner->user_id
            && $command->student_public_id === $data['student_public_id']
            && $command->operation === $data['operation']
            && json_decode($command->payload, true, flags: JSON_THROW_ON_ERROR) == $data['payload'];
        abort_unless($sameRequest, 409, 'This request identifier was already used for different data.');

        return response()->json(['operationId' => $command->operation_id, 'status' => $command->status], 200);
    }

    public function commands(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'parent', 403);
        $accounts = $this->familyAccounts($request);
        $commands = DB::table('relay_parent_commands as commands')
            ->join('relay_parent_links as links', function ($join): void {
                $join->on('links.school_id', '=', 'commands.school_id')
                    ->on('links.user_id', '=', 'commands.user_id')
                    ->on('links.student_public_id', '=', 'commands.student_public_id');
            })
            ->whereIn('commands.user_id', $accounts->modelKeys())
            ->where('links.access_level', 'full')
            ->orderByDesc('commands.id')->limit(100)->get([
                'commands.operation_id', 'commands.school_id', 'commands.student_public_id',
                'commands.status', 'commands.result_message', 'commands.created_at', 'commands.completed_at',
            ])
            ->map(fn (object $command): array => [
                'operationId' => $command->operation_id,
                'schoolId' => $command->school_id,
                'studentPublicId' => $command->student_public_id,
                'status' => $command->status,
                'message' => $command->result_message,
                'createdAt' => $command->created_at,
                'completedAt' => $command->completed_at,
            ])->all();

        return response()->json(['commands' => $commands]);
    }

    /** @return Collection<int, User> */
    private function familyAccounts(Request $request): Collection
    {
        /** @var User $current */
        $current = $request->user();
        if ($current->google_subject === null) {
            return new Collection([$current]);
        }

        return User::query()->with(['school.currentLicense'])
            ->where('role', 'parent')
            ->where('is_active', true)
            ->where('email', $current->email)
            ->where('phone', $current->phone)
            ->where('google_subject', $current->google_subject)
            ->get()
            ->filter(function (User $account): bool {
                $school = $account->school;
                $trialEnabled = (bool) config('school_erp.trial_enabled');
                $googleStates = $trialEnabled ? ['verified', 'bypassed_trial'] : ['verified'];
                $activationStates = $trialEnabled ? ['active', 'bypassed_trial'] : ['active'];
                if ($school === null
                    || ! in_array($school->google_link_status, $googleStates, true)
                    || ! in_array($school->activation_status, $activationStates, true)
                    || $school->service_on_hold) {
                    return false;
                }
                $trialEnded = $school->currentLicense?->status === 'trial'
                    && $school->trial_expires_at !== null
                    && $school->trial_expires_at->isPast();

                return ! $trialEnded;
            })->values();
    }

    private function parentSnapshot(string $payload, string $accessLevel): array
    {
        $snapshot = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if ($accessLevel !== 'schedule_only') {
            return $snapshot;
        }

        $student = is_array($snapshot['student'] ?? null) ? $snapshot['student'] : [];

        return [
            'student' => array_intersect_key($student, array_flip(['fullName', 'className', 'sectionName'])),
            'timetable' => is_array($snapshot['timetable'] ?? null) ? $snapshot['timetable'] : [],
        ];
    }
}
