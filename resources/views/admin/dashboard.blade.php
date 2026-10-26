@extends('admin.layout')

@section('title', 'Dashboard')

@section('content')
    <h1>Rewards dashboard</h1>
    <p class="muted intro">
        Monitor campaigns, participation, and delivered rewards.
    </p>

    <div class="stats">
        <div class="card">
            <span class="muted">Campaigns</span>
            <strong class="stat-number">
                {{ number_format($campaignCount) }}
            </strong>
        </div>

        <div class="card">
            <span class="muted">Recognized devices</span>
            <strong class="stat-number">
                {{ number_format($deviceCount) }}
            </strong>
        </div>

        <div class="card">
            <span class="muted">Campaign enrolments</span>
            <strong class="stat-number">
                {{ number_format($participantCount) }}
            </strong>
        </div>

        <div class="card">
            <span class="muted">Delivered rewards</span>
            <strong class="stat-number">
                {{ number_format($deliveredCount) }}
            </strong>
        </div>
    </div>

    <section class="card">
        <h2>Campaigns</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Environment</th>
                        <th>Status</th>
                        <th>Enrolled / limit</th>
                        <th>Budget</th>
                        <th>Allocated budget</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaigns as $campaign)
                        <tr>
                            <td>{{ $campaign->name }}</td>
                            <td>
                                <span class="badge">
                                    {{ strtoupper($campaign->environment) }}
                                </span>
                            </td>
                            <td>{{ ucfirst($campaign->status) }}</td>
                            <td>
                                {{ number_format($campaign->enrolled_count) }}
                                /
                                {{ number_format($campaign->participant_limit) }}
                            </td>
                            <td>
                                ₦{{ number_format($campaign->budget_kobo / 100, 2) }}
                            </td>
                            <td>
                                ₦{{ number_format($campaign->allocated_budget_kobo / 100, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="empty" colspan="6">
                                No campaigns yet.
                                Campaigns you create will appear here.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($campaigns->hasPages())
            <nav class="pagination" aria-label="Campaign pages">
                <div>
                    @if ($campaigns->previousPageUrl())
                        <a href="{{ $campaigns->previousPageUrl() }}">
                            Previous
                        </a>
                    @endif
                </div>

                <span class="muted">
                    Page {{ $campaigns->currentPage() }}
                    of {{ $campaigns->lastPage() }}
                </span>

                <div>
                    @if ($campaigns->nextPageUrl())
                        <a href="{{ $campaigns->nextPageUrl() }}">
                            Next
                        </a>
                    @endif
                </div>
            </nav>
        @endif
    </section>
@endsection