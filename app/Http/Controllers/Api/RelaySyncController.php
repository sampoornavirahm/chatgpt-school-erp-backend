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
                    $studentIds = array_values(array_unique(array_map('strtolower', $account['student_public_ids'] ?? [])));
                    DB::table('relay_parent_links')->where('school_id', $school)->where('user_id', $user->id)
                        ->whereNotIn('student_public_id', $studentIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $studentIds)
                        ->delete();
                    foreach ($studentIds as $studentId) {
                        DB::table('relay_parent_links')->insertOrIgnore([
                            'school_id' => $school,
                            'user_id' => $user->id,
                            'student_public_id' => $studentId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
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
            $schoolRow = DB::table('schools')->where('id', $school)->lockForUpdate()->first();
            $existingBatch = DB::table('relay_batches')->where('school_id', $school)->where('batch_id', $data['batch_id'])->first();
            if ($existingBatch !== null) {
                if (! hash_equals($existingBatch->request_hash, $requestHash)) {
                    return ['conflict' => true];
                }

                return ['replayed' => true, 'response' => json_decode($existingBatch->response_json, true, flags: JSON_THROW_ON_ERROR), 'noticeUserIds' => []];
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
            DB::table('relay_batches')->insert([
                'school_id' => $school,
                'batch_id' => $data['batch_id'],
                'request_hash' => $requestHash,
                'response_json' => json_encode($response, JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);

            return ['replayed' => false, 'response' => $response, 'noticeUserIds' => $noticeUserIds];
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
            'results.*.status' => ['required', 'in:accepted,rejected'],
            'results.*.message' => ['sometimes', 'string', 'max:240'],
        ]);
        $updated = 0;
        DB::transaction(function () use ($data, $school, &$updated): void {
            foreach ($data['results'] as $result) {
                $updated += DB::table('relay_parent_commands')
                    ->where('school_id', $school)
                    ->where('operation_id', strtolower($result['operation_id']))
                    ->whereIn('status', ['queued', 'delivered'])
                    ->update([
                        'status' => $result['status'],
                        'result_message' => $result['message'] ?? '',
                        'completed_at' => now(),
                    ]);
            }
        });

        return response()->json(['updated' => $updated]);
    }
}
