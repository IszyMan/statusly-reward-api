@extends('admin.layout')

@section('title', 'Popups')

@section('content')
    <div class="page-heading">
        <div>
            <h1>Popups</h1>

            <p class="help">
                Manage Saved-screen campaign announcements.
            </p>
        </div>

        <a class="button" href="{{ route('admin.popups.create') }}">
            Create popup
        </a>
    </div>

    @if (session('success'))
        <div class="success" role="status">
            {{ session('success') }}
        </div>
    @endif

    <section class="card">
        @if ($popups->isEmpty())
            <h2>No popups yet</h2>

            <p class="help">
                Create a popup and link it to an existing campaign.
            </p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Popup</th>
                            <th>Campaign</th>
                            <th>Template</th>
                            <th>Enabled</th>
                            <th>Priority</th>
                            <th>Revision</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($popups as $popup)
                            <tr>
                                <td>
                                    <strong>{{ $popup->name }}</strong>

                                    <div class="help">
                                        {{ $popup->title }}
                                    </div>
                                </td>

                                <td>
                                    {{ $popup->campaign_name }}

                                    <div class="help">
                                        {{ strtoupper($popup->campaign_environment) }}
                                        · {{ ucfirst($popup->campaign_status) }}
                                    </div>
                                </td>

                                <td>
                                    {{ [
                                        'gift_card' => 'Gift card',
                                        'gift_corner_left' => 'Golden gift',
                                        'gift_corner_right' => 'Pink surprise',
                                        'gift_seal' => 'Gift seal',
                                        'simple_card' => 'Simple card',
                                    ][$popup->template] ?? $popup->template }}
                                </td>

                                <td>
                                    {{ $popup->enabled ? 'Yes' : 'No' }}
                                </td>

                                <td>{{ $popup->priority }}</td>

                                <td>{{ $popup->revision }}</td>

                                <td>
                                    <a href="{{ route('admin.popups.edit', $popup->id) }}">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="actions" style="margin-top:20px;">
                @if ($popups->previousPageUrl())
                    <a href="{{ $popups->previousPageUrl() }}">Previous</a>
                @endif

                <span>
                    Page {{ $popups->currentPage() }}
                    of {{ $popups->lastPage() }}
                </span>

                @if ($popups->nextPageUrl())
                    <a href="{{ $popups->nextPageUrl() }}">Next</a>
                @endif
            </div>
        @endif
    </section>
@endsection