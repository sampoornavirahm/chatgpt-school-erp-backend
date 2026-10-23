<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payments · School ERP Control Center</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('operator.theme')
</head>
<body class="admin-page">
@include('operator.sidebar')
<header class="top">
    <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('operator.schools.index') }}">Schools</a><span aria-hidden="true">/</span><strong aria-current="page">Payments</strong></nav>
    <form method="post" action="{{ route('operator.logout') }}">@csrf<button type="submit">Sign out</button></form>
</header>
<main class="wrap payments-page">
    <div class="page-heading">
        <div><div class="small eyebrow">Checkout configuration</div><h1>Payments</h1><p class="muted">Set the merchant details used in subscription payment instructions.</p></div>
        <span class="pill">Current mode · {{ strtoupper($paymentDriver) }}</span>
    </div>

    @if (session('status'))<div class="status" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="notice warn" role="alert">{{ $errors->first() }}</div>@endif

    <div class="payments-grid">
        <section class="panel payment-config-card">
            <div class="payment-card-heading"><span class="payment-symbol">₹</span><span class="pill">Available</span></div>
            <div class="small eyebrow">Manual UPI</div>
            <h2>Merchant VPA</h2>
            <p class="muted">New subscription orders can show a UPI QR code and payment link. Payments still need to be verified and recorded by an operator.</p>
            <form method="post" action="{{ route('operator.payments.update') }}">
                @csrf
                <label for="merchant-vpa">UPI ID (VPA)</label>
                <input id="merchant-vpa" name="merchant_vpa" type="text" maxlength="255" value="{{ old('merchant_vpa', $merchantVpa) }}" placeholder="schoolerp@bank" autocomplete="off" required>
                <div class="field-help">Example: merchant@bank. This address is displayed to schools when they pay.</div>
                <label for="merchant-name">Display name</label>
                <input id="merchant-name" name="merchant_name" type="text" maxlength="120" value="{{ old('merchant_name', $merchantName) }}" required>
                <button class="submit" type="submit">Save UPI details</button>
            </form>
        </section>

        <section class="panel gateway-card">
            <div class="payment-card-heading"><span class="gateway-mark razorpay-mark">R</span><span class="pill warn">Not connected</span></div>
            <div class="small eyebrow">Online gateway</div>
            <h2>Razorpay</h2>
            <p class="muted">Gateway checkout, signature verification, and payment webhooks are not installed in this backend yet.</p>
            <button class="gateway-action" type="button" disabled>Integration required</button>
        </section>

        <section class="panel gateway-card">
            <div class="payment-card-heading"><span class="gateway-mark payu-mark">P</span><span class="pill warn">Not connected</span></div>
            <div class="small eyebrow">Online gateway</div>
            <h2>PayU</h2>
            <p class="muted">Hosted checkout, response hash validation, and payment callbacks are not installed in this backend yet.</p>
            <button class="gateway-action" type="button" disabled>Integration required</button>
        </section>
    </div>
    <div class="notice payment-note"><strong>Payment safety:</strong> gateway credentials should only be added after the matching checkout and server-side verification flow is implemented. Saving keys before then would not enable payments securely.</div>
</main>
</body>
</html>
