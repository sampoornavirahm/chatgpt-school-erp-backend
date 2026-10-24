<aside class="admin-sidebar" aria-label="Primary navigation">
    <a class="sidebar-brand" href="{{ route('operator.schools.index') }}">
        <span class="brand-mark" aria-hidden="true">SE</span>
        <span class="brand-copy"><strong>School ERP</strong><small>ADMIN CONSOLE</small></span>
    </a>
    <div class="sidebar-label">WORKSPACE</div>
    <nav>
        <a class="sidebar-link {{ request()->routeIs('operator.schools.*') ? 'active' : '' }}" href="{{ route('operator.schools.index') }}"><span class="sidebar-icon" aria-hidden="true">▦</span><span>Schools</span></a>
        <a class="sidebar-link {{ request()->routeIs('operator.plans.*') ? 'active' : '' }}" href="{{ route('operator.plans.index') }}"><span class="sidebar-icon" aria-hidden="true">◇</span><span>License plans</span></a>
        <a class="sidebar-link {{ request()->routeIs('operator.payments.*') ? 'active' : '' }}" href="{{ route('operator.payments.index') }}"><span class="sidebar-icon" aria-hidden="true">₹</span><span>Payments</span></a>
        <a class="sidebar-link {{ request()->routeIs('operator.support.*') ? 'active' : '' }}" href="{{ route('operator.support.index') }}"><span class="sidebar-icon" aria-hidden="true">?</span><span>Parent support</span></a>
        <a class="sidebar-link {{ request()->routeIs('operator.app-updates.*') ? 'active' : '' }}" href="{{ route('operator.app-updates.index') }}"><span class="sidebar-icon" aria-hidden="true">⬆</span><span>App updates</span></a>
    </nav>
    <div class="sidebar-foot"><span class="sidebar-status-dot"></span><span><strong>Control center</strong><small>Hosted environment</small></span></div>
</aside>
