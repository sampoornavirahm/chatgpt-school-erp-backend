<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>License plans · School ERP Control Center</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('operator.theme')
</head>
<body class="admin-page">
@include('operator.sidebar')
<header class="top">
    <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('operator.schools.index') }}">Schools</a><span aria-hidden="true">/</span><strong aria-current="page">License plans</strong></nav>
    <form method="post" action="{{ route('operator.logout') }}">@csrf<button type="submit">Sign out</button></form>
</header>
<main class="wrap plans-page">
    <div class="page-heading flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="small eyebrow">Billing configuration</div>
            <h1>License plans</h1>
            <div class="muted">Review plan details at a glance; expand a plan to edit its settings.</div>
        </div>
        <span class="currency-tag">INR <span>·</span> prices shown in rupees</span>
    </div>

    @if (session('status'))<div class="status" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="notice warn" role="alert">{{ $errors->first() }}</div>@endif
    @if (!$merchantVpaConfigured)<div class="notice">Merchant UPI is not configured. Payment instructions remain disabled until a merchant VPA is supplied.</div>@endif

    <section class="plans-grid grid grid-cols-1 gap-4 2xl:grid-cols-2" aria-label="License plans">
        @foreach ($plans as $plan)
            <article class="plan-card rounded-2xl border bg-white p-4 shadow-sm sm:p-5">
                <div class="plan-card-head">
                    <div class="plan-name-block">
                        <span class="plan-key">{{ str_replace('_', ' ', $plan->key) }}</span>
                        <h2>{{ $plan->name }}</h2>
                    </div>
                    <span class="pill {{ $plan->is_enabled ? '' : 'warn' }}">{{ $plan->is_enabled ? 'Available' : 'Disabled' }}</span>
                </div>
                <p class="plan-description">{{ $plan->description ?: 'No description provided.' }}</p>
                <div class="plan-facts grid grid-cols-2 gap-2">
                    <div><span>Billing</span><strong>{{ ucfirst(str_replace('_', ' ', $plan->billing_period)) }}</strong></div>
                    <div><span>Mode</span><strong>{{ ucfirst(str_replace('_', ' ', $plan->billing_mode)) }}</strong></div>
                    <div><span>School price</span><strong>{{ $plan->school_price_paise === null ? '—' : '₹'.number_format($plan->school_price_paise / 100, 2) }}</strong></div>
                    <div><span>Per student / year</span><strong>{{ $plan->student_price_paise === null ? '—' : '₹'.number_format($plan->student_price_paise / 100, 2) }}</strong></div>
                </div>

                <details class="plan-editor">
                    <summary><span>Edit plan settings</span><span class="edit-chevron" aria-hidden="true">⌄</span></summary>
                    <form method="post" action="{{ route('operator.plans.update', $plan) }}">
                        @csrf
                    <div class="plan-fields grid grid-cols-1 gap-x-3 gap-y-1 sm:grid-cols-2">
                            <label class="field-wide">Plan name<input name="name" required maxlength="100" value="{{ $plan->name }}"></label>
                            <label class="field-wide">Description<textarea name="description" maxlength="500">{{ $plan->description }}</textarea></label>
                            <label>Billing period<select name="billing_period">@foreach (['free' => 'Free', 'annual' => 'Annual', 'custom' => 'Custom'] as $key => $label)<option value="{{ $key }}" @selected($plan->billing_period === $key)>{{ $label }}</option>@endforeach</select></label>
                            <label>Billing mode<select name="billing_mode">@foreach (['free' => 'Free', 'ad_supported' => 'Ad supported', 'upfront' => 'Upfront', 'installments' => 'Installments'] as $key => $label)<option value="{{ $key }}" @selected($plan->billing_mode === $key)>{{ $label }}</option>@endforeach</select></label>
                            <label>School price (INR)<input name="school_price_inr" type="number" step="0.01" min="0" value="{{ $plan->school_price_paise === null ? '' : number_format($plan->school_price_paise / 100, 2, '.', '') }}"></label>
                            <label>Per student / year (INR)<input name="student_price_inr" type="number" step="0.01" min="0" value="{{ $plan->student_price_paise === null ? '' : number_format($plan->student_price_paise / 100, 2, '.', '') }}"></label>
                            <label>Parent / year (INR)<input name="parent_price_inr" type="number" step="0.01" min="0" value="{{ $plan->parent_price_paise === null ? '' : number_format($plan->parent_price_paise / 100, 2, '.', '') }}"></label>
                            <label>Teacher / year (INR)<input name="teacher_price_inr" type="number" step="0.01" min="0" value="{{ $plan->teacher_price_paise === null ? '' : number_format($plan->teacher_price_paise / 100, 2, '.', '') }}"></label>
                            <label>Student limit<input name="student_limit" type="number" min="1" value="{{ $plan->student_limit }}" @if ($plan->key === 'basic_adware') placeholder="Inherits Starter: 100" @endif></label>
                            <label>Teacher limit<input name="teacher_limit" type="number" min="1" value="{{ $plan->teacher_limit }}" @if ($plan->key === 'basic_adware') placeholder="Inherits Starter: 1" @endif></label>
                            <label class="field-wide">Feature flags <span class="small">Comma or space separated</span><input name="feature_flags" maxlength="2000" value="{{ implode(', ', $plan->feature_flags ?? []) }}" placeholder="attendance.read, reports.export"></label>
                        </div>
                        <div class="plan-toggles">
                            <label><input type="hidden" name="ad_supported" value="0"><input type="checkbox" name="ad_supported" value="1" @checked($plan->ad_supported)> Ad-supported tier</label>
                            <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked($plan->is_enabled)> Available for activation and purchase</label>
                        </div>
                        <button class="save" type="submit">Save changes</button>
                    </form>
                </details>
            </article>
        @endforeach
    </section>
</main>
</body>
</html>
