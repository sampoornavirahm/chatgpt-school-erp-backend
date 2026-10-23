<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>One-time operator setup · School ERP</title>@vite(['resources/css/app.css', 'resources/js/app.js'])<style>body{font:15px system-ui;background:#f3f6fa;color:#17233b;margin:0;min-height:100vh;display:grid;place-items:center}.card{background:#fff;width:min(460px,calc(100% - 40px));padding:30px;border:1px solid #dce3ec;border-radius:18px}h1{font-size:23px;margin:0}p{color:#64748b;line-height:1.5}label{display:block;margin:15px 0 6px;font-weight:600}input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:9px;font:inherit}button{margin-top:20px;width:100%;padding:12px;border:0;border-radius:9px;background:#1256c3;color:white;font:inherit;font-weight:700}.error{color:#a92335}</style>
@include('operator.theme')
</head>
<body class="auth-page"><main class="card"><h1>Set up your operator account</h1><p>This creates the first platform operator. Setup is available only once while the bootstrap token is configured.</p>
    @if ($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('operator.bootstrap.store') }}">@csrf
        <label for="bootstrap_token">One-time bootstrap token</label><input id="bootstrap_token" name="bootstrap_token" type="password" autocomplete="off" required>
        <label for="name">Name</label><input id="name" name="name" value="{{ old('name') }}" required>
        <label for="email">Operator email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required>
        <label for="password">Password (16 characters minimum)</label><input id="password" name="password" type="password" minlength="16" autocomplete="new-password" required>
        <button type="submit">Create operator</button>
    </form>
</main></body></html>
