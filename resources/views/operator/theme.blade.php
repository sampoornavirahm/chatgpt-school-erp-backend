<style>
    :root {
        color-scheme: light;
        --ink: #17243a;
        --muted: #68778d;
        --line: #e3e9f1;
        --surface: #ffffff;
        --canvas: #f4f7fb;
        --navy: #102440;
        --blue: #3568d4;
        --blue-dark: #2856bd;
        --blue-soft: #edf3ff;
        --green: #16805a;
        --green-soft: #e8f7f0;
        --amber: #a65c0a;
        --amber-soft: #fff4df;
        --red: #b33b4c;
        --red-soft: #fff0f1;
        --shadow: 0 12px 32px rgba(22, 39, 67, .055);
    }

    * { box-sizing: border-box; }
    body.admin-page {
        min-height: 100vh;
        background: var(--canvas);
        color: var(--ink);
        font: 14px/1.55 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        -webkit-font-smoothing: antialiased;
    }
    .admin-page .top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-height: 68px;
        position: sticky;
        z-index: 10;
        top: 0;
        gap: 20px;
        padding: 12px clamp(22px, 4vw, 58px);
        background: var(--navy);
        color: #fff;
        border-bottom: 1px solid rgba(255,255,255,.08);
        box-shadow: 0 5px 20px rgba(13, 30, 54, .1);
    }
    .admin-sidebar {
        position: fixed;
        z-index: 20;
        inset: 0 auto 0 0;
        display: flex;
        width: 252px;
        flex-direction: column;
        padding: 24px 16px 18px;
        background: #102440;
        color: #dbe6f5;
        box-shadow: 8px 0 30px rgba(12, 30, 55, .08);
    }
    .sidebar-brand { display: flex; align-items: center; gap: 12px; padding: 3px 8px 28px; color: #fff; text-decoration: none; }
    .brand-mark { display: grid; width: 40px; height: 40px; place-items: center; border: 1px solid rgba(255,255,255,.18); border-radius: 12px; background: linear-gradient(145deg, #5a88e4, #3161bf); color: #fff; font-size: 13px; font-weight: 800; letter-spacing: -.04em; box-shadow: 0 7px 16px rgba(5, 14, 29, .2); }
    .brand-copy { display: grid; gap: 2px; }
    .brand-copy strong { font-size: 15px; letter-spacing: -.025em; }
    .brand-copy small { color: #9db1ce; font-size: 9px; font-weight: 750; letter-spacing: .13em; }
    .sidebar-label { padding: 0 11px 9px; color: #8398b5; font-size: 10px; font-weight: 800; letter-spacing: .14em; }
    .admin-sidebar nav { display: grid; gap: 5px; }
    .admin-sidebar .sidebar-link { display: flex; min-height: 44px; align-items: center; gap: 12px; padding: 9px 11px; border: 1px solid transparent; border-radius: 10px; color: #bdcbe0; font-weight: 620; text-decoration: none; transition: background .15s ease, color .15s ease, border-color .15s ease; }
    .admin-sidebar .sidebar-link:hover { background: rgba(255,255,255,.07); color: #fff; }
    .admin-sidebar .sidebar-link.active { border-color: rgba(139, 174, 239, .12); background: #1c3558; color: #fff; box-shadow: inset 3px 0 #6f9df0; }
    .sidebar-icon { display: grid; width: 24px; height: 24px; place-items: center; color: #9eb6dc; font-size: 19px; line-height: 1; }
    .sidebar-link.active .sidebar-icon { color: #9fc0ff; }
    .sidebar-foot { display: flex; align-items: center; gap: 10px; margin-top: auto; padding: 15px 10px 3px; border-top: 1px solid rgba(255,255,255,.1); }
    .sidebar-foot span:last-child { display: grid; gap: 2px; }
    .sidebar-foot strong { color: #dce7f6; font-size: 12px; }
    .sidebar-foot small { color: #8ea3c0; font-size: 11px; }
    .sidebar-status-dot { width: 8px; height: 8px; border-radius: 50%; background: #4ec79b; box-shadow: 0 0 0 4px rgba(78,199,155,.12); }
    .admin-page .top strong { font-size: 15px; letter-spacing: -.02em; }
    .admin-page .top nav { display: flex; align-items: center; gap: 10px; }
    .admin-page .top .breadcrumbs { display: flex; min-width: 0; align-items: center; gap: 11px; color: #9db2d0; }
    .admin-page .top .breadcrumbs strong { overflow: hidden; color: #fff; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
    .admin-page .top a { color: #dbe7fa; text-decoration: none; font-weight: 600; }
    .admin-page .top a:hover { color: #fff; }
    .admin-page .top form { margin: 0; }
    .admin-page .top button, .admin-page .button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 38px;
        padding: 8px 13px;
        border: 1px solid rgba(255,255,255,.16);
        border-radius: 9px;
        background: rgba(255,255,255,.1);
        color: #fff;
        font: inherit;
        font-weight: 650;
        text-decoration: none;
        cursor: pointer;
        transition: background .16s ease, transform .16s ease;
    }
    .admin-page .top button:hover, .admin-page .button:hover { background: rgba(255,255,255,.18); transform: translateY(-1px); }
    .admin-page .top { margin-left: 252px; }
    .admin-page .wrap { width: calc(100% - 252px); max-width: none; margin: 0 0 0 252px; padding: 34px clamp(22px, 4vw, 58px) 64px; }
    .admin-page .page-actions { display: flex; align-items: center; gap: 12px; }
    .admin-page .school-dialog {
        width: min(680px, calc(100vw - 32px));
        max-width: 680px;
        max-height: min(88vh, 820px);
        padding: 26px;
        overflow: auto;
        border: 1px solid var(--line);
        border-radius: 18px;
        background: #fff;
        color: var(--ink);
        box-shadow: 0 28px 90px rgba(10, 24, 45, .24);
    }
    .admin-page .school-dialog::backdrop { background: rgba(11, 25, 45, .58); backdrop-filter: blur(3px); }
    .dialog-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 16px; }
    .dialog-head h2 { margin: 0 0 4px; font-size: 22px; }
    .dialog-head p { margin: 0; }
    .dialog-close { display: grid; width: 36px; height: 36px; flex: 0 0 auto; place-items: center; border: 1px solid var(--line); border-radius: 10px; background: #fff; color: #526178; font-size: 23px; line-height: 1; cursor: pointer; }
    .dialog-close:hover { background: #f2f5fa; color: #1b2d47; }
    .dialog-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 16px; }
    .dialog-wide { grid-column: 1 / -1; }
    .dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; padding-top: 15px; border-top: 1px solid #e9edf3; }
    .dialog-actions .submit { margin-top: 0!important; }
    .admin-page .dialog-cancel { border-color: var(--line); background: #fff; color: #41536d; }
    .admin-page .dialog-cancel:hover { background: #f5f7fa; }
    .plans-page .page-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 18px; flex-wrap: wrap; }
    .eyebrow { margin-bottom: 7px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .currency-tag { display: inline-flex; align-items: center; gap: 9px; padding: 8px 12px; border: 1px solid #dfe7f3; border-radius: 999px; background: #fff; color: #50627b; font-size: 12px; font-weight: 700; }
    .currency-tag span { color: #b0bccb; }
    .plans-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 420px), 1fr)); align-items: start; gap: 16px; margin-top: 22px; }
    .payments-page .page-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; }
    .payments-page .page-heading p { margin: 0; }
    .payments-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: stretch; gap: 16px; margin-top: 22px; }
    .payment-config-card, .gateway-card { margin: 0!important; }
    .payment-card-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 19px; }
    .payment-symbol, .gateway-mark { display: grid; width: 40px; height: 40px; place-items: center; border: 1px solid #dfe7f3; border-radius: 12px; background: #f4f7fd; color: #345da7; font-size: 19px; font-weight: 800; }
    .razorpay-mark { background: #edf3ff; color: #315ec8; }
    .payu-mark { background: #fff3e8; color: #c65d19; }
    .payments-page .eyebrow { margin-bottom: 5px; }
    .payments-page h2 { margin-bottom: 7px; }
    .payments-page .muted { min-height: 65px; }
    .payments-page .field-help { margin-top: 5px; color: #75839a; font-size: 12px; }
    .gateway-action { min-height: 39px; margin-top: 8px; padding: 8px 12px; border: 1px solid #dce3ec; border-radius: 9px; background: #f5f7fa; color: #8490a2; font: inherit; font-weight: 650; }
    .payment-note { margin-top: 18px!important; }
    .plan-card { min-width: 0; padding: 19px; border: 1px solid var(--line); border-radius: 15px; background: #fff; box-shadow: var(--shadow); }
    .plan-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
    .plan-key { display: block; margin-bottom: 4px; color: #718097; font-size: 10px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .plan-card h2 { margin: 0; font-size: 19px; }
    .plan-description { min-height: 22px; margin: 8px 0 14px; color: #69788d; font-size: 13px; }
    .plan-facts { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
    .plan-facts div { min-width: 0; padding: 11px 12px; border: 1px solid #e9edf4; border-radius: 10px; background: #f8faff; }
    .plan-facts span { display: block; margin-bottom: 3px; color: #728097; font-size: 11px; font-weight: 650; }
    .plan-facts strong { color: #20334e; font-size: 13px; }
    .plan-editor { margin-top: 14px; border-top: 1px solid #e8edf4; }
    .plan-editor summary { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 2px 3px; color: #355b9b; font-weight: 750; cursor: pointer; list-style: none; }
    .plan-editor summary::-webkit-details-marker { display: none; }
    .edit-chevron { color: #75869d; font-size: 19px; transition: transform .15s ease; }
    .plan-editor[open] .edit-chevron { transform: rotate(180deg); }
    .plan-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 13px; padding-top: 10px; }
    .field-wide { grid-column: 1 / -1; }
    .plan-toggles { display: grid; gap: 2px; margin-top: 9px; padding: 10px 12px; border: 1px solid #e9edf4; border-radius: 10px; background: #f8faff; }
    .plan-toggles label { display: flex; align-items: center; gap: 8px; margin: 5px 0; }
    .plan-toggles input[type="checkbox"] { width: 16px; height: 16px; margin: 0; }
    .admin-page h1 { color: #13233b; font-size: clamp(25px, 3vw, 32px); line-height: 1.2; letter-spacing: -.04em; margin: 0 0 7px; }
    .admin-page h2 { color: #1b2c45; font-size: 18px; line-height: 1.35; letter-spacing: -.02em; margin: 0 0 14px; }
    .admin-page h3 { color: #243650; font-size: 15px; line-height: 1.4; margin: 20px 0 10px; }
    .admin-page p { line-height: 1.6; }
    .admin-page .muted, .admin-page .small { color: var(--muted); }
    .admin-page .small { font-size: 12px; }
    .admin-page .grid { gap: 16px; }
    .admin-page .cols { gap: 16px; }
    .admin-page .panel, .admin-page .card {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 15px;
        box-shadow: var(--shadow);
    }
    .admin-page .panel { padding: 22px; margin: 18px 0; }
    .admin-page a.card { color: inherit; transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
    .admin-page a.card:hover { border-color: #a9c1f2; box-shadow: 0 16px 34px rgba(34, 74, 145, .1); transform: translateY(-2px); }
    .admin-page .metric { color: #152b4a; font-size: 29px; line-height: 1.1; letter-spacing: -.04em; }
    .admin-page .name { color: #1d2d46; letter-spacing: -.02em; }
    .admin-page .pill { border-radius: 999px; padding: 4px 10px; background: var(--blue-soft); color: #345da7; font-size: 12px; font-weight: 700; white-space: nowrap; }
    .admin-page .pill.warn { background: var(--amber-soft); color: var(--amber); }
    .admin-page .notice, .admin-page .status, .admin-page .alert {
        padding: 14px 16px;
        border: 1px solid #cbdcfb;
        border-radius: 12px;
        background: #eff5ff;
        color: #254779;
        box-shadow: 0 5px 18px rgba(30, 61, 111, .035);
        margin: 16px 0;
    }
    .admin-page .notice.warn { border-color: #f1dfb7; background: var(--amber-soft); color: #77460e; }
    .admin-page .notice pre { overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; }
    .admin-page label { display: block; margin: 13px 0 6px; color: #34445b; font-size: 13px; font-weight: 650; }
    .admin-page input:not([type="checkbox"]):not([type="hidden"]), .admin-page select, .admin-page textarea {
        display: block;
        width: 100%;
        min-height: 42px;
        padding: 10px 12px;
        border: 1px solid #d6deea;
        border-radius: 9px;
        background: #fff;
        color: #192a43;
        font: inherit;
        outline: none;
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .admin-page textarea { min-height: 82px; resize: vertical; }
    .admin-page input:focus, .admin-page select:focus, .admin-page textarea:focus { border-color: #6f95e5; box-shadow: 0 0 0 3px rgba(74, 119, 214, .14); }
    .admin-page input[type="checkbox"] { accent-color: var(--blue); }
    .admin-page button.submit, .admin-page button.save, .admin-page .button.primary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        margin-top: 14px;
        padding: 9px 15px;
        border: 1px solid var(--blue);
        border-radius: 9px;
        background: var(--blue);
        color: #fff;
        font: inherit;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        transition: background .15s ease, box-shadow .15s ease, transform .15s ease;
    }
    .admin-page button.submit:hover, .admin-page button.save:hover, .admin-page .button.primary:hover { background: var(--blue-dark); box-shadow: 0 6px 14px rgba(53,104,212,.2); transform: translateY(-1px); }
    .admin-page button.danger { border-color: var(--red)!important; background: var(--red)!important; color: #fff!important; }
    .admin-page .table-wrap { overflow: auto; border: 1px solid var(--line); border-radius: 11px; }
    .admin-page table { width: 100%; border-collapse: separate; border-spacing: 0; background: #fff; }
    .admin-page th { background: #f7f9fc; color: #65748a; font-size: 11px; font-weight: 750; letter-spacing: .07em; text-transform: uppercase; }
    .admin-page td, .admin-page th { padding: 12px 13px; border-bottom: 1px solid #e9edf3; text-align: left; vertical-align: top; }
    .admin-page tr:last-child td { border-bottom: 0; }
    .admin-page td { color: #34445b; }
    .admin-page .form-grid { gap: 12px; }
    .admin-page .inline { display: inline; }
    .admin-page .qr canvas { box-shadow: 0 2px 9px rgba(25, 42, 69, .08); }

    body.auth-page {
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 24px;
        background: radial-gradient(circle at 14% 15%, rgba(80, 121, 211, .2), transparent 35%), linear-gradient(135deg, #102440 0%, #1b3961 58%, #264b79 100%);
        color: #192a43;
        font: 15px/1.55 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .auth-page .card {
        width: min(460px, 100%);
        padding: 34px;
        border: 1px solid rgba(255,255,255,.58);
        border-radius: 20px;
        background: rgba(255,255,255,.98);
        box-shadow: 0 28px 80px rgba(4, 17, 37, .28);
    }
    .auth-page h1 { color: #152943; font-size: 25px; letter-spacing: -.04em; margin: 0 0 7px; }
    .auth-page p { margin: 0 0 20px; color: #66768d; }
    .auth-page label { display: block; margin: 17px 0 6px; color: #334660; font-size: 13px; font-weight: 700; }
    .auth-page input { display: block; width: 100%; min-height: 46px; padding: 11px 13px; border: 1px solid #d5deea; border-radius: 10px; background: #fff; color: #17243a; font: inherit; outline: none; }
    .auth-page input:focus { border-color: #6f95e5; box-shadow: 0 0 0 3px rgba(74,119,214,.14); }
    .auth-page button { width: 100%; min-height: 46px; margin-top: 23px; border: 0; border-radius: 10px; background: #3568d4; color: #fff; font: inherit; font-weight: 750; cursor: pointer; box-shadow: 0 8px 18px rgba(53,104,212,.2); }
    .auth-page button:hover { background: #2856bd; }
    .auth-page .error { color: #a92e42; }
    .auth-page .status { color: #16805a; }
    @media (max-width: 720px) {
        .admin-sidebar { position: sticky; inset: auto; width: 100%; min-height: 0; flex-direction: row; align-items: center; gap: 15px; padding: 10px 14px; overflow-x: auto; }
        .sidebar-brand { flex: 0 0 auto; padding: 0; }
        .brand-mark { width: 36px; height: 36px; }
        .brand-copy small, .sidebar-label, .sidebar-foot { display: none!important; }
        .admin-sidebar nav { display: flex; flex: 0 0 auto; gap: 5px; }
        .admin-sidebar .sidebar-link { min-height: 38px; white-space: nowrap; }
        .admin-page .top { position: static; margin-left: 0; gap: 12px; padding: 14px 18px; }
        .admin-page .top .breadcrumbs { max-width: 100%; }
        .admin-page .top nav { flex-wrap: wrap; }
        .admin-page .wrap { width: 100%; margin: 0; padding: 25px 16px 48px; }
        .admin-page .panel { padding: 17px; }
        .admin-page .cols { grid-template-columns: 1fr; }
        .admin-page .grid { grid-template-columns: 1fr; }
        .payments-grid { grid-template-columns: 1fr; }
        .payments-page .page-heading { flex-direction: column; }
        .admin-page .page-actions { align-items: flex-end; flex-direction: column-reverse; gap: 8px; }
        .admin-page .school-dialog { padding: 20px; }
        .dialog-fields, .plan-fields { grid-template-columns: 1fr; }
        .dialog-wide, .field-wide { grid-column: auto; }
        .plan-card { padding: 16px; }
        .auth-page .card { padding: 26px 22px; }
    }
</style>
