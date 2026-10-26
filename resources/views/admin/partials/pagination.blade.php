@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Results pagination">
        <div>
            @if ($paginator->previousPageUrl())
                <a href="{{ $paginator->previousPageUrl() }}">
                    Previous
                </a>
            @endif
        </div>

        <span class="muted">
            Page {{ $paginator->currentPage() }}
            of {{ $paginator->lastPage() }}
        </span>

        <div>
            @if ($paginator->nextPageUrl())
                <a href="{{ $paginator->nextPageUrl() }}">
                    Next
                </a>
            @endif
        </div>
    </nav>
@endif