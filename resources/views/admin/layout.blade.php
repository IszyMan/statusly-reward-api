<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', 'Dashboard') — Statusly Rewards</title>

    <style>
        :root {
            color-scheme: light;
            --green: #075e54;
            --accent: #04af46;
            --background: #f5f7f6;
            --border: #e3e8e6;
            --text: #17201d;
            --muted: #6b7773;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font: 15px/1.5 Arial, sans-serif;
        }

        a { color: var(--green); }
        button, input, select, textarea { font: inherit; }

        h1 { margin: 0 0 6px; font-size: 28px; }
        h2 { margin: 0 0 14px; font-size: 20px; }
        h3 { margin: 0; font-size: 17px; }

        .muted { color: var(--muted); }
        .intro { margin: 0 0 24px; }

        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: 240px;
            z-index: 30;
            display: flex;
            flex-direction: column;
            padding: 26px 16px;
            background: var(--green);
            color: white;
            overflow-y: auto;
        }

        .brand {
            display: block;
            padding: 0 12px;
            margin-bottom: 32px;
            color: white;
            font-size: 21px;
            font-weight: 700;
            text-decoration: none;
        }

        .brand small {
            display: block;
            margin-top: 4px;
            color: #bcd9cd;
            font-size: 12px;
            font-weight: 400;
        }

        .sidebar nav {
            display: grid;
            gap: 8px;
        }

        .sidebar nav a {
            padding: 12px 14px;
            border-radius: 10px;
            color: #e2f0e9;
            text-decoration: none;
            font-weight: 600;
        }

        .sidebar nav a:hover { background: #ffffff15; }

        .sidebar nav a.active {
            color: white;
            background: #ffffff22;
            box-shadow: inset 3px 0 var(--accent);
        }

        .sidebar-bottom {
            margin-top: auto;
            padding: 30px 12px 0;
        }

        .sidebar-bottom small {
            display: block;
            overflow-wrap: anywhere;
            margin-bottom: 12px;
            color: #c6dfd4;
        }

        .workspace { margin-left: 240px; }

        .topbar {
            min-height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 28px;
            background: white;
            border-bottom: 1px solid var(--border);
        }

        .topbar strong { font-size: 16px; }
        .mobile-toggle { display: none; }

        main {
            max-width: 1400px;
            margin: auto;
            padding: 28px;
        }

        .guest-header {
            padding: 22px;
            background: var(--green);
            color: white;
            font-size: 20px;
            font-weight: 700;
        }

        .guest-main { max-width: 600px; }

        .card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stats .card { margin: 0; }

        .stat-number {
            display: block;
            margin-top: 8px;
            font-size: 30px;
            font-weight: 700;
            color: var(--green);
        }

        .login-card { margin: 36px auto; }

        label {
            display: block;
            margin: 16px 0 6px;
            font-weight: 600;
        }

        input, select, textarea {
            width: 100%;
            min-width: 0;
            padding: 11px 12px;
            border: 1px solid #bac9c2;
            border-radius: 9px;
            background: white;
            color: var(--text);
        }

        textarea { resize: vertical; }

        input:focus-visible,
        select:focus-visible,
        textarea:focus-visible,
        button:focus-visible,
        a:focus-visible,
        summary:focus-visible {
            outline: 3px solid #8bdcb6;
            outline-offset: 3px;
        }

        button, .button {
            display: inline-block;
            border: 0;
            border-radius: 9px;
            padding: 11px 17px;
            background: var(--green);
            color: white;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        button:disabled {
            opacity: .55;
            cursor: wait;
        }

        .secondary {
            background: #edf4f0;
            color: var(--green);
        }

        .danger {
            background: #fff0f0;
            color: #a31d1d;
        }

        .logout {
            border: 1px solid #ffffff60;
            background: transparent;
            color: white;
        }

        .login-button { width: 100%; margin-top: 24px; }

        .error, .success, .notice {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .error { background: #fff0f0; color: #a31d1d; }
        .success { background: #e5f6eb; color: #145f33; }
        .notice { background: #fff7e2; color: #78591c; }

        .error ul { margin: 0; padding-left: 20px; }

        .row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .network-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .network-card {
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        .network-card label:first-child { margin-top: 0; }

        .page-heading, .task-heading, .actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
        }

        .help {
            display: block;
            margin-top: 6px;
            color: var(--muted);
            font-size: 13px;
        }

        .task-card { border-top: 4px solid var(--green); }

        .estimate {
            padding: 16px;
            border-radius: 10px;
            background: #edf6f0;
            margin: 20px 0;
            color: var(--green);
        }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th, td {
            padding: 14px 12px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }

        th { color: var(--muted); font-size: 13px; }

        .badge {
            display: inline-block;
            background: #edf4f0;
            color: var(--green);
            padding: 4px 9px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
        }

        .empty { padding: 32px 12px; text-align: center; }

        .pagination {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 18px;
        }

        .overlay { display: none; }
        [hidden] { display: none !important; }

        @media (max-width: 1100px) {
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .network-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 800px) {
            .sidebar {
                visibility: hidden;
                transform: translateX(-100%);
                transition: transform .2s ease;
            }

            body.menu-open .sidebar {
                visibility: visible;
                transform: translateX(0);
            }

            .workspace { margin-left: 0; }
            .mobile-toggle { display: inline-block; }
            .topbar { padding: 14px 18px; }

            body.menu-open .overlay {
                display: block;
                position: fixed;
                inset: 0;
                z-index: 20;
                border-radius: 0;
                background: #0007;
            }
        }

        @media (max-width: 560px) {
            main { padding: 20px 12px; }
            .card { padding: 18px; }
            .row { grid-template-columns: 1fr; gap: 0; }
            .stats { gap: 10px; }
            .stat-number { font-size: 25px; }
            h1 { font-size: 24px; }
        }


        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 2fr auto;
            align-items: end;
            gap: 14px;
        }

        .filter-grid label {
            margin-top: 0;
        }

        .filter-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 2px;
        }

        .record-id {
            display: block;
            max-width: 290px;
            overflow-wrap: anywhere;
            font-size: 12px;
        }

        .badge-earned {
            background: #e8f0ff;
            color: #244d91;
        }

        .badge-processing,
        .badge-pending,
        .badge-submitting {
            background: #fff3d4;
            color: #795700;
        }

        .badge-delivered {
            background: #e2f5e8;
            color: #146537;
        }

        .badge-review {
            background: #ffebeb;
            color: #a52b2b;
        }

        .progress-track {
            height: 9px;
            min-width: 130px;
            margin-top: 8px;
            overflow: hidden;
            border-radius: 20px;
            background: #e5eee8;
        }

        .progress-fill {
            height: 100%;
            background: var(--accent);
            border-radius: inherit;
        }

        .record-details {
            margin-top: 12px;
            max-width: 430px;
        }

        .record-details summary {
            cursor: pointer;
            color: var(--green);
            font-weight: 600;
        }

        .record-details dl,
        .profile-details {
            margin: 14px 0 0;
        }

        .record-details dt,
        .profile-details dt {
            margin-top: 10px;
            color: var(--muted);
            font-size: 12px;
        }

        .record-details dd,
        .profile-details dd {
            margin: 2px 0 0;
            overflow-wrap: anywhere;
        }

        .small-button {
            padding: 8px 12px;
            font-size: 13px;
        }

        @media (max-width: 1000px) {
            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 560px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }

        .device-filter {
            display: grid;
            grid-template-columns: 180px minmax(200px, 1fr) auto;
            align-items: end;
            gap: 16px;
        }

        .device-filter label {
            margin-top: 0;
        }

        .device-progress-cell {
            min-width: 280px;
        }

        .device-campaign + .device-campaign {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
        }

        .device-campaign-heading,
        .device-task-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .device-campaign-heading {
            margin-bottom: 12px;
        }

        .device-task {
            margin: 12px 0 16px;
        }

        .device-task-heading strong {
            font-size: 14px;
        }

        .device-task-count {
            white-space: nowrap;
            font-weight: 700;
            color: var(--green);
        }

        .device-detail-task {
            padding: 18px 0;
            border-top: 1px solid var(--border);
        }

        .friend-card {
            padding: 20px 0;
            border-top: 1px solid var(--border);
        }

        .friend-card .page-heading {
            margin-bottom: 10px;
        }

        .friend-credit {
            margin: 8px 0;
            padding: 10px 12px;
            border-radius: 8px;
            background: #edf7f0;
            color: #185e38;
            font-size: 13px;
        }

        .file-preview {
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }

        #invited-friends,
        #saved-files {
            scroll-margin-top: 24px;
        }

        @media (max-width: 800px) {
            .device-filter {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    @if (auth()->check() && auth()->user()->is_admin)
        <aside class="sidebar" id="admin-sidebar" aria-label="Admin menu">
            <a class="brand" href="{{ route('admin.dashboard') }}">
                Statusly Rewards
                <small>Campaign administration</small>
            </a>

            <nav aria-label="Main navigation">
                <a
                    href="{{ route('admin.dashboard') }}"
                    class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"
                    @if (request()->routeIs('admin.dashboard'))
                        aria-current="page"
                    @endif
                >
                    Dashboard
                </a>

                <a
                    href="{{ route('admin.campaigns.index') }}"
                    class="{{ request()->routeIs('admin.campaigns.index') ? 'active' : '' }}"
                    @if (request()->routeIs('admin.campaigns.index'))
                        aria-current="page"
                    @endif
                >
                    Campaigns
                </a>

                <a
                    href="{{ route('admin.campaigns.create') }}"
                    class="{{ request()->routeIs('admin.campaigns.create') ? 'active' : '' }}"
                    @if (request()->routeIs('admin.campaigns.create'))
                        aria-current="page"
                    @endif
                >
                    Create campaign
                </a>


                <a
                    href="{{ route('admin.rewards.index') }}"
                    class="{{ request()->routeIs('admin.rewards.*') ? 'active' : '' }}"
                    @if (request()->routeIs('admin.rewards.*'))
                        aria-current="page"
                    @endif
                >
                    Rewards & Transactions
                </a>

                <a
                    href="{{ route('admin.devices.index') }}"
                    class="{{ request()->routeIs('admin.devices.*') ? 'active' : '' }}"
                    @if (request()->routeIs('admin.devices.*'))
                        aria-current="page"
                    @endif
                >
                    Devices & Progress
                </a>
            </nav>

            <div class="sidebar-bottom">
                <small>{{ auth()->user()->email }}</small>

                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="logout" type="submit">Log out</button>
                </form>
            </div>
        </aside>

        <button
            class="overlay"
            type="button"
            aria-label="Close navigation"
            tabindex="-1"
        ></button>

        <div class="workspace">
            <header class="topbar">
                <button
                    class="mobile-toggle secondary"
                    type="button"
                    aria-controls="admin-sidebar"
                    aria-expanded="false"
                >
                    Menu
                </button>

                <strong>@yield('title', 'Dashboard')</strong>

                <span class="badge">Rewards admin</span>
            </header>

            <main>
                @if (session('success'))
                    <div class="success" role="status">
                        {{ session('success') }}
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    @else
        <header class="guest-header">Statusly Rewards</header>

        <main class="guest-main">
            @yield('content')
        </main>
    @endif

    <script>
        const menuButton = document.querySelector('.mobile-toggle');
        const overlay = document.querySelector('.overlay');

        function closeAdminMenu() {
            document.body.classList.remove('menu-open');
            menuButton?.setAttribute('aria-expanded', 'false');
        }

        menuButton?.addEventListener('click', () => {
            const open = document.body.classList.toggle('menu-open');

            menuButton.setAttribute(
                'aria-expanded',
                open ? 'true' : 'false'
            );
        });

        overlay?.addEventListener('click', closeAdminMenu);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAdminMenu();
            }
        });
    </script>

    @stack('scripts')
</body>
</html>