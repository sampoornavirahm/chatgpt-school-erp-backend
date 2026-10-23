<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchoolPaymentSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === 'school_admin', 403);
        $settings = DB::table('school_payment_settings')->where('school_id', $user->school_id)->first();

        return response()->json(['settings' => $this->safeSettings($settings)]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === 'school_admin', 403);
        $data = $request->validate([
            'provider' => ['required', 'in:none,vpa,razorpay,payu'],
            'merchant_vpa' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]{2,100}@[A-Za-z0-9.-]{2,100}$/'],
            'merchant_name' => ['nullable', 'string', 'max:120'],
            'provider_account_id' => ['nullable', 'string', 'max:160'],
            'credentials' => ['nullable', 'array', 'max:12'],
            'credentials.*' => ['required', 'string', 'max:1024'],
            'is_enabled' => ['required', 'boolean'],
        ]);
        $existing = DB::table('school_payment_settings')->where('school_id', $user->school_id)->first();
        if ($data['provider'] === 'vpa' && empty($data['merchant_vpa'])) {
            throw ValidationException::withMessages(['merchant_vpa' => ['Enter the school merchant VPA for UPI payments.']]);
        }
        if (in_array($data['provider'], ['razorpay', 'payu'], true)
            && (empty($data['provider_account_id']) || (empty($data['credentials']) && ($existing?->provider !== $data['provider'] || empty($existing?->credentials_ciphertext))))) {
            throw ValidationException::withMessages(['credentials' => ['Add the account credentials before enabling this gateway.']]);
        }

        $credentialsCiphertext = $existing?->credentials_ciphertext;
        if (isset($data['credentials'])) {
            $credentialsCiphertext = Crypt::encryptString(json_encode($data['credentials'], JSON_THROW_ON_ERROR));
        } elseif ($data['provider'] === 'none' || $data['provider'] === 'vpa') {
            $credentialsCiphertext = null;
        }
        DB::table('school_payment_settings')->updateOrInsert(
            ['school_id' => $user->school_id],
            [
                'provider' => $data['provider'],
                'merchant_vpa' => $data['merchant_vpa'] ?? null,
                'merchant_name' => $data['merchant_name'] ?? null,
                'provider_account_id' => $data['provider_account_id'] ?? null,
                'credentials_ciphertext' => $credentialsCiphertext,
                'is_enabled' => $data['is_enabled'],
                'updated_by' => $user->id,
                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
        $settings = DB::table('school_payment_settings')->where('school_id', $user->school_id)->first();

        return response()->json(['settings' => $this->safeSettings($settings)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeSettings(?object $settings): ?array
    {
        if ($settings === null) {
            return null;
        }

        return [
            'provider' => $settings->provider,
            'merchant_vpa' => $settings->merchant_vpa,
            'merchant_name' => $settings->merchant_name,
            'provider_account_id' => $settings->provider_account_id,
            'credentials_configured' => $settings->credentials_ciphertext !== null,
            'is_enabled' => (bool) $settings->is_enabled,
            'updated_at' => $settings->updated_at,
        ];
    }
}
