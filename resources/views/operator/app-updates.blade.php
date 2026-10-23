<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>App updates · School ERP Control Center</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('operator.theme')
</head>
<body class="admin-page">
@include('operator.sidebar')
<header class="top">
    <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('operator.schools.index') }}">Schools</a><span aria-hidden="true">/</span><strong aria-current="page">App updates</strong></nav>
    <form method="post" action="{{ route('operator.logout') }}">@csrf<button type="submit">Sign out</button></form>
</header>
<main class="wrap payments-page">
    <div class="page-heading"><div><div class="small eyebrow">Release controls</div><h1>App updates</h1><p class="muted">Publish a version and download link to prompt installed apps the next time they connect. The minimum version makes the update mandatory.</p></div></div>
    @if (session('status'))<div class="status" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="notice warn" role="alert">{{ $errors->first() }}</div>@endif
    <div class="payments-grid">
        @foreach ($policies as $policy)
            <section class="panel payment-config-card">
                <div class="payment-card-heading"><span class="pill">{{ strtoupper($policy->platform) }}</span><span class="pill {{ $policy->enabled ? '' : 'warn' }}">{{ $policy->enabled ? 'Prompt enabled' : 'Off' }}</span></div>
                <div class="small eyebrow">{{ ucfirst($policy->app) }} app</div>
                <h2>{{ ucfirst($policy->app) }} · {{ ucfirst($policy->platform) }}</h2>
                <form method="post" action="{{ route('operator.app-updates.update', $policy->id) }}">
                    @csrf
                    <label>Latest version<input name="latest_version" required maxlength="32" value="{{ $policy->latest_version }}" placeholder="1.2.0"></label>
                    <label>Minimum allowed version<input name="minimum_version" required maxlength="32" value="{{ $policy->minimum_version }}" placeholder="1.0.0"></label>
                    <label>Secure download link<input name="download_url" type="url" value="{{ $policy->download_url }}" maxlength="2048" placeholder="https://…" @if ($policy->enabled) required @endif></label>
                    <label>Release notes<textarea name="release_notes" rows="3" maxlength="5000">{{ $policy->release_notes }}</textarea></label>
                    <label><input type="checkbox" name="enabled" value="1" @checked($policy->enabled)> Send update prompts</label>
                    <div class="field-help">Users below the minimum version are told they must update. All others see an optional update prompt. Only HTTPS links are accepted.</div>
                    <button class="submit" type="submit">Save release policy</button>
                </form>
            </section>
        @endforeach
    </div>
</main>
</body>
</html>
