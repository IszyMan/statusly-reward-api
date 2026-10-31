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

                            <td>
                                {{ ucfirst($campaign->status) }}
                                <small class="help">
                                    {{ $campaign->show_in_app ? 'Visible in app' : 'Hidden from app' }}
                                </small>
                            </td>

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
                                <div style="display:flex; flex-direction:column; gap:8px; align-items:flex-start;">

                                    {{-- Main actions side by side --}}
                                    <div style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;">

                                        {{-- Edit campaign --}}
                                        <a
                                            href="{{ route('admin.campaigns.edit', $campaign->id) }}"
                                            class="button"
                                            style="background:#2563eb; color:#fff; border-color:#2563eb;"
                                        >
                                            Edit
                                        </a>

                                        {{-- Show or hide campaign in the app --}}
                                        <form
                                            method="POST"
                                            action="{{ route('admin.campaigns.visibility', $campaign->id) }}"
                                            style="margin:0;"
                                        >
                                            @csrf

                                            <button
                                                type="submit"
                                                onclick="return confirm('{{ $campaign->show_in_app ? 'Hide this campaign from the app?' : 'Show this campaign in the app?' }}')"
                                            >
                                                {{ $campaign->show_in_app ? 'Hide from app' : 'Show in app' }}
                                            </button>
                                        </form>

                                    </div>

                                    {{-- Activate sandbox campaign --}}
                                    @if (
                                        $campaign->status === 'draft' &&
                                        $campaign->environment === 'sandbox'
                                    )
                                        <form
                                            method="POST"
                                            action="{{ route('admin.campaigns.activate', $campaign->id) }}"
                                            style="margin:0;"
                                        >
                                            @csrf

                                            <button
                                                type="submit"
                                                onclick="return confirm('Activate this sandbox campaign for testing?')"
                                            >
                                                Activate
                                            </button>
                                        </form>
                                    @endif

                                </div>
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