<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Parent support · School ERP Control Center</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('operator.theme')
</head>
<body class="admin-page">
@include('operator.sidebar')
<header class="top">
    <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('operator.schools.index') }}">Schools</a><span aria-hidden="true">/</span><strong aria-current="page">Parent support</strong></nav>
    <form method="post" action="{{ route('operator.logout') }}">@csrf<button type="submit">Sign out</button></form>
</header>
<main class="wrap payments-page">
    <div class="page-heading"><div><div class="small eyebrow">Help and escalation</div><h1>Parent support</h1><p class="muted">Triage a request, route it to the school or platform team, and leave a response parents can see in the app.</p></div></div>
    @if (session('status'))<div class="status" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="notice warn" role="alert">{{ $errors->first() }}</div>@endif
    @if ($tickets->isEmpty())
        <section class="panel"><h2>No parent support requests yet</h2><p class="muted">New requests from signed-in parents will appear here.</p></section>
    @else
        <div class="payments-grid">
            @foreach ($tickets as $ticket)
                <section class="panel payment-config-card">
                    <div class="payment-card-heading"><span class="pill">{{ $ticket->reference }}</span>@if (!$ticket->user_id)<span class="pill warn">Pre-login · verify identity</span>@endif<span class="pill {{ in_array($ticket->status, ['resolved', 'closed'], true) ? '' : 'warn' }}">{{ str_replace('_', ' ', ucfirst($ticket->status)) }}</span></div>
                    <h2>{{ $ticket->subject }}</h2>
                    <p class="muted">{{ ucfirst(str_replace('_', ' ', $ticket->category)) }} · {{ $ticket->school_name ?: ($ticket->requester_school ?: 'School not identified') }} · {{ $ticket->created_at }}</p>
                    <p><strong>{{ $ticket->parent_name ?: ($ticket->requester_name ?: 'Parent / guardian') }}</strong> · {{ $ticket->parent_email ?: $ticket->requester_email }}@if ($ticket->parent_phone ?: $ticket->requester_phone) · {{ $ticket->parent_phone ?: $ticket->requester_phone }}@endif</p>
                    @if ($ticket->student_public_id)<p class="small">Linked student ID: {{ $ticket->student_public_id }}</p>@endif
                    <div class="panel"><p style="white-space:pre-wrap">{{ $ticket->message }}</p></div>
                    @if ($ticket->response_note)<div class="notice"><strong>Last response</strong><p style="white-space:pre-wrap">{{ $ticket->response_note }}</p></div>@endif
                    <p class="small">Assigned to: {{ $ticket->assignee_name ?: 'Unassigned' }} · Updated {{ $ticket->updated_at }}</p>
                    <form method="post" action="{{ route('operator.support.update', $ticket->id) }}">
                        @csrf
                        <label>Status<select name="status" required>@foreach (['open', 'in_progress', 'waiting_on_parent', 'escalated', 'resolved', 'closed'] as $status)<option value="{{ $status }}" @selected($ticket->status === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>@endforeach</select></label>
                        <label>Route to<select name="escalation_target" required><option value="platform" @selected($ticket->escalation_target === 'platform')>Platform support team</option><option value="school" @selected($ticket->escalation_target === 'school')>School office</option></select></label>
                        <label>Response or handoff note<textarea name="response_note" rows="3" maxlength="3000">{{ $ticket->response_note }}</textarea></label>
                        <div class="field-help">The parent can see this response and the status after refreshing their app. Do not include private notes intended only for staff.</div>
                        <button class="submit" type="submit">Save ticket update</button>
                    </form>
                </section>
            @endforeach
        </div>
    @endif
</main>
</body>
</html>
