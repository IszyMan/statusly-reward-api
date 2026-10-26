@extends('admin.layout')

@section('title', 'Rewards & Transactions')

@section('content')
    <h1>Rewards & Transactions</h1>

    <p class="muted intro">
        Inspect earned rewards, redemptions, and delivery confirmation.
    </p>

    @if ($errors->any())
        <div class="error" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="stats">
        @foreach ([
            'earned' => 'Awaiting redemption',
            'processing' => 'Processing',
            'delivered' => 'Delivered',
            'review' => 'Needs review',
        ] as $key => $label)
            <div class="card">
                <span class="muted">{{ $label }}</span>
                <strong class="stat-number">
                    {{ number_format($counts->get($key, 0)) }}
                </strong>
            </div>
        @endforeach
    </div>

    <p class="help">
        Summary totals are for the selected environment.
        Dates on this page are UTC.
    </p>

    <section class="card">
        <form
            method="GET"
            action="{{ route('admin.rewards.index') }}"
            class="filter-grid"
        >
            <div>
                <label for="environment">Environment</label>
                <select id="environment" name="environment">
                    <option value="sandbox" @selected($environment === 'sandbox')>
                        Sandbox
                    </option>
                    <option value="live" @selected($environment === 'live')>
                        Live
                    </option>
                </select>
            </div>

            <div>
                <label for="status">Reward status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>

                    @foreach ([
                        'earned' => 'Earned',
                        'processing' => 'Processing',
                        'delivered' => 'Delivered',
                        'review' => 'Review',
                    ] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="search">Search</label>
                <input
                    id="search"
                    name="search"
                    value="{{ $search }}"
                    maxlength="120"
                    placeholder="Campaign, reward ID, device ID, code, phone or reference"
                >
            </div>

            <div class="filter-actions">
                <button type="submit">Filter</button>

                <a href="{{ route('admin.rewards.index', [
                    'environment' => $environment,
                ]) }}">
                    Clear
                </a>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Reward</th>
                        <th>Device</th>
                        <th>Reward status</th>
                        <th>Redemption</th>
                        <th>Activity</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($rewards as $reward)
                        <tr>
                            <td>
                                <strong>{{ $reward->task['title'] ?? 'Reward task' }}</strong>

                                <small class="help">
                                    {{ $reward->campaign_name }}
                                </small>

                                <code class="record-id">
                                    {{ $reward->reward_id }}
                                </code>

                                <small class="help">
                                    Earned: {{ $reward->earned_at }}
                                </small>
                            </td>

                            <td>
                                <a href="{{ route('admin.devices.show', [
                                    'device' => $reward->device_id,
                                    'environment' => $environment,
                                ]) }}">
                                    Device #{{ $reward->device_id }}
                                </a>

                                <small class="help">
                                    {{ $reward->referral_code }}
                                </small>
                            </td>

                            <td>
                                <span class="badge badge-{{ $reward->reward_status }}">
                                    {{ ucfirst($reward->reward_status) }}
                                </span>

                                @if ($reward->reward_delivered_at)
                                    <small class="help">
                                        {{ $reward->reward_delivered_at }}
                                    </small>
                                @endif
                            </td>

                            <td>
                                @if ($reward->redemption_id)
                                    <span class="badge badge-{{ $reward->transaction_status }}">
                                        {{ ucfirst($reward->transaction_status) }}
                                    </span>

                                    <small class="help">
                                        {{ strtoupper($reward->network) }}
                                        · {{ $reward->phone }}
                                    </small>

                                    <code class="record-id">
                                        {{ $reward->request_id }}
                                    </code>

                                    <details class="record-details">
                                        <summary>Transaction details</summary>

                                        <dl>
                                            <dt>Bundle</dt>
                                            <dd>{{ $reward->bundle['bundle_name'] ?? '—' }}</dd>

                                            <dt>Quoted bundle price</dt>
                                            <dd>
                                                ₦{{ number_format($reward->quoted_price_kobo / 100, 2) }}
                                            </dd>

                                            <dt>VTpass response code</dt>
                                            <dd>{{ $reward->provider_code ?: '—' }}</dd>

                                            <dt>VTpass transaction status</dt>
                                            <dd>{{ $reward->provider_status ?: '—' }}</dd>

                                            <dt>VTpass transaction ID</dt>
                                            <dd>{{ $reward->provider_transaction_id ?: '—' }}</dd>

                                            <dt>Message</dt>
                                            <dd>{{ $reward->message ?: '—' }}</dd>

                                            <dt>Submitted</dt>
                                            <dd>{{ $reward->submitted_at }}</dd>

                                            <dt>Last recorded response</dt>
                                            <dd>{{ $reward->checked_at ?: '—' }}</dd>
                                        </dl>
                                    </details>
                                @else
                                    <span class="muted">
                                        User has not redeemed yet.
                                    </span>
                                @endif
                            </td>

                            <td>
                                @if ($reward->redemption_id)
                                    <small class="help">
                                        Automatic attempts:
                                        {{ $reward->auto_check_attempts }}
                                    </small>

                                    @if (in_array($reward->transaction_status, ['pending', 'submitting'], true))
                                        <small class="help">
                                            Next check:
                                            {{ $reward->next_check_at ?: 'On the next eligible scheduler run' }}
                                        </small>
                                    @else
                                        <small class="help">
                                            Automatic checking stopped.
                                        </small>
                                    @endif

                                    @if (
                                        $reward->environment === 'sandbox'
                                        && config('vtpass.environment') === 'sandbox'
                                        && app()->environment('local', 'testing')
                                        && $reward->transaction_status !== 'delivered'
                                    )
                                        <form
                                            method="POST"
                                            action="{{ route('admin.rewards.check', $reward->reward_id) }}"
                                            style="margin-top: 12px;"
                                        >
                                            @csrf

                                            <button class="secondary small-button" type="submit">
                                                Check existing transaction
                                            </button>
                                        </form>

                                        <small class="help">
                                            Queries its reference. Does not purchase again.
                                        </small>
                                    @endif
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="empty muted">
                                No rewards match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination', [
            'paginator' => $rewards,
        ])
    </section>
@endsection