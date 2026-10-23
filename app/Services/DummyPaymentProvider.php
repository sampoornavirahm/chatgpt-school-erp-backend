<?php

namespace App\Services;

use App\Models\SubscriptionOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DummyPaymentProvider implements PaymentProvider
{
    public function createIntent(SubscriptionOrder $order): array
    {
        $settings = DB::table('platform_payment_settings')->where('id', 1)->first();
        $vpa = (string) ($settings?->merchant_vpa ?: config('school_erp.merchant_upi_vpa'));
        $merchantName = (string) ($settings?->merchant_name ?: config('school_erp.merchant_upi_name', 'School ERP'));
        $paymentUri = null;
        if (preg_match('/^[A-Za-z0-9._-]{2,128}@[A-Za-z0-9.-]{2,64}$/', $vpa)) {
            $paymentUri = 'upi://pay?'.http_build_query([
                'pa' => $vpa,
                'pn' => $merchantName,
                'am' => number_format($order->amount_paise / 100, 2, '.', ''),
                'cu' => 'INR',
                'tr' => $order->id,
                'tn' => 'School ERP '.$order->plan_key,
            ], '', '&', PHP_QUERY_RFC3986);
        }

        return [
            'provider_order_id' => 'dummy_'.Str::lower((string) Str::uuid()),
            'payment_uri' => $paymentUri,
        ];
    }

    public function name(): string
    {
        return 'dummy';
    }
}
