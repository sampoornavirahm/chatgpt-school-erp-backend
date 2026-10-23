<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PaymentSettingsController extends Controller
{
    public function index(): View
    {
        $settings = DB::table('platform_payment_settings')->where('id', 1)->first();

        return view('operator.payments', [
            'merchantVpa' => $settings?->merchant_vpa ?: config('school_erp.merchant_upi_vpa'),
            'merchantName' => $settings?->merchant_name ?: config('school_erp.merchant_upi_name', 'School ERP'),
            'paymentDriver' => config('school_erp.payment_driver'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'merchant_vpa' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]{2,128}@[A-Za-z0-9.-]{2,64}$/'],
            'merchant_name' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        DB::table('platform_payment_settings')->updateOrInsert(
            ['id' => 1],
            [
                'merchant_vpa' => $data['merchant_vpa'],
                'merchant_name' => $data['merchant_name'],
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return back()->with('status', 'Merchant UPI details saved. New payment instructions will use this VPA.');
    }
}
