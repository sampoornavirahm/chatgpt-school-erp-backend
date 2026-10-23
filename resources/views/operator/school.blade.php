<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $school->name }} · School ERP operator</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body{font:14px system-ui;background:#f3f6fa;color:#17233b;margin:0}.top{background:#102447;color:#fff;padding:14px 5vw;display:flex;justify-content:space-between;align-items:center;gap:16px}.top a{color:#fff;text-decoration:none}.top form{margin:0}.top button,.button{padding:8px 12px;border:0;border-radius:7px;background:#fff;color:#183961;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.wrap{max-width:1180px;margin:28px auto;padding:0 22px}h1{font-size:24px;margin:0 0 5px}h2{font-size:18px;margin:0 0 12px}.muted{color:#64748b}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:20px 0}.panel{background:#fff;border:1px solid #dce3ec;border-radius:13px;padding:18px;margin:14px 0}.metric{font-size:24px;font-weight:750}.cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px}label{display:block;font-weight:600;margin:11px 0 5px}input,select,textarea{width:100%;box-sizing:border-box;padding:9px;border:1px solid #cbd5e1;border-radius:7px;font:inherit}button.submit{background:#1558bd;color:white;border:0;border-radius:7px;padding:9px 13px;font:inherit;font-weight:650;cursor:pointer}.danger{background:#a82337!important;color:#fff!important}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}td,th{text-align:left;border-bottom:1px solid #e5eaf0;padding:8px 6px;vertical-align:top}.notice{background:#eaf4ff;border:1px solid #c7e0ff;padding:12px;border-radius:9px;overflow-wrap:anywhere;margin:14px 0}.notice.warn{background:#fff7e7;border-color:#f2dfad}.inline{display:inline}.inline button{padding:5px 8px}.small{font-size:12px;color:#64748b}.qr{display:block;margin-top:8px}.qr canvas{background:#fff;border:1px solid #e5eaf0;padding:6px;border-radius:8px}.pill{display:inline-block;border-radius:20px;padding:3px 9px;background:#e8efff;color:#27549a;font-size:12px}.pill.warn{background:#fff1e7;color:#a44d0b}.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
    </style>
    @include('operator.theme')
</head>
<body class="admin-page">
@include('operator.sidebar')
<header class="top">
    <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="{{ route('operator.schools.index') }}">Schools</a><span aria-hidden="true">/</span><strong aria-current="page">{{ $school->name }}</strong></nav>
    <form method="post" action="{{ route('operator.logout') }}">@csrf<button type="submit">Sign out</button></form>
</header>
<main class="wrap">
    <h1>{{ $school->name }}</h1>
    <div class="muted">School ID {{ $school->id }} · created {{ $school->created_at }}</div>
    @if (session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="notice warn" role="alert">{{ $errors->first() }}</div>@endif
    @if (session('relay_credentials'))<section class="notice"><strong>Copy these relay credentials now.</strong> The shared secret is shown once and cannot be retrieved later.<pre>{{ json_encode(session('relay_credentials'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></section>@endif
    @if (session('activation_code'))<section class="notice"><strong>One-time activation code — copy it now.</strong><pre>{{ session('activation_code') }}</pre><span class="small">Only a hash is stored on the server. This code is bound to this school and its server ID.</span></section>@endif
    @if ($errors->has('main_user_password'))<div class="notice warn" role="alert">{{ $errors->first('main_user_password') }}</div>@endif

    <section class="grid">
        <div class="panel"><div class="muted">Stored projection data</div><div class="metric">{{ number_format($school->storage_used_bytes / 1048576, 2) }} MB</div><div>of {{ number_format($school->storage_quota_bytes / 1073741824, 1) }} GB</div></div>
        <div class="panel"><div class="muted">Student projections</div><div class="metric">{{ number_format($school->snapshot_count) }}</div></div>
        <div class="panel"><div class="muted">Linked parent accounts</div><div class="metric">{{ number_format($school->linked_parents) }}</div></div>
        <div class="panel"><div class="muted">Queued parent requests</div><div class="metric">{{ number_format($school->queued_commands) }}</div></div>
    </section>

    <section class="panel">
        <h2>Trial and license</h2>
        <p>Plan: <strong>{{ $school->license_plan_key }}</strong> · Status: <span class="pill {{ in_array($school->license_status, ['expired', 'suspended'], true) ? 'warn' : '' }}">{{ $school->license_status }}</span></p>
        @if ($school->trial_started_at)<p>Trial started {{ $school->trial_started_at->format('Y-m-d') }} · ends {{ $school->trial_expires_at?->format('Y-m-d') ?? 'not set' }} · extension {{ $school->trial_extended_at ? 'used' : 'available' }}</p>@endif
        <p class="small">A school can request one 15-day extension. Operator approval extends the initial trial to a maximum of 30 days. License changes reach connected installations at their next licensing check.</p>
        @if ($school->license_status === 'trial' && !$school->trial_extended_at)
            <form method="post" action="{{ route('operator.schools.trial-extension', $school) }}">@csrf<button class="submit" type="submit">Approve extension to 30 days</button></form>
        @endif
        @if ($trialExtensionRequests->isNotEmpty())<h3>Trial extension requests</h3><div class="table-wrap"><table><thead><tr><th>Requested</th><th>By</th><th>Request</th><th>Status</th><th>Action</th></tr></thead><tbody>@foreach ($trialExtensionRequests as $extensionRequest)<tr><td>{{ $extensionRequest->requested_at }}</td><td>{{ $extensionRequest->requester_name }}</td><td>{{ $extensionRequest->school_note ?: '15-day extension requested' }}</td><td>{{ $extensionRequest->status }}</td><td>@if ($extensionRequest->status === 'pending' && !$school->trial_extended_at)<form method="post" action="{{ route('operator.schools.trial-extension', $school) }}">@csrf<button class="submit" type="submit">Approve</button></form>@endif</td></tr>@endforeach</tbody></table></div>@endif

        <div class="cols">
            <section>
                <h3>Issue a one-time activation code</h3>
                <form method="post" action="{{ route('operator.schools.activation-codes.store', $school) }}">@csrf
                    <label>School plan</label><select name="plan_key" required>@foreach ($plans->whereNotIn('key', ['professional_distributed', 'elite']) as $plan)<option value="{{ $plan->key }}">{{ $plan->name }}</option>@endforeach</select>
                    <div class="form-grid"><div><label>Student seats</label><input type="number" name="student_seats" min="1" max="200000" placeholder="Plan default"></div><div><label>Teacher seats</label><input type="number" name="teacher_seats" min="1" max="10000" placeholder="Plan default"></div></div>
                    <label>Custom feature flags</label><input name="feature_flags" placeholder="Optional; comma separated">
                    <label>License end date</label><input type="date" name="license_expires_at"><div class="small">Annual plans expire one year after activation. Elite is issued only after its installment agreement is paid.</div>
                    <button class="submit" type="submit">Create activation code</button>
                </form>
            </section>
            <section>
                <h3>Issued licenses</h3>
                <div class="table-wrap"><table><thead><tr><th>Plan</th><th>Status</th><th>Issued</th><th>Expiry</th><th>Action</th></tr></thead><tbody>
                    @forelse ($licenses as $license)<tr><td>{{ $license->plan_key }}<br><span class="small">{{ $license->id }}</span></td><td>{{ $license->status }}</td><td>{{ $license->issued_at->format('Y-m-d H:i') }}</td><td>{{ $license->valid_until?->format('Y-m-d') ?? 'No expiry' }}</td><td>@if ($license->status === 'active')<form method="post" action="{{ route('operator.schools.licenses.revoke', [$school, $license]) }}" onsubmit="return confirm('Revoke this license? Existing school records will remain available.');">@csrf<button class="submit danger" type="submit">Revoke</button></form>@endif</td></tr>@empty<tr><td colspan="5">No signed license has been issued.</td></tr>@endforelse
                </tbody></table></div>
            </section>
        </div>
    </section>

    <section class="panel">
        <h2>School activation requests</h2>
        <p class="small">A school code only requests access. The license is issued after you approve the Google account and installation shown here.</p>
        <div class="table-wrap"><table><thead><tr><th>Google account</th><th>Requested</th><th>Plan</th><th>Status</th><th>Installation</th><th>Action</th></tr></thead><tbody>
            @forelse ($activationRequests as $activationRequest)
                <tr>
                    <td>{{ $activationRequest->requested_google_email }}</td>
                    <td>{{ $activationRequest->enrollment_requested_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $activationRequest->plan_key }}</td>
                    <td>{{ $activationRequest->consumed_at ? 'Approved' : 'Pending approval' }}</td>
                    <td><code>{{ $activationRequest->requested_server_id }}</code></td>
                    <td>
                        @if (!$activationRequest->consumed_at)
                            <form method="post" action="{{ route('operator.schools.activation-codes.approve', [$school, $activationRequest]) }}" onsubmit="return confirm('Approve this school Google account and issue its license?');">
                                @csrf
                                <button class="submit" type="submit">Approve and activate</button>
                            </form>
                        @else
                            <span class="small">License issued {{ $activationRequest->approved_at?->format('Y-m-d H:i') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No school activation requests yet.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>

    <section class="panel">
        <h2>Main school account password</h2>
        <p class="small">Set a new password for the school’s primary desktop administrator. The app applies it the next time it connects to the secure relay. The change expires after 7 days if that computer stays offline. Share the new password with the administrator through a secure channel.</p>
        <form method="post" action="{{ route('operator.schools.main-user-password', $school) }}" onsubmit="return confirm('Queue this password change for the school’s primary desktop administrator?');">
            @csrf
            <div class="form-grid"><div><label>New password</label><input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" required></div><div><label>Confirm new password</label><input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password" required></div></div>
            <button class="submit" type="submit">Queue main account password change</button>
        </form>
        @if ($mainPasswordChange)<p class="small">Most recent change: <strong>{{ ucfirst($mainPasswordChange->status) }}</strong>@if ($mainPasswordChange->status === 'pending') · expires {{ $mainPasswordChange->expires_at }}@elseif ($mainPasswordChange->applied_at) · applied {{ $mainPasswordChange->applied_at }}@endif @if ($mainPasswordChange->result_message) · {{ $mainPasswordChange->result_message }}@endif</p>@endif
    </section>

    <div class="cols">
        <section class="panel"><h2>School service access</h2><p>Current state: <strong>{{ $school->service_on_hold ? 'On hold' : 'Available' }}</strong>. On hold, sign-in and renewal remain available while school services pause.</p><form method="post" action="{{ route('operator.schools.service-access', $school) }}">@csrf
            <label>Service access</label><select name="service_access"><option value="active" @selected(!$school->service_on_hold)>Available</option><option value="on_hold" @selected($school->service_on_hold)>On hold</option></select>
            <label>Support note (audit only)</label><textarea name="support_note" maxlength="500"></textarea><button class="submit" type="submit">Save service access</button>
        </form></section>
        <section class="panel"><h2>Relay access</h2><p>Relay: <strong>{{ $school->relay_status }}</strong> · Credential: <strong>{{ $credential && $credential->is_active ? 'configured' : 'not configured' }}</strong></p>@if ($credential)<p class="small">Key ID {{ $credential->key_id }} · last used {{ $credential->last_used_at ?? 'never' }}</p>@endif<form method="post" action="{{ route('operator.schools.relay-key', $school) }}">@csrf<button class="submit">{{ $credential ? 'Rotate relay secret' : 'Create relay secret' }}</button></form></section>
        <section class="panel"><h2>Relay and storage controls</h2><form method="post" action="{{ route('operator.schools.controls', $school) }}">@csrf
            <label>Relay status</label><select name="relay_status"><option value="active" @selected($school->relay_status === 'active')>Active</option><option value="paused" @selected($school->relay_status === 'paused')>Paused</option></select>
            <label>Storage allowance (GB, maximum 50)</label><input type="number" name="storage_quota_gb" min="1" max="50" value="{{ (int) ($school->storage_quota_bytes / 1073741824) }}" required>
            <label>Support note (audit only)</label><textarea name="support_note" maxlength="500"></textarea><button class="submit" type="submit">Save controls</button>
        </form></section>
    </div>

    <section class="panel"><h2>Archive access window</h2><p class="small">Enable archive access for the timeframe agreed with the school. Offline payments can be recorded separately. Query execution and metering will be wired to the archived-session data store when its schema is in place.</p><form method="post" action="{{ route('operator.schools.archive-access', $school) }}">@csrf
        <label><input style="width:auto" type="checkbox" name="archive_access_enabled" value="1" @checked($school->archive_access_enabled)> Enable archive access</label>
        <div class="form-grid"><div><label>Starts</label><input type="date" name="archive_access_starts_at" value="{{ $school->archive_access_starts_at?->format('Y-m-d') }}"></div><div><label>Ends</label><input type="date" name="archive_access_ends_at" value="{{ $school->archive_access_ends_at?->format('Y-m-d') }}"></div><div><label>Included archive reads</label><input type="number" name="archive_free_reads_limit" min="0" max="1000000" value="{{ $school->archive_free_reads_limit }}" required></div><div><label>Charge per additional query (INR)</label><input name="archive_query_price" inputmode="decimal" value="{{ $school->archive_query_price_paise === null ? '' : number_format($school->archive_query_price_paise / 100, 2, '.', '') }}" placeholder="Set only when enabled"></div></div>
        <label>Agreement note (internal)</label><textarea name="archive_access_note" maxlength="500">{{ $school->archive_access_note }}</textarea><button class="submit" type="submit">Save archive access</button>
    </form></section>

    <section class="panel">
        <h2>Elite custom agreement · installment plan</h2>
        <p class="small">Enter the amount and date for each installment, separated by commas or line breaks. The license activates when all installments are paid. Its expiry and included features remain custom per agreement.</p>
        <form method="post" action="{{ route('operator.schools.elite-contracts.store', $school) }}">@csrf
            <label>Custom services on top of Basic</label><textarea name="services_summary" maxlength="1000" required placeholder="Describe the services included in this agreement"></textarea>
            <div class="form-grid"><div><label>Installment amounts (INR)</label><textarea name="installment_amounts" required placeholder="10000, 10000"></textarea></div><div><label>Installment due dates</label><textarea name="installment_due_dates" required placeholder="2026-11-01, 2026-12-01"></textarea></div></div>
            <div class="form-grid"><div><label>Student seats (optional)</label><input type="number" name="student_seats" min="1" max="200000"></div><div><label>Teacher seats (optional)</label><input type="number" name="teacher_seats" min="1" max="10000"></div><div><label>License expiry</label><input type="date" name="license_expires_at" required></div></div>
            <label>Custom feature flags</label><input name="feature_flags" placeholder="Comma separated, e.g. reports.export">
            <button class="submit" type="submit">Create Elite schedule</button>
        </form>
        @if ($contracts->isNotEmpty())<h3>Existing agreements</h3><div class="table-wrap"><table><thead><tr><th>Services</th><th>Total</th><th>Installments</th><th>Status</th><th>Expiry</th></tr></thead><tbody>@foreach ($contracts as $contract)<tr><td>{{ $contract->services_summary }}</td><td>₹{{ number_format($contract->total_amount_paise / 100, 2) }}</td><td>{{ $contract->installment_count }}</td><td>{{ $contract->status }}</td><td>{{ $contract->license_expires_at }}</td></tr>@endforeach</tbody></table></div>@endif
    </section>

    <section class="panel"><h2>Subscription payments</h2><p class="small">Most payments can be collected offline. Record the method and receipt or cheque reference here; the system activates only after an operator records the payment.</p><div class="table-wrap"><table><thead><tr><th>Plan</th><th>Amount</th><th>Status</th><th>Provider</th><th>Payment QR</th><th>Reconcile</th><th>Created</th></tr></thead><tbody>
        @forelse ($orders as $order)<tr><td>{{ $order->plan_key }}@if ($order->installment_number)<br><span class="small">Installment {{ $order->installment_number }} / {{ $order->installment_count }}</span>@endif</td><td>₹{{ number_format($order->amount_paise / 100, 2) }} {{ $order->currency }}</td><td>{{ $order->status }}</td><td>{{ $order->payment_provider }}@if ($order->reconciliation_method)<br><span class="small">{{ $order->reconciliation_method }} · {{ $order->reconciliation_reference ?: 'no reference' }} · {{ $order->reconciled_paid_at }}</span>@endif</td><td>@if ($order->payment_uri)<div class="qr"><canvas data-upi-uri="{{ $order->payment_uri }}" aria-label="UPI payment QR code"></canvas><a href="{{ $order->payment_uri }}">Open UPI payment</a></div>@else<span class="small">No online payment instruction configured</span>@endif</td><td>@if ($order->status === 'pending')<form method="post" action="{{ route('operator.schools.orders.reconcile-payment', [$school, $order->id]) }}">@csrf<label>Received by</label><select name="method" required><option value="cheque">Cheque</option><option value="bank_transfer">Bank transfer</option><option value="upi">UPI</option><option value="razorpay">Razorpay</option><option value="payu">PayU</option><option value="cash">Cash</option></select><label>Reference</label><input name="reference" maxlength="120"><label>Paid on</label><input type="datetime-local" name="paid_at" required><label>Note</label><input name="note" maxlength="500"><button class="submit" type="submit">Record payment</button></form>@endif</td><td>{{ $order->created_at }}</td></tr>@empty<tr><td colspan="7">No subscription orders yet.</td></tr>@endforelse
    </tbody></table></div></section>

    <div class="cols">
        <section class="panel"><h2>Explicit parent-to-student links</h2><p class="small">Only linked parents receive the selected student's snapshot. Links require a parent account and a student already projected by the Hub.</p><form method="post" action="{{ route('operator.schools.parent-links.store', $school) }}">@csrf
            <label>Parent account</label><select name="user_id" required>@foreach ($parents as $parent)<option value="{{ $parent->id }}">{{ $parent->name }} · {{ $parent->email }}</option>@endforeach</select>
            <label>Student</label><select name="student_public_id" required>@foreach ($students as $student)<option value="{{ $student['id'] }}">{{ $student['name'] }} · {{ $student['admissionNo'] }} · {{ $student['id'] }}</option>@endforeach</select>
            <button class="submit" type="submit">Link parent to student</button>
        </form><h3>Current links</h3><div class="table-wrap"><table><thead><tr><th>Parent</th><th>Student</th><th></th></tr></thead><tbody>@forelse ($links as $link)<tr><td>{{ $link['parentName'] }}<br><span class="small">{{ $link['email'] }}</span></td><td>{{ $link['studentName'] }}</td><td><form class="inline" method="post" action="{{ route('operator.schools.parent-links.destroy', [$school, $link['id']]) }}">@csrf @method('DELETE')<button class="submit danger">Unlink</button></form></td></tr>@empty<tr><td colspan="3">No parent access links yet.</td></tr>@endforelse</tbody></table></div></section>
        <section class="panel"><h2>Recent support and access audit</h2><div class="table-wrap"><table><thead><tr><th>Time</th><th>Action</th><th>Target</th></tr></thead><tbody>@forelse ($audit as $event)<tr><td>{{ $event->created_at }}</td><td>{{ $event->action }}</td><td>{{ $event->target_type }} {{ $event->target_id }}</td></tr>@empty<tr><td colspan="3">No operator actions recorded.</td></tr>@endforelse</tbody></table></div></section>
    </div>
</main>
</body>
</html>
