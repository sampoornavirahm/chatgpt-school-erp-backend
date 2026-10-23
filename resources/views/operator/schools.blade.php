<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schools · School ERP Control Center</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('operator.theme')
</head>
<body class="admin-page">
@include('operator.sidebar')
<header class="top">
    <nav class="breadcrumbs" aria-label="Breadcrumb"><span>Control center</span><span aria-hidden="true">/</span><strong aria-current="page">Schools</strong></nav>
    <form method="post" action="{{ route('operator.logout') }}">@csrf<button type="submit">Sign out</button></form>
</header>

<main class="wrap">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="small mb-2 text-xs font-bold uppercase tracking-widest">Platform administration</div>
            <h1>Schools</h1>
            <div class="muted">Manage hosted school tenants, relay health, and service access.</div>
        </div>
        <div class="page-actions flex items-center justify-end gap-3">
            <div class="pill">{{ $schools->count() }} {{ \Illuminate\Support\Str::plural('school', $schools->count()) }}</div>
            @if (config('school_erp.trial_enabled'))
                <button class="button primary" type="button" id="open-school-dialog">＋ Add school</button>
            @endif
        </div>
    </div>

    @if (session('status'))<div class="status" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="notice warn" role="alert">{{ $errors->first() }}</div>@endif

    <section class="panel">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div><h2 style="margin-bottom:3px">Hosted tenants</h2><div class="muted">Current relay, licensing, and storage overview.</div></div>
        </div>
        <div class="table-wrap">
            <table class="w-full text-sm">
                <thead><tr><th>School</th><th>Relay</th><th>License</th><th>Storage</th><th>Activity</th><th></th></tr></thead>
                <tbody>
                @forelse ($schools as $school)
                    <tr>
                        <td><strong>{{ $school->name }}</strong><br><span class="small">{{ $school->id }}</span></td>
                        <td><span class="pill {{ $school->relay_status === 'paused' ? 'warn' : '' }}">{{ ucfirst($school->relay_status) }}</span></td>
                        <td><span class="pill {{ in_array($school->license_status, ['expired', 'suspended'], true) ? 'warn' : '' }}">{{ ucfirst($school->license_status) }}</span></td>
                        <td>{{ number_format($school->storage_used_bytes / 1048576, 2) }} MB <span class="muted">/ {{ number_format($school->storage_quota_bytes / 1073741824, 1) }} GB</span></td>
                        <td>{{ number_format($school->snapshot_count) }} snapshots<br><span class="small">{{ $school->users_count }} accounts · {{ $school->queued_commands }} queued requests</span></td>
                        <td><a class="button primary" href="{{ route('operator.schools.show', $school) }}">Manage</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:28px;text-align:center"><strong>No schools yet</strong><br><span class="muted">New school tenants will appear here after setup.</span></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
@if (config('school_erp.trial_enabled'))
    <dialog class="school-dialog" id="add-school-dialog" aria-labelledby="add-school-title">
        <div class="dialog-head flex items-start justify-between gap-4">
            <div><div class="small mb-1 text-xs font-bold uppercase tracking-widest">Tenant provisioning</div><h2 id="add-school-title">Add a trial school</h2><p class="muted">Create the school account and its first administrator.</p></div>
            <button class="dialog-close" type="button" data-close-school-dialog aria-label="Close dialog">×</button>
        </div>
        @if ($errors->any())<div class="notice warn" role="alert">{{ $errors->first() }}</div>@endif
        <form method="post" action="{{ route('operator.schools.store') }}">
            @csrf
            <div class="dialog-fields grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                <label>School name<input name="school_name" minlength="2" maxlength="160" value="{{ old('school_name') }}" required></label>
                <label>Administrator name<input name="admin_name" minlength="2" maxlength="120" value="{{ old('admin_name') }}" required></label>
                <label>Administrator email<input name="admin_email" type="email" value="{{ old('admin_email') }}" required></label>
                <label>Initial password <span class="small">12 characters minimum</span><input name="admin_password" type="password" minlength="12" required></label>
                <label class="dialog-wide">Hub server ID <span class="small">Optional</span><input name="server_id" type="text" inputmode="text" value="{{ old('server_id') }}" placeholder="Copy from the desktop License panel"></label>
            </div>
            <div class="dialog-actions flex items-center justify-end gap-2"><button class="button dialog-cancel" type="button" data-close-school-dialog>Cancel</button><button class="submit" type="submit">Create school tenant</button></div>
        </form>
    </dialog>
    <script>
        const schoolDialog = document.getElementById('add-school-dialog');
        document.getElementById('open-school-dialog')?.addEventListener('click', () => schoolDialog.showModal());
        document.querySelectorAll('[data-close-school-dialog]').forEach((button) => button.addEventListener('click', () => schoolDialog.close()));
        @if ($errors->any())schoolDialog.showModal();@endif
    </script>
@endif
</body>
</html>
