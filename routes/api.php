<?php

use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AppUpdateController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\LicensingController;
use App\Http\Controllers\Api\MobileDeviceController;
use App\Http\Controllers\Api\NotificationTestController;
use App\Http\Controllers\Api\ParentRelayController;
use App\Http\Controllers\Api\RelaySyncController;
use App\Http\Controllers\Api\SchoolPaymentSettingsController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\WebhookReceiptController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    try {
        DB::connection()->select('SELECT 1');

        return response()->json(['status' => 'ok', 'service' => 'school-erp-backend', 'database' => 'connected']);
    } catch (Throwable) {
        Log::warning('School ERP backend health check could not connect to its database.');

        return response()->json(['status' => 'degraded', 'service' => 'school-erp-backend', 'database' => 'unavailable'], 503);
    }
})
    ->name('api.health');

Route::get('/setup/status', [SetupController::class, 'status'])->name('setup.status');
Route::post('/setup/complete', [SetupController::class, 'complete'])
    ->middleware('throttle:5,1')
    ->name('setup.complete');
Route::post('/setup/activate', [SetupController::class, 'activate'])
    ->middleware('throttle:5,1')
    ->name('setup.activate');

Route::get('/licensing/plans', [LicensingController::class, 'plans'])->name('licensing.plans');
Route::post('/licensing/current', [LicensingController::class, 'currentForSchool'])->middleware('throttle:10,1')->name('licensing.current');
Route::post('/licensing/trial-extension-requests/server', [LicensingController::class, 'requestTrialExtensionFromServer'])->middleware('throttle:3,60')->name('licensing.trial-extension-requests.server');
Route::get('/licensing/public-key', [LicensingController::class, 'publicKey'])->name('licensing.public-key');
Route::get('/licensing/enrollment-config', [LicensingController::class, 'enrollmentConfig'])->name('licensing.enrollment-config');
Route::post('/licensing/verify', [LicensingController::class, 'verify'])->middleware('throttle:60,1')->name('licensing.verify');
Route::post('/licensing/activate', [LicensingController::class, 'activate'])->middleware('throttle:10,1')->name('licensing.activate');
Route::post('/licensing/enroll', [LicensingController::class, 'enroll'])->middleware('throttle:5,1')->name('licensing.enroll');
Route::post('/licensing/enrollment-status', [LicensingController::class, 'enrollmentStatus'])->middleware('throttle:30,1')->name('licensing.enrollment-status');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('auth.login');
Route::post('/auth/google-login', [AuthController::class, 'googleLogin'])
    ->middleware('throttle:10,1')
    ->name('auth.google-login');
Route::get('/app-updates', [AppUpdateController::class, 'show'])
    ->middleware('throttle:60,1')->name('app-updates.show');

Route::post('/webhooks/{provider}', [WebhookReceiptController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('webhooks.receipts.store');

Route::prefix('/relay/schools/{school}')
    ->middleware(['relay.signature', 'throttle:120,1'])
    ->group(function (): void {
        Route::post('/sync/push', [RelaySyncController::class, 'push'])->name('relay.sync.push');
        Route::get('/sync/pull', [RelaySyncController::class, 'pull'])->name('relay.sync.pull');
        Route::post('/sync/ack', [RelaySyncController::class, 'acknowledge'])->name('relay.sync.ack');
        Route::post('/accounts/sync', [RelaySyncController::class, 'syncAccounts'])->name('relay.accounts.sync');
        Route::get('/main-password-change', [RelaySyncController::class, 'mainPasswordChange'])->name('relay.main-password-change.show');
        Route::post('/main-password-change/{change}/ack', [RelaySyncController::class, 'acknowledgeMainPasswordChange'])->name('relay.main-password-change.ack');
    });

Route::middleware(['auth.reset', 'auth:sanctum', 'school.access'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/licensing/status', [LicensingController::class, 'status'])->name('licensing.status');
    Route::get('/school/payment-settings', [SchoolPaymentSettingsController::class, 'show'])->name('school.payment-settings.show');
    Route::put('/school/payment-settings', [SchoolPaymentSettingsController::class, 'update'])->name('school.payment-settings.update');
    Route::post('/licensing/trial-extension-requests', [LicensingController::class, 'requestTrialExtension'])->middleware('throttle:3,60')->name('licensing.trial-extension-requests.store');
    Route::get('/billing/orders', [BillingController::class, 'index'])->name('billing.orders.index');
    Route::post('/billing/orders', [BillingController::class, 'store'])->middleware('throttle:10,1')->name('billing.orders.store');
    Route::post('/billing/orders/{order}/simulate-payment', [BillingController::class, 'simulatePayment'])->middleware('throttle:10,1')->name('billing.orders.simulate');
    Route::post('/admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
    Route::post('/admin/notifications/test', [NotificationTestController::class, 'send'])->name('admin.notifications.test');
    Route::post('/devices/fcm-token', [MobileDeviceController::class, 'store'])->name('devices.store');
    Route::delete('/devices/fcm-token', [MobileDeviceController::class, 'destroy'])->name('devices.destroy');
    Route::get('/parent/children', [ParentRelayController::class, 'children'])->middleware('throttle:60,1')->name('parent.children');
    Route::get('/parent/children/{student}', [ParentRelayController::class, 'showChild'])->middleware('throttle:60,1')->name('parent.children.show');
    Route::get('/parent/commands', [ParentRelayController::class, 'commands'])->middleware('throttle:60,1')->name('parent.commands');
    Route::post('/parent/commands', [ParentRelayController::class, 'submit'])->middleware('throttle:30,1')->name('parent.commands.store');
});
