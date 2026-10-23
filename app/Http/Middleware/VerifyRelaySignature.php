<?php

namespace App\Http\Middleware;

use App\Models\School;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyRelaySignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $keyId = $request->header('X-Relay-Key');
        $timestamp = $request->header('X-Relay-Timestamp');
        $nonce = $request->header('X-Relay-Nonce');
        $signature = $request->header('X-Relay-Signature');
        $schoolId = $request->route('school');

        if (! is_string($schoolId) || ! is_string($keyId) || ! is_string($timestamp)
            || ! ctype_digit($timestamp) || ! is_string($nonce) || ! preg_match('/^[A-Za-z0-9_-]{20,64}$/', $nonce)
            || ! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/', $signature)
            || abs(now()->timestamp - (int) $timestamp) > 300) {
            return response()->json(['error' => ['code' => 'RELAY_AUTH_INVALID', 'message' => 'Relay authentication failed.']], 401);
        }

        $school = School::query()->find($schoolId);
        $credential = DB::table('school_relay_credentials')
            ->where('school_id', $schoolId)
            ->where('key_id', $keyId)
            ->where('is_active', true)
            ->first();

        if ($school === null || $credential === null || $school->relay_status !== 'active') {
            return response()->json(['error' => ['code' => 'RELAY_AUTH_INVALID', 'message' => 'Relay authentication failed.']], 401);
        }

        try {
            $secret = Crypt::decryptString($credential->secret_ciphertext);
        } catch (Throwable) {
            return response()->json(['error' => ['code' => 'RELAY_AUTH_INVALID', 'message' => 'Relay authentication failed.']], 401);
        }

        $bodyHash = hash('sha256', $request->getContent());
        $signedContent = implode("\n", [$request->method(), $request->getRequestUri(), $timestamp, $nonce, $bodyHash]);
        $expected = hash_hmac('sha256', $signedContent, $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => ['code' => 'RELAY_AUTH_INVALID', 'message' => 'Relay authentication failed.']], 401);
        }

        try {
            DB::table('relay_request_nonces')->insert([
                'school_id' => $schoolId,
                'nonce' => $nonce,
                'created_at' => now(),
            ]);
            DB::table('relay_request_nonces')->where('created_at', '<', now()->subDay())->delete();
        } catch (Throwable) {
            return response()->json(['error' => ['code' => 'RELAY_REPLAY_REJECTED', 'message' => 'This signed request was already received.']], 409);
        }

        DB::table('school_relay_credentials')->where('school_id', $schoolId)->update(['last_used_at' => now(), 'updated_at' => now()]);
        $request->attributes->set('relay_school', $school);

        return $next($request);
    }
}
