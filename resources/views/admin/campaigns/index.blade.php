@extends('admin.layout')

@section('title', 'Campaigns')

@section('content')

    @if ($errors->any())
        <div class="error" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <div class="page-heading">
        <div>
            <h1>Campaigns</h1>
            <p class="muted">Manage your reward campaigns.</p>
        </div>

        <a class="button" href="{{ route('admin.campaigns.create') }}">
            Create campaign
        </a>
    </div>

    <section class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Campaign</th>
                        <th>Environment</th>
                        <th>Status</th>
                        <th>Participants</th>
                        <th>Budget</th>
                        <th>Allocated</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaigns as $campaign)
                        <tr>
                            <td>
                                <strong>{{ $campaign->name }}</strong>
                                <small class="help">
                                    {{ $campaign->audience === 'all'
                                        ? 'New and existing users'
                                        : 'New users only' }}
                                </small>
                            </td>

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

                            <td>
                                @if ($campaign->status === 'draft' && $campaign->environment === 'sandbox')
                                    <form
                                        method="POST"
                                        action="{{ route('admin.campaigns.activate', $campaign->id) }}"
                                    >
                                        @csrf

                                        <button
                                            type="submit"
                                            onclick="return confirm('Activate this sandbox campaign for testing?')"
                                        >
                                            Activate
                                        </button>
                                    </form>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="empty muted" colspan="7">
                                No campaigns yet. Create your first campaign.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($campaigns->hasPages())
            <nav class="pagination" aria-label="Campaign pagination">
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