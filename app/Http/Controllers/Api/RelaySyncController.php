<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FcmSender;
use App\Services\LicenseTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RelaySyncController extends Controller
{
    private const MAX_BATCH_RECORDS = 50;

    private const MAX_SNAPSHOT_BYTES = 1_000_000;

    public function syncAccounts(Request $request, string $school): JsonResponse
    {
        $data = $request->validate([
            'accounts' => ['required', 'array', 'max:500'],
            'accounts.*.relay_user_id' => ['required', 'integer', 'min:1'],
            'accounts.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'accounts.*.email' => ['required', 'email:rfc', 'max:255'],
            'accounts.*.phone' => ['required', 'string', 'regex:/^\\+?[0-9][0-9 .()-]{7,20}$/'],
            'accounts.*.role' => ['required', 'in:parent,teacher'],
            'accounts.*.is_active' => ['required', 'boolean'],
            'accounts.*.student_public_ids' => ['sometimes', 'array', 'max:100'],
            'accounts.*.student_public_ids.*' => ['required', 'uuid'],
            'accounts.*.student_access' => ['sometimes', 'array', 'max:100'],
            'accounts.*.student_access.*.student_public_id' => ['required', 'uuid'],
            'accounts.*.student_access.*.access_level' => ['required', 'in:full,schedule_only'],
        ]);
        $results = [];
        DB::transaction(function () use ($data, $school, &$results): void {
            foreach ($data['accounts'] as $account) {
                $email = strtolower($account['email']);
                $phone = preg_replace('/\\D+/', '', $account['phone']);
                $user = User::query()->where('school_id', $school)->where('relay_user_id', $account['relay_user_id'])->lockForUpdate()->first();
                if ($user === null) {
                    $user = User::query()->where('school_id', $school)->where('email', $email)->lockForUpdate()->first();
                }
                if ($user !== null && $user->role !== $account['role']) {
                    $results[] = ['relayUserId' => $account['relay_user_id'], 'status' => 'conflict'];

                    continue;
                }
                if ($user === null) {
                    $user = User::query()->create([
                        'school_id' => $school,
                        'relay_user_id' => $account['relay_user_id'],
                        'name' => $account['name'],
                        'email' => $email,
                        'phone' => $phone,
                        'password' => Hash::make(Str::random(64)),
                        'role' => $account['role'],
                        'is_active' => $account['is_active'],
                    ]);
                } else {
                    if ($user->relay_user_id !== null && (int) $user->relay_user_id !== (int) $account['relay_user_id']) {
                        $results[] = ['relayUserId' => $account['relay_user_id'], 'status' => 'conflict'];

                        continue;
                    }
                    $user->forceFill([
                        'relay_user_id' => $account['relay_user_id'],
                        'name' => $account['name'],
                        'email' => $email,
                        'phone' => $phone,
                        'is_active' => $account['is_active'],
                    ])->save();
                }
                if (! $account['is_active']) {
                    $user->tokens()->delete();
                }
                if ($account['role'] === 'parent') {
                    $accessByStudent = [];
                    foreach ($account['student_access'] ?? [] as $studentAccess) {
                        $accessByStudent[strtolower($studentAccess['student_public_id'])] = $studentAccess['access_level'];
                    }
                    if (! array_key_exists('student_access', $account)) {
                        foreach ($account['student_public_ids'] ?? [] as $studentId) {
                            $accessByStudent[strtolower($studentId)] = 'full';
                        }
                    }
                    $studentIds = array_keys($accessByStudent);
                    DB::table('relay_parent_links')->where('school_id', $school)->where('user_id', $user->id)
                        ->whereNotIn('student_public_id', $studentIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $studentIds)
                        ->delete();
                    if ($studentIds !== []) {
                        $now = now();
                        $links = array_map(static fn (string $studentId): array => [
                            'school_id' => $school,
                            'user_id' => $user->id,
                            'student_public_id' => $studentId,
                            'access_level' => $accessByStudent[$studentId],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ], $studentIds);
                        DB::table('relay_parent_links')->upsert(
                            $links,
                            ['school_id', 'user_id', 'student_public_id'],
                            ['access_level', 'updated_at'],
                        );
                    }
                }
                $results[] = ['relayUserId' => (int) $account['relay_user_id'], 'status' => 'accepted'];
            }
        }, 3);

        return response()->json(['results' => $results]);
    }

    public function mainPasswordChange(string $school): JsonResponse
    {
        $serverId = DB::table('schools')->where('id', $school)->value('server_id');
        if (! is_string($serverId)) {
            return response()->json(['change' => null]);
        }
        $change = DB::table('school_main_password_changes')
            ->where('school_id', $school)
            ->where('server_id', $serverId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();
        if ($change === null) {
            DB::table('school_main_password_changes')
                ->where('school_id', $school)
                ->where('status', 'pending')
                ->where('expires_at', '<=', now())
                ->update(['status' => 'expired', 'password_ciphertext' => '', 'updated_at' => now()]);

            return response()->json(['change' => null]);
        }

        try {
            $password = Crypt::decryptString($change->password_ciphertext);
        } catch (Throwable) {
            return response()->json(['error' => ['code' => 'PASSWORD_CHANGE_UNAVAILABLE', 'message' => 'The queued password change could not be read.']], 500);
        }

        return response()->json(['change' => ['id' => $change->id, 'password' => $password]]);
    }

    public function acknowledgeMainPasswordChange(Request $request, string $school, string $change): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:applied,failed'],
            'message' => ['nullable', 'string', 'max:240'],
        ]);
        $serverId = DB::table('schools')->where('id', $school)->value('server_id');
        $row = DB::table('school_main_password_changes')
            ->where('id', $change)
            ->where('school_id', $school)
            ->where('server_id', $serverId)
            ->where('status', 'pending')
            ->first();
        if ($row === null) {
            return response()->json(['updated' => 0]);
        }

        DB::table('school_main_password_changes')->where('id', $change)->update([
            'status' => $data['status'],
            'result_message' => $data['message'] ?? null,
            'password_ciphertext' => '',
            'applied_at' => $data['status'] === 'applied' ? now() : null,
            'updated_at' => now(),
        ]);
        DB::table('relay_audit_logs')->insert([
            'school_id' => $school,
            'actor_user_id' => $row->created_by,
            'action' => $data['status'] === 'applied' ? 'school_main_password_change_applied' : 'school_main_password_change_failed',
            'target_type' => 'school_account',
            'target_id' => $change,
            'details' => json_encode(['message' => $data['message'] ?? null], JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json(['updated' => 1]);
    }

    public function pushCalendar(Request $request, string $school, LicenseTokenService $tokens, FcmSender $notifications): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'content_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'events' => ['required', 'array', 'max:300'],
            'events.*.id' => ['required', 'integer', 'min:1'],
            'events.*.title' => ['required', 'string', 'max:160'],
            'events.*.eventType' => ['required', 'in:holiday,closure,exam,trip,deadline,event,ptm,other'],
            'events.*.startDate' => ['required', 'date_format:Y-m-d'],
            'events.*.endDate' => ['nullable', 'date_format:Y-m-d'],
            'events.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);
        foreach ($data['events'] as $index => $event) {
            if (($event['endDate'] ?? null) !== null && $event['endDate'] < $event['startDate']) {
                throw ValidationException::withMessages(["events.$index.endDate" => ['The event end date must be on or after its start date.']]);
            }
        }
        $events = array_values($data['events']);
        $payload = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! hash_equals($data['content_hash'], hash('sha256', $payload)) || strlen($payload) > self::MAX_SNAPSHOT_BYTES) {
            throw ValidationException::withMessages(['content_hash' => ['The calendar integrity hash or payload size is invalid.']]);
        }

        $result = DB::transaction(function () use ($data, $school, $tokens, $payload): array {
            $schoolRow = DB::table('schools')->where('id', $school)->lockForUpdate()->first();
            if ($schoolRow === null) {
                return ['missing' => true];
            }
            if ($schoolRow->current_license_id !== null) {
                $currentLicense = DB::table('school_licenses')->where('id', $schoolRow->current_license_id)->first();
                $verification = $currentLicense === null ? ['valid' => false] : $tokens->verify($currentLicense->signed_token);
                $isRevoked = $schoolRow->license_status === 'suspended' || $currentLicense?->status === 'revoked';
                $isExpired = $currentLicense === null || ! ($verification['valid'] ?? false) || ($verification['state'] ?? null) === 'expired';
                if ($isRevoked || $isExpired) {
                    return ['blocked' => $isRevoked ? 'LICENSE_REVOKED_READ_ONLY' : 'LICENSE_EXPIRED_READ_ONLY'];
                }
            }

            $current = DB::table('relay_school_calendars')->where('school_id', $school)->lockForUpdate()->first();
            $revision = (int) $data['revision'];
            if ($current !== null && $revision < (int) $current->revision) {
                return ['status' => 'stale', 'revision' => (int) $current->revision];
            }
            if ($current !== null && $revision === (int) $current->revision) {
                if (hash_equals($current->content_hash, $data['content_hash'])) {
                    return ['status' => 'unchanged', 'revision' => $revision];
                }

                return ['conflict' => true, 'revision' => (int) $current->revision];
            }

            DB::table('relay_school_calendars')->updateOrInsert(
                ['school_id' => $school],
                [
                    'revision' => $revision,
                    'content_hash' => $data['content_hash'],
                    'payload' => $payload,
                    'projected_at' => now(),
                ],
            );
            $userIds = $current === null
                ? []
                : DB::table('users')->where('school_id', $school)->where('is_active', true)
                    ->whereIn('role', ['parent', 'teacher'])->orderBy('id')->pluck('id')
                    ->map(static fn ($id): int => (int) $id)->all();

            return ['status' => 'accepted', 'revision' => $revision, 'changed' => $current !== null, 'userIds' => $userIds];
        }, 3);

        if ($result['missing'] ?? false) {
            return response()->json(['error' => ['code' => 'SCHOOL_NOT_FOUND']], 404);
        }
        if (isset($result['blocked'])) {
            return response()->json(['error' => ['code' => $result['blocked']]], 403);
        }
        if ($result['conflict'] ?? false) {
            return response()->json(['error' => ['code' => 'CALENDAR_REVISION_CONFLICT']], 409);
        }
        if (($result['status'] ?? null) === 'stale') {
            return response()->json(['status' => 'stale', 'revision' => $result['revision']], 409);
        }
        if (($result['changed'] ?? false) && ($result['userIds'] ?? []) !== []) {
            defer(fn () => $notifications->sendScheduleUpdate($school, $result['userIds']));
        }

        return response()->json(['status' => $result['status'], 'revision' => $result['revision']]);
    }

    public function push(Request $request, string $school, LicenseTokenService $tokens, FcmSender $notifications): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'uuid'],
            'snapshots' => ['required', 'array', 'min:1', 'max:50'],
            'snapshots.*.student_public_id' => ['required', 'uuid'],
            'snapshots.*.revision' => ['required', 'integer', 'min:1'],
            'snapshots.*.snapshot' => ['required', 'array'],
        ]);
        $snapshotKeys = ['student', 'attendance', 'timetable', 'homework', 'announcements', 'results', 'fees'];
        foreach ($data['snapshots'] as $index => $record) {
            $snapshot = $record['snapshot'];
            $deleted = ($snapshot['deleted'] ?? false) === true;
            if (array_diff(array_keys($snapshot), $deleted ? ['deleted'] : $snapshotKeys) !== []
                || (! $deleted && ! isset($snapshot['student']))) {
                throw ValidationException::withMessages(["snapshots.$index.snapshot" => ['Snapshot includes unsupported or missing fields.']]);
            }
            if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > self::MAX_SNAPSHOT_BYTES) {
                throw ValidationException::withMessages(["snapshots.$index.snapshot" => ['A student snapshot exceeds the 1 MB relay limit.']]);
            }
        }

        $requestHash = hash('sha256', $request->getContent());
        $now = now();
        $result = DB::transaction(function () use ($data, $school, $requestHash, $now, $tokens): array {
            $newNoticeStudentIds = [];
            $changedScheduleStudentIds = [];
            $newHomeworkStudentIds = [];
            $schoolRow = DB::table('schools')->where('id', $school)->lockForUpdate()->first();
            $existingBatch = DB::table('relay_batches')->where('school_id', $school)->where('batch_id', $data['batch_id'])->first();
            if ($existingBatch !== null) {
                if (! hash_equals($existingBatch->request_hash, $requestHash)) {
                    return ['conflict' => true];
                }

                return ['replayed' => true, 'response' => json_decode($existingBatch->response_json, true, flags: JSON_THROW_ON_ERROR), 'noticeUserIds' => [], 'scheduleUserIds' => [], 'homeworkUserIds' => []];
            }

            $responses = [];
            $storedBytes = (int) DB::table('relay_snapshots')->where('school_id', $school)->sum('payload_bytes');
            $activeStudents = (int) DB::table('relay_snapshots')->where('school_id', $school)->where('is_deleted', false)->count();
            $studentLimit = (int) $schoolRow->active_student_limit;
            if ($schoolRow->current_license_id !== null) {
                $currentLicense = DB::table('school_licenses')->where('id', $schoolRow->current_license_id)->first();
                $verification = $currentLicense === null ? ['valid' => false] : $tokens->verify($currentLicense->signed_token);
                $isRevoked = $schoolRow->license_status === 'suspended' || $currentLicense?->status === 'revoked';
                $isExpired = $currentLicense === null || ! ($verification['valid'] ?? false) || ($verification['state'] ?? null) === 'expired';
                if ($isRevoked || $isExpired) {
                    return ['blocked' => [
                        'code' => $isRevoked ? 'LICENSE_REVOKED_READ_ONLY' : 'LICENSE_EXPIRED_READ_ONLY',
                        'message' => $isRevoked
                            ? 'This school license was revoked. New relay changes are blocked.'
                            : 'This school license has expired. New relay changes are blocked.',
                    ]];
                }
                $claims = $verification['claims'] ?? [];
                if (array_key_exists('students', $claims['limits'] ?? [])) {
                    $studentLimit = $claims['limits']['students'];
                }
            }
            foreach ($data['snapshots'] as $record) {
                $studentId = strtolower($record['student_public_id']);
                $isDeleted = ($record['snapshot']['deleted'] ?? false) === true;
                $payloadJson = json_encode($isDeleted ? ['deleted' => true] : $record['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $payloadBytes = strlen($payloadJson);
                $contentHash = hash('sha256', $payloadJson);
                $current = DB::table('relay_snapshots')->where('school_id', $school)->where('student_public_id', $studentId)->lockForUpdate()->first();

                if ($current !== null && (int) $record['revision'] < (int) $current->revision) {
                    $responses[] = ['studentPublicId' => $studentId, 'status' => 'stale', 'revision' => (int) $current->revision];

                    continue;
                }
                if ($current !== null && (int) $record['revision'] === (int) $current->revision) {
                    $responses[] = hash_equals($current->content_hash, $contentHash)
                        ? ['studentPublicId' => $studentId, 'status' => 'accepted', 'revision' => (int) $current->revision, 'unchanged' => true]
                        : ['studentPublicId' => $studentId, 'status' => 'conflict', 'revision' => (int) $current->revision];

                    continue;
                }

                $addsActiveSeat = ! $isDeleted && ($current === null || (bool) $current->is_deleted);
                if ($addsActiveSeat && $studentLimit !== null && $activeStudents >= $studentLimit) {
                    $responses[] = ['studentPublicId' => $studentId, 'status' => 'rejected', 'code' => 'SCHOOL_STUDENT_CAPACITY_EXCEEDED'];

                    continue;
                }

                $nextBytes = $storedBytes - (int) ($current->payload_bytes ?? 0) + $payloadBytes;
                if ($nextBytes > (int) $schoolRow->storage_quota_bytes) {
                    $responses[] = ['studentPublicId' => $studentId, 'status' => 'rejected', 'code' => 'SCHOOL_STORAGE_QUOTA_EXCEEDED'];

                    continue;
                }
                if ($current !== null && ! $isDeleted) {
                    $priorSnapshot = json_decode($current->payload, true, flags: JSON_THROW_ON_ERROR);
                    $priorNoticeIds = $this->publishedNoticeIds($priorSnapshot['announcements'] ?? []);
                    $nextNoticeIds = $this->publishedNoticeIds($record['snapshot']['announcements'] ?? []);
                    if (array_diff($nextNoticeIds, $priorNoticeIds) !== []) {
                        $newNoticeStudentIds[$studentId] = $studentId;
                    }
                    if (($priorSnapshot['timetable'] ?? []) !== ($record['snapshot']['timetable'] ?? [])) {
                        $changedScheduleStudentIds[$studentId] = $studentId;
                    }
                    $priorHomeworkIds = $this->publishedHomeworkIds($priorSnapshot['homework'] ?? []);
                    $nextHomeworkIds = $this->publishedHomeworkIds($record['snapshot']['homework'] ?? []);
                    if (array_diff($nextHomeworkIds, $priorHomeworkIds) !== []) {
                        $newHomeworkStudentIds[$studentId] = $studentId;
                    }
                }
                DB::table('relay_snapshots')->updateOrInsert(
                    ['school_id' => $school, 'student_public_id' => $studentId],
                    [
                        'revision' => $record['revision'],
                        'content_hash' => $contentHash,
                        'payload' => $payloadJson,
                        'payload_bytes' => $payloadBytes,
                        'is_deleted' => $isDeleted,
                        'projected_at' => $now,
                    ],
                );
                $storedBytes = $nextBytes;
                if ($addsActiveSeat) {
                    $activeStudents++;
                } elseif ($isDeleted && $current !== null && ! (bool) $current->is_deleted) {
                    $activeStudents--;
                }
                $responses[] = ['studentPublicId' => $studentId, 'status' => 'accepted', 'revision' => (int) $record['revision']];
            }

            $response = ['results' => $responses, 'storedBytes' => $storedBytes, 'cursor' => (int) DB::table('relay_parent_commands')->where('school_id', $school)->max('id')];
            $noticeUserIds = $newNoticeStudentIds === []
                ? []
                : DB::table('relay_parent_links')
                    ->where('school_id', $school)
                    ->whereIn('student_public_id', array_values($newNoticeStudentIds))
                    ->distinct()
                    ->orderBy('user_id')
                    ->pluck('user_id')
                    ->map(static fn ($userId): int => (int) $userId)
                    ->all();
            $scheduleUserIds = $changedScheduleStudentIds === []
                ? []
                : DB::table('relay_parent_links')
                    ->where('school_id', $school)
                    ->whereIn('student_public_id', array_values($changedScheduleStudentIds))
                    ->distinct()
                    ->orderBy('user_id')
                    ->pluck('user_id')
                    ->map(static fn ($userId): int => (int) $userId)
                    ->all();
            $homeworkUserIds = $newHomeworkStudentIds === []
                ? []
                : DB::table('relay_parent_links')
                    ->join('users', 'users.id', '=', 'relay_parent_links.user_id')
                    ->where('relay_parent_links.school_id', $school)
                    ->whereIn('relay_parent_links.student_public_id', array_values($newHomeworkStudentIds))
                    ->where('users.school_id', $school)
                    ->where('users.role', 'parent')
                    ->where('users.is_active', true)
                    ->distinct()
                    ->orderBy('relay_parent_links.user_id')
                    ->pluck('relay_parent_links.user_id')
                    ->map(static fn ($userId): int => (int) $userId)
                    ->all();
            DB::table('relay_batches')->insert([
                'school_id' => $school,
                'batch_id' => $data['batch_id'],
                'request_hash' => $requestHash,
                'response_json' => json_encode($response, JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);

            return ['replayed' => false, 'response' => $response, 'noticeUserIds' => $noticeUserIds, 'scheduleUserIds' => $scheduleUserIds, 'homeworkUserIds' => $homeworkUserIds];
        }, 3);

        if (isset($result['blocked'])) {
            return response()->json(['error' => $result['blocked']], 403);
        }
        if ($result['conflict'] ?? false) {
            return response()->json(['error' => ['code' => 'BATCH_ID_REUSED', 'message' => 'The batch identifier was already used for a different payload.']], 409);
        }

        if (($result['noticeUserIds'] ?? []) !== []) {
            defer(fn () => $notifications->sendSchoolNotice($school, $result['noticeUserIds']));
        }
        if (($result['scheduleUserIds'] ?? []) !== []) {
            defer(fn () => $notifications->sendScheduleUpdate($school, $result['scheduleUserIds']));
        }
        if (($result['homeworkUserIds'] ?? []) !== []) {
            defer(fn () => $notifications->sendHomeworkUpdate($school, $result['homeworkUserIds']));
        }

        return response()->json($result['response'])->header('Idempotency-Replayed', ($result['replayed'] ?? false) ? 'true' : 'false');
    }

    /** @return list<string> */
    private function publishedNoticeIds(mixed $announcements): array
    {
        if (! is_array($announcements)) {
            return [];
        }

        $ids = [];
        foreach ($announcements as $announcement) {
            if (! is_array($announcement) || (! is_string($announcement['id'] ?? null) && ! is_int($announcement['id'] ?? null))) {
                continue;
            }
            $id = (string) $announcement['id'];
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** @return list<string> */
    private function publishedHomeworkIds(mixed $homework): array
    {
        if (! is_array($homework)) {
            return [];
        }

        $ids = [];
        foreach ($homework as $assignment) {
            if (! is_array($assignment) || (! is_string($assignment['id'] ?? null) && ! is_int($assignment['id'] ?? null))) {
                continue;
            }
            $id = (string) $assignment['id'];
            if (preg_match('/^[1-9][0-9]*$/', $id) === 1) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public function pull(Request $request, string $school): JsonResponse
    {
        $data = $request->validate(['cursor' => ['sometimes', 'integer', 'min:0']]);
        $cursor = (int) ($data['cursor'] ?? 0);
        $commands = DB::table('relay_parent_commands')
            ->where('school_id', $school)
            ->where('id', '>', $cursor)
            ->where('status', 'queued')
            ->orderBy('id')
            ->limit(100)
            ->get();
        $items = $commands->map(fn (object $command): array => [
            'cursor' => (int) $command->id,
            'operationId' => $command->operation_id,
            'userId' => $command->user_id,
            'studentPublicId' => $command->student_public_id,
            'operation' => $command->operation,
            'payload' => json_decode($command->payload, true, flags: JSON_THROW_ON_ERROR),
            'createdAt' => $command->created_at,
        ])->all();

        return response()->json(['commands' => $items, 'cursor' => $items === [] ? $cursor : end($items)['cursor']]);
    }

    public function acknowledge(Request $request, string $school): JsonResponse
    {
        $data = $request->validate([
            'results' => ['required', 'array', 'min:1', 'max:100'],
            'results.*.operation_id' => ['required', 'uuid'],
            'results.*.status' => ['required', 'in:accepted,rejected,request_approved,request_rejected,needs_info'],
            'results.*.message' => ['sometimes', 'string', 'max:240'],
        ]);
        $updated = 0;
        DB::transaction(function () use ($data, $school, $request, &$updated): void {
            foreach ($data['results'] as $result) {
                $operationId = strtolower($result['operation_id']);
                $command = DB::table('relay_parent_commands')
                    ->where('school_id', $school)
                    ->where('operation_id', $operationId)
                    ->whereIn('status', ['queued', 'delivered', 'accepted'])
                    ->lockForUpdate()
                    ->first(['status', 'result_message']);
                if ($command === null) {
                    continue;
                }

                $message = $result['message'] ?? '';
                if ($command->status === $result['status'] && $command->result_message === $message) {
                    continue;
                }

                $completedAt = now();
                $changed = DB::table('relay_parent_commands')
                    ->where('school_id', $school)
                    ->where('operation_id', $operationId)
                    ->whereIn('status', ['queued', 'delivered', 'accepted'])
                    ->update([
                        'status' => $result['status'],
                        'result_message' => $message,
                        'completed_at' => $completedAt,
                    ]);
                $updated += $changed;

                if ($changed === 1) {
                    DB::table('relay_audit_logs')->insert([
                        'school_id' => $school,
                        'actor_user_id' => null,
                        'action' => 'parent_request_status_received',
                        'target_type' => 'parent_command',
                        'target_id' => $operationId,
                        'details' => json_encode([
                            'from' => $command->status,
                            'to' => $result['status'],
                            'messageProvided' => $message !== '',
                            'source' => 'signed_school_hub_relay',
                        ], JSON_THROW_ON_ERROR),
                        'ip_address' => $request->ip(),
                        'created_at' => $completedAt,
                    ]);
                }
            }
        });

        return response()->json(['updated' => $updated]);
    }
}
