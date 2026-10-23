<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Connect School ERP</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f1f5fb;color:#17233b;font:16px system-ui,sans-serif}.card{width:min(100% - 32px,520px);padding:32px;background:white;border:1px solid #dce4ef;border-radius:18px;box-shadow:0 20px 60px #18345b17}h1{margin:0 0 8px;font-size:26px}.muted{color:#64748b;line-height:1.55}.code{margin:20px 0;padding:12px;border-radius:10px;background:#f5f7fb;font-family:ui-monospace,monospace;overflow-wrap:anywhere}.status{margin-top:18px;padding:12px;border-radius:10px;background:#edf4ff;color:#234b86}.status.error{background:#fff0ee;color:#922e25}.google{min-height:44px;margin-top:12px}button{font:inherit}#message{min-height:24px}
    </style>
</head>
<body>
<main class="card">
    <h1>Connect this school to School ERP</h1>
    <p class="muted">Sign in with the Google account registered as this school's administrator. The operator will review and approve the installation before its license is activated.</p>
    <div class="code" id="code"></div>
    <div id="googleButton" class="google"></div>
    <div id="message" class="status" role="status">Preparing secure Google sign-in…</div>
</main>
<script>
    (() => {
        const params = new URLSearchParams(location.hash.slice(1));
        const activationCode = params.get('code') || '';
        const serverId = params.get('server_id') || '';
        const message = document.getElementById('message');
        const show = (text, error = false) => { message.textContent = text; message.classList.toggle('error', error); };
        document.getElementById('code').textContent = activationCode ? `Activation code: ${activationCode}` : 'No activation code was provided.';
        if (!activationCode || !/^[0-9a-f-]{36}$/i.test(serverId)) {
            show('Return to the School ERP app and start again with the code provided by the operator.', true);
            return;
        }
        fetch('/api/v1/licensing/enrollment-config', { headers: { Accept: 'application/json' } })
            .then(async response => {
                const config = await response.json();
                if (!response.ok || !config.google_client_id) throw new Error(config.message || 'Google sign-in is not configured on this service.');
                const script = document.createElement('script');
                script.src = 'https://accounts.google.com/gsi/client';
                script.async = true;
                script.onload = () => {
                    google.accounts.id.initialize({
                        client_id: config.google_client_id,
                        callback: async ({ credential }) => {
                            show('Checking your Google account and sending the request to the operator…');
                            try {
                                const result = await fetch('/api/v1/licensing/enroll', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                                    body: JSON.stringify({ activation_code: activationCode, server_id: serverId, google_id_token: credential })
                                });
                                const body = await result.json();
                                if (!result.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] || 'Could not verify this school code and Google account.');
                                show(result.status === 202 ? 'Request sent. Leave School ERP open; this app will show when the operator approves it.' : 'This school is approved. Return to School ERP to finish activation.');
                            } catch (error) { show(error instanceof Error ? error.message : 'Sign-in could not be completed.', true); }
                        }
                    });
                    google.accounts.id.renderButton(document.getElementById('googleButton'), { theme: 'outline', size: 'large', text: 'signin_with' });
                    show('Choose the school administrator’s Google account to continue.');
                };
                script.onerror = () => show('Google sign-in could not load. Check the internet connection and try again.', true);
                document.head.appendChild(script);
            })
            .catch(error => show(error instanceof Error ? error.message : 'Could not prepare sign-in.', true));
    })();
</script>
</body>
</html>
