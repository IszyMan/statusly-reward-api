@extends('admin.layout')

@section('title', 'Device progress')

@section('content')
    @if ($errors->any())
        <div class="error" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="page-heading">
        <div>
            <h1>Device #{{ $profile->id }}</h1>
            <code class="record-id">{{ $profile->public_id }}</code>
        </div>

        <a
            class="button secondary"
            href="{{ route('admin.devices.index', [
                'environment' => $environment,
            ]) }}"
        >
            All participants
        </a>
    </div>

    @if ($profile->archived_at)
        <div class="notice">
            This sandbox profile was archived on
            {{ $profile->archived_at }} UTC.
            Its history remains available for inspection.
        </div>
    @endif

    <div class="row">
        <section class="card">
            <h2>Profile</h2>

            <dl class="profile-details">
                <dt>Own referral code</dt>
                <dd><strong>{{ $profile->referral_code }}</strong></dd>

                <dt>First registered, UTC</dt>
                <dd>{{ $profile->first_seen_at }}</dd>

                <dt>Last seen, UTC</dt>
                <dd>{{ $profile->last_seen_at ?: '—' }}</dd>

                <dt>Access</dt>
                <dd>
                    {{ $profile->archived_at
                        ? 'Archived'
                        : ($profile->blocked_at ? 'Blocked' : 'Enabled') }}
                </dd>

                @if ($profile->block_reason)
                    <dt>Reason</dt>
                    <dd>{{ $profile->block_reason }}</dd>
                @endif
            </dl>
        </section>

        <section class="card">
            <h2>Activity</h2>

            <dl class="profile-details">
                <dt>Lifetime distinct saved-file records</dt>
                <dd>{{ number_format($files->total()) }}</dd>

                <dt>Attributed invited friends in {{ $environment }}</dt>
                <dd>{{ number_format($friends->total()) }}</dd>

                <dt>Invited by</dt>
                <dd>
                    @if ($inviter)
                        <a href="{{ route('admin.devices.show', [
                            'device' => $inviter->id,
                            'environment' => $environment,
                        ]) }}">
                            Device #{{ $inviter->id }}
                        </a>

                        — {{ $inviter->referral_code }}
                    @else
                        No attribution in this environment.
                    @endif
                </dd>
            </dl>

            <p class="help">
                Invited friends count toward rewards only when the
                campaign's qualification rules are met.
            </p>
        </section>
    </div>

    <section class="card">
        <form
            method="GET"
            action="{{ route('admin.devices.show', $profile->id) }}"
            class="actions"
        >
            <div>
                <label for="environment" style="margin-top: 0;">
                    Campaign environment
                </label>

                <select id="environment" name="environment">
                    <option value="sandbox" @selected($environment === 'sandbox')>
                        Sandbox
                    </option>

                    <option value="live" @selected($environment === 'live')>
                        Live
                    </option>
                </select>
            </div>

            <button type="submit">Show records</button>
        </form>

        <h2>Campaign tasks</h2>

        @forelse ($progress as $item)
            <div class="device-detail-task">
                <div>
                    <strong>{{ $item->campaign_name }}</strong>

                    <small class="help">
                        {{ ucfirst($item->campaign_status) }}
                        · Joined {{ $item->enrolled_at }} UTC
                    </small>
                </div>

                @include('admin.devices.task-progress', [
                    'item' => $item,
                ])

                @if ($item->task['type'] === 'referrals')
                    <small class="help">
                        Each new invited device needs
                        {{ $item->task['qualification_rules']['required_unique_saves'] ?? '—' }}
                        distinct qualifying saves.
                    </small>
                @endif

                @if ($item->reward_id)
                    <a
                        class="help"
                        href="{{ route('admin.rewards.index', [
                            'environment' => $environment,
                            'search' => $item->reward_id,
                        ]) }}"
                    >
                        View reward and transaction
                    </a>
                @endif
            </div>
        @empty
            <p class="muted">
                This device has not started its own campaign tasks
                in {{ $environment }}.
            </p>
        @endforelse

        @include('admin.partials.pagination', [
            'paginator' => $progress,
        ])
    </section>

    <section class="card" id="invited-friends">
        <h2>Invited friends</h2>

        <p class="help">
            Each friend's saved-file count is its lifetime distinct total.
            Confirmed referral credits are shown separately.
        </p>

        @forelse ($friends as $friend)
            <article class="friend-card">
                <div class="page-heading">
                    <div>
                        <a href="{{ route('admin.devices.show', [
                            'device' => $friend->id,
                            'environment' => $environment,
                        ]) }}">
                            <strong>Device #{{ $friend->id }}</strong>
                        </a>

                        <small class="help">
                            Own code: {{ $friend->referral_code }}
                        </small>

                        <small class="help">
                            Invited: {{ $friend->attributed_at }} UTC
                        </small>
                    </div>

                    <div>
                        <span class="badge">
                            {{ number_format($friend->saved_count) }}
                            distinct saves
                        </span>

                        <span class="badge">
                            {{ $friend->enrolment_count > 0
                                ? 'Has own campaign enrolment'
                                : 'Invited only' }}
                        </span>

                        @if ($friend->archived_at)
                            <span class="badge badge-review">Archived test profile</span>
                        @endif
                    </div>
                </div>

                @forelse ($friend->credits as $credit)
                    <div class="friend-credit">
                        Counted toward
                        <strong>{{ $credit->campaign_name }}</strong>
                        — {{ $credit->task['title'] }}.
                    </div>
                @empty
                    <p class="help">
                        Not credited to a referral task.
                        Qualification depends on task rules, timing,
                        and whether the task still needs referrals.
                    </p>
                @endforelse

                <details class="record-details">
                    <summary>Recent distinct save records</summary>

                    @forelse ($friend->files as $file)
                        <div class="file-preview">
                            <strong>File #{{ $file->id }}</strong>
                            · {{ $file->media_type === 'videos' ? 'Video' : 'Photo' }}
                            · {{ $file->source === 'business' ? 'Business' : 'WhatsApp' }}

                            <small class="help">
                                {{ $file->accepted_at }} UTC
                            </small>

                            <code class="record-id">
                                {{ substr($file->content_hash, 0, 20) }}…
                            </code>
                        </div>
                    @empty
                        <p class="muted">No saved-file records yet.</p>
                    @endforelse

                    <a href="{{ route('admin.devices.show', [
                        'device' => $friend->id,
                        'environment' => $environment,
                    ]) }}#saved-files">
                        Open all saved-file records
                    </a>
                </details>
            </article>
        @empty
            <p class="muted">No invited friends in this environment.</p>
        @endforelse

        @include('admin.partials.pagination', [
            'paginator' => $friends,
        ])
    </section>

    <section class="card" id="saved-files">
        <h2>Distinct saved-file records</h2>

        <p class="help">
            These are file fingerprints and save metadata.
            Statusly does not upload the photos or videos to this dashboard.
            Dates are UTC.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>File record</th>
                        <th>Type</th>
                        <th>Source</th>
                        <th>Size</th>
                        <th>Accepted</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($files as $file)
                        <tr>
                            <td>
                                <strong>File #{{ $file->id }}</strong>

                                <code class="record-id">
                                    {{ substr($file->content_hash, 0, 24) }}…
                                </code>
                            </td>

                            <td>
                                {{ $file->media_type === 'videos' ? 'Video' : 'Photo' }}
                            </td>

                            <td>
                                {{ $file->source === 'business'
                                    ? 'WhatsApp Business'
                                    : ($file->source === 'whatsapp' ? 'WhatsApp' : 'Unknown') }}
                            </td>

                            <td>
                                {{ number_format($file->size_bytes / 1024, 1) }} KB
                            </td>

                            <td>{{ $file->accepted_at }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="empty muted">
                                No saved-file records.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination', [
            'paginator' => $files,
        ])
    </section>

    @if (
        ! $profile->archived_at
        && $environment === config('vtpass.environment')
    )
        <section class="card">
            <h2>Reset controls</h2>

            <p class="help">
                Reset session disconnects the device while preserving
                progress, referral attribution, and reward history.
                It is available in both live and sandbox.
            </p>

            <form
                method="POST"
                action="{{ route('admin.devices.reset', $profile->id) }}"
            >
                @csrf

                <input
                    type="hidden"
                    name="environment"
                    value="{{ $environment }}"
                >

                <label for="reset-action">Action</label>

                <select id="reset-action" name="action">
                    <option value="session">
                        Reset device session
                    </option>

                    @if (
                        $environment === 'sandbox'
                        && app()->environment('local', 'testing')
                    )
                        <option value="sandbox_restart">
                            Archive this profile and start a fresh sandbox test
                        </option>
                    @endif
                </select>

                @if (
                    $environment === 'sandbox'
                    && app()->environment('local', 'testing')
                )
                    <p class="help">
                        A fresh sandbox restart preserves this profile's
                        history as archived. The same phone can then register
                        with a new profile and referral code. Existing campaign
                        places and reservations remain recorded.
                    </p>
                @endif

                <label for="reset-reason">Reason, optional</label>

                <input
                    id="reset-reason"
                    name="reason"
                    maxlength="500"
                    value="{{ old('reason') }}"
                >

                <label for="reset-confirmation">
                    Type {{ $profile->referral_code }} to confirm
                </label>

                <input
                    id="reset-confirmation"
                    name="confirmation"
                    maxlength="16"
                    autocomplete="off"
                    required
                >

                <button
                    class="danger"
                    type="submit"
                    style="margin-top: 16px;"
                >
                    Apply reset
                </button>
            </form>
        </section>
    @endif

    @if ($resetLogs->isNotEmpty())
        <section class="card">
            <h2>Recent reset activity</h2>

            @foreach ($resetLogs as $log)
                <div class="file-preview">
                    <strong>
                        {{ $log->action === 'sandbox_restart'
                            ? 'Sandbox profile archived'
                            : 'Session reset' }}
                    </strong>

                    <small class="help">
                        {{ $log->created_at }} UTC
                        · {{ $log->admin_name ?: 'Administrator' }}
                        · {{ $log->environment }}
                    </small>

                    @if ($log->reason)
                        <p>{{ $log->reason }}</p>
                    @endif
                </div>
            @endforeach
        </section>
    @endif
@endsection