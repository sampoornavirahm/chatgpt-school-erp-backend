<?php

use App\Http\Controllers\Operator\AppUpdateController as OperatorAppUpdateController;
use App\Http\Controllers\Operator\AuthController as OperatorAuthController;
use App\Http\Controllers\Operator\LicensePlanController as OperatorLicensePlanController;
use App\Http\Controllers\Operator\PaymentSettingsController as OperatorPaymentSettingsController;
use App\Http\Controllers\Operator\ParentSupportController as OperatorParentSupportController;
use App\Http\Controllers\Operator\SchoolController as OperatorSchoolController;
use App\Http\Controllers\Operator\SchoolLicenseController as OperatorSchoolLicenseController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', fn () => redirect()->route('operator.login'))->name('login');
Route::view('/school/connect', 'school.connect')->name('school.connect');

Route::prefix('/operator')->name('operator.')->group(function (): void {
    Route::get('/bootstrap', [OperatorAuthController::class, 'bootstrapForm'])->name('bootstrap');
    Route::post('/bootstrap', [OperatorAuthController::class, 'bootstrap'])->middleware('throttle:5,1')->name('bootstrap.store');
    Route::get('/login', [OperatorAuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [OperatorAuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
    Route::middleware(['auth', 'platform.admin'])->group(function (): void {
        Route::post('/logout', [OperatorAuthController::class, 'logout'])->name('logout');
        Route::get('/schools', [OperatorSchoolController::class, 'index'])->name('schools.index');
        Route::get('/plans', [OperatorLicensePlanController::class, 'index'])->name('plans.index');
        Route::post('/plans/{licensePlan}', [OperatorLicensePlanController::class, 'update'])->name('plans.update');
        Route::get('/payments', [OperatorPaymentSettingsController::class, 'index'])->name('payments.index');
        Route::get('/support', [OperatorParentSupportController::class, 'index'])->name('support.index');
        Route::post('/support/{ticket}', [OperatorParentSupportController::class, 'update'])->whereNumber('ticket')->name('support.update');
        Route::get('/app-updates', [OperatorAppUpdateController::class, 'index'])->name('app-updates.index');
        Route::post('/app-updates/{policy}', [OperatorAppUpdateController::class, 'update'])->name('app-updates.update');
        Route::post('/payments', [OperatorPaymentSettingsController::class, 'update'])->name('payments.update');
        Route::post('/schools', [OperatorSchoolController::class, 'create'])->middleware('throttle:10,1')->name('schools.store');
        Route::get('/schools/{school}', [OperatorSchoolController::class, 'show'])->name('schools.show');
        Route::post('/schools/{school}/trial-extension', [OperatorSchoolLicenseController::class, 'extendTrial'])->name('schools.trial-extension');
        Route::post('/schools/{school}/service-access', [OperatorSchoolController::class, 'updateServiceAccess'])->name('schools.service-access');
        Route::post('/schools/{school}/archive-access', [OperatorSchoolController::class, 'updateArchiveAccess'])->name('schools.archive-access');
        Route::post('/schools/{school}/orders/{order}/reconcile-payment', [OperatorSchoolLicenseController::class, 'reconcilePayment'])->name('schools.orders.reconcile-payment');
        Route::post('/schools/{school}/activation-codes', [OperatorSchoolLicenseController::class, 'createActivationCode'])->middleware('throttle:10,1')->name('schools.activation-codes.store');
        Route::post('/schools/{school}/activation-codes/{activationCode}/approve', [OperatorSchoolLicenseController::class, 'approveActivation'])->middleware('throttle:10,1')->name('schools.activation-codes.approve');
        Route::post('/schools/{school}/main-user-password', [OperatorSchoolController::class, 'changeMainUserPassword'])->middleware('throttle:5,1')->name('schools.main-user-password');
        Route::post('/schools/{school}/licenses/{license}/revoke', [OperatorSchoolLicenseController::class, 'revoke'])->name('schools.licenses.revoke');
        Route::post('/schools/{school}/elite-contracts', [OperatorSchoolLicenseController::class, 'createEliteContract'])->middleware('throttle:10,1')->name('schools.elite-contracts.store');
        Route::post('/schools/{school}/relay-key', [OperatorSchoolController::class, 'rotateRelayKey'])->name('schools.relay-key');
        Route::post('/schools/{school}/parent-links', [OperatorSchoolController::class, 'linkParent'])->name('schools.parent-links.store');
        Route::delete('/schools/{school}/parent-links/{link}', [OperatorSchoolController::class, 'unlinkParent'])->name('schools.parent-links.destroy');
        Route::post('/schools/{school}/controls', [OperatorSchoolController::class, 'updateControls'])->name('schools.controls');
    });
});
