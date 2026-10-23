<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class MobileDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fid' => ['required', 'string', 'min:20', 'max:512'],
            'platform' => ['required', 'in:android,ios'],
        ]);

        $fidHash = hash('sha256', $data['fid']);
        DB::table('mobile_devices')->updateOrInsert(
            ['fid_hash' => $fidHash],
            [
                'user_id' => $request->user()->id,
                'fid_ciphertext' => Crypt::encryptString($data['fid']),
                'platform' => $data['platform'],
                'last_seen_at' => now(),
                'revoked_at' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return response()->json(['registered' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['fid' => ['required', 'string', 'max:512']]);
        DB::table('mobile_devices')
            ->where('user_id', $request->user()->id)
            ->where('fid_hash', hash('sha256', $data['fid']))
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return response()->json(['revoked' => true]);
    }
}
