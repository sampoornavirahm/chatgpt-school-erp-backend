<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FcmSender
{
    public function __construct(private readonly FcmAccessTokenProvider $accessTokens) {}

    public function sendConnectionTest(string $schoolId): int
    {
        return $this->sendToDevices(
            $schoolId,
            null,
            'connection_test',
            'School ERP',
            'This is a notification connection test.',
        );
    }

    /** @param list<int> $userIds */
    public function sendSchoolNotice(string $schoolId, array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        try {
            return $this->sendToDevices(
                $schoolId,
                $userIds,
                'school_notice',
                'School ERP',
                'A new school notice is available. Open School messages to read it.',
            );
        } catch (Throwable) {
            // Push is optional; the synced in-app Notices page remains available.
            Log::warning('Optional school notice push could not be sent.');

            return 0;
        }
    }

    /** @param list<int> $userIds */
    public function sendScheduleUpdate(string $schoolId, array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        try {
            return $this->sendToDevices(
                $schoolId,
                $userIds,
                'schedule_update',
                'School schedule updated',
                'Open Learning to see the latest timetable and calendar.',
            );
        } catch (Throwable) {
            // Schedule data still appears in the synced in-app view when push is unavailable.
            Log::warning('Optional schedule update push could not be sent.');

            return 0;
        }
    }

    /** @param list<int> $userIds */
    public function sendHomeworkUpdate(string $schoolId, array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        try {
            return $this->sendToDevices(
                $schoolId,
                $userIds,
                'homework_update',
                'New homework is available',
                'Open School ERP to review the assignment and due date.',
            );
        } catch (Throwable) {
            // Assignment details are available in the synced parent view when push is unavailable.
            Log::warning('Optional homework push could not be sent.');

            return 0;
        }
    }

    /** @param list<int>|null $userIds */
    private function sendToDevices(
        string $schoolId,
        ?array $userIds,
        string $type,
        string $title,
        string $body,
    ): int {
        $projectId = (string) config('school_erp.firebase_project_id');
        $serviceAccountPath = (string) config('school_erp.firebase_service_account');
        $realPath = $serviceAccountPath !== '' ? realpath($serviceAccountPath) : false;

        $publicPath = realpath(public_path());
        if ($projectId === '' || $realPath === false || ! is_readable($realPath)
            || ($publicPath !== false && str_starts_with($realPath, $publicPath.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('FCM credentials are not configured.');
        }

        $installationsQuery = DB::table('mobile_devices')
            ->join('users', 'users.id', '=', 'mobile_devices.user_id')
            ->where('users.school_id', $schoolId)
            ->whereNull('mobile_devices.revoked_at')
            ->select(['mobile_devices.id', 'mobile_devices.fid_ciphertext']);
        if ($userIds !== null) {
            $installationsQuery->whereIn('users.id', $userIds);
        }
        if (in_array($type, ['school_notice', 'schedule_update', 'homework_update'], true)) {
            $installationsQuery->where(function ($query) use ($type): void {
                $query->where('users.role', '<>', 'parent')
                    ->orWhereNotExists(function ($preferences) use ($type): void {
                        $preferences->selectRaw('1')
                            ->from('parent_notification_preferences')
                            ->whereColumn('parent_notification_preferences.user_id', 'users.id')
                            ->where('parent_notification_preferences.category', $type)
                            ->where('parent_notification_preferences.enabled', false);
                    });
            });
        }
        $installations = $installationsQuery->get();

        if ($installations->isEmpty()) {
            return 0;
        }

        $accessToken = $this->accessTokens->accessToken($realPath);

        $sent = 0;
        $endpoint = 'https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send';
        foreach ($installations as $device) {
            try {
                $fid = Crypt::decryptString($device->fid_ciphertext);
                $response = Http::acceptJson()
                    ->withToken($accessToken)
                    ->connectTimeout(3)
                    ->timeout(8)
                    ->post($endpoint, [
                        'message' => [
                            'fid' => $fid,
                            'notification' => ['title' => $title, 'body' => $body],
                            'data' => ['type' => $type],
                        ],
                    ]);
                if ($response->successful()) {
                    $sent++;
                } elseif ($response->json('error.details.0.errorCode') === 'UNREGISTERED') {
                    DB::table('mobile_devices')->where('id', $device->id)->update([
                        'revoked_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } catch (Throwable) {
                // One stale device token must not disclose credentials or block other recipients.
            }
        }

        return $sent;
    }
}
