@extends('admin.layout')

@section('title', 'Registered devices')

@section('content')
    <h1>Registered devices</h1>

    <p class="help">
        All rewards device registrations, including devices that
        have not joined a campaign. Campaign counts include
        sandbox and live participation.
    </p>

    <section class="card">
        <h2>Find a device</h2>

        <form
            method="GET"
            action="{{ route('admin.registered-devices.index') }}"
        >
            <label for="registered-search">
                Device ID, public ID or referral code
            </label>

            <input
                id="registered-search"
                name="search"
                type="search"
                maxlength="120"
                value="{{ $search }}"
                placeholder="Enter an exact ID or referral code"
            >

            <label for="registered-status">
                Status
            </label>

            <select
                id="registered-status"
                name="status"
            >
                <option value="">
                    All devices
                </option>

                <option
                    value="active"
                    @selected($status === 'active')
                >
                    Active
                </option>

                <option
                    value="blocked"
                    @selected($status === 'blocked')
                >
                    Blocked
                </option>

                <option
                    value="archived"
                    @selected($status === 'archived')
                >
                    Archived
                </option>
            </select>

            <p class="help">
                Active means the device is neither blocked nor archived.
                It does not necessarily mean the user is currently online.
            </p>

            <div class="actions" style="margin-top:16px;">
                <button type="submit">
                    Search devices
                </button>

                <a href="{{ route('admin.registered-devices.index') }}">
                    Clear filters
                </a>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Device registrations</h2>

        <p class="help">
            {{ number_format($devices->total()) }}
            device(s) matching these filters.
        </p>

        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>Referral code</th>
                        <th>Registered</th>
                        <th>Last activity</th>
                        <th>Campaigns joined</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($devices as $device)
                        <tr>
                            <td>
                                <strong>
                                    #{{ $device->id }}
                                </strong>

                                <br>

                                <small style="overflow-wrap:anywhere;">
                                    {{ $device->public_id }}
                                </small>
                            </td>

                            <td>
                                {{ $device->referral_code }}
                            </td>

                            <td>
                                {{ $device->first_seen_at ?? '—' }}
                            </td>

                            <td>
                                {{ $device->last_seen_at ?? '—' }}
                            </td>

                            <td>
                                {{ $device->campaigns_count }}
                            </td>

                            <td>
                                <span class="badge">
                                    @if ($device->archived_at)
                                        Archived
                                    @elseif ($device->blocked_at)
                                        Blocked
                                    @else
                                        Active
                                    @endif
                                </span>
                            </td>

                            <td>
                                <a
                                    href="{{ route('admin.devices.show', [
                                        'device' => $device->id,
                                        'environment' => config('vtpass.environment'),
                                    ]) }}"
                                >
                                    View device
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                No registered devices match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <nav
            class="actions"
            aria-label="Registered device pages"
            style="margin-top:20px;"
        >
            @if ($devices->previousPageUrl())
                <a href="{{ $devices->previousPageUrl() }}">
                    Previous
                </a>
            @endif

            <span>
                Page {{ $devices->currentPage() }}
                of {{ $devices->lastPage() }}
            </span>

            @if ($devices->nextPageUrl())
                <a href="{{ $devices->nextPageUrl() }}">
                    Next
                </a>
            @endif
        </nav>
    </section>
@endsection