<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FcmSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class NotificationTestController extends Controller
{
    public function send(Request $request, FcmSender $sender): JsonResponse
    {
        abort_unless($request->user()->role === 'school_admin', 403);

        try {
            $count = $sender->sendConnectionTest((string) $request->user()->school_id);
        } catch (RuntimeException) {
            return response()->json([
                'error' => [
                    'code' => 'FCM_NOT_CONFIGURED',
                    'message' => 'Push notifications are not configured on this server yet.',
                ],
            ], 503);
        }

        return response()->json(['sent' => $count]);
    }
}
