<div class="device-task">
    <div class="device-task-heading">
        <strong>{{ $item->task['title'] }}</strong>

        <span class="device-task-count">
            {{ $item->progress_count }}/{{ $item->target }}
        </span>
    </div>

    <div
        class="progress-track"
        role="progressbar"
        aria-label="{{ $item->task['title'] }}"
        aria-valuemin="0"
        aria-valuemax="{{ $item->target }}"
        aria-valuenow="{{ min($item->target, (int) $item->progress_count) }}"
    >
        <div
            class="progress-fill"
            style="width: {{ $item->percentage }}%;"
        ></div>
    </div>

    @if ($item->reward_status)
        <small class="help">
            Reward:
            <span class="badge badge-{{ $item->reward_status }}">
                {{ ucfirst($item->reward_status) }}
            </span>
        </small>
    @endif
</div>