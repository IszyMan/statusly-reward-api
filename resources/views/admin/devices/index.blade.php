@extends('admin.layout')

@section('title', 'Devices & Progress')

@section('content')
    <h1>Devices & Progress</h1>

    <p class="muted intro">
        Campaign participants and their task progress.
        Invited friends appear under their inviter until they join
        a campaign themselves.
    </p>

    <section class="card">
        <form
            method="GET"
            action="{{ route('admin.devices.index') }}"
            class="device-filter"
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
                <label for="search">Find a participant</label>

                <input
                    id="search"
                    name="search"
                    value="{{ $search }}"
                    maxlength="120"
                    placeholder="Device number, public ID or referral code"
                >
            </div>

            <div class="filter-actions">
                <button type="submit">Search</button>

                <a href="{{ route('admin.devices.index', [
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
                        <th>Device</th>
                        <th>Campaign tasks</th>
                        <th>Last seen, UTC</th>
                        <th>Access</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($devices as $device)
                        <tr>
                            <td>
                                <a href="{{ route('admin.devices.show', [
                                    'device' => $device->id,
                                    'environment' => $environment,
                                ]) }}">
                                    <strong>Device #{{ $device->id }}</strong>
                                </a>

                                <small class="help">
                                    {{ $device->referral_code }}
                                </small>

                                <code class="record-id">
                                    {{ $device->public_id }}
                                </code>

                                <a
                                    class="help"
                                    href="{{ route('admin.devices.show', [
                                        'device' => $device->id,
                                        'environment' => $environment,
                                    ]) }}#invited-friends"
                                >
                                    View invited friends
                                </a>
                            </td>

                            <td class="device-progress-cell">
                                @foreach ($device->campaigns as $campaignTasks)
                                    <div class="device-campaign">
                                        <div class="device-campaign-heading">
                                            <strong>
                                                {{ $campaignTasks->first()->campaign_name }}
                                            </strong>

                                            <small class="muted">
                                                {{ ucfirst($campaignTasks->first()->campaign_status) }}
                                            </small>
                                        </div>

                                        @foreach ($campaignTasks as $item)
                                            @include('admin.devices.task-progress', [
                                                'item' => $item,
                                            ])
                                        @endforeach
                                    </div>
                                @endforeach
                            </td>

                            <td>{{ $device->last_seen_at ?: '—' }}</td>

                            <td>
                                <span class="badge {{ $device->blocked_at ? 'badge-review' : 'badge-delivered' }}">
                                    {{ $device->blocked_at ? 'Blocked' : 'Enabled' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="empty muted">
                                No campaign participants match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination', [
            'paginator' => $devices,
        ])
    </section>
@endsection