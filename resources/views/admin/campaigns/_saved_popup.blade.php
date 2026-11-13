<section class="card" style="margin-top:24px;">
    <h2>Saved-screen campaign popup</h2>

    <p class="help">
        Promote this campaign when users enter Saved.
        The campaign must also be active, visible in the app
        and within its campaign dates.
    </p>

    @if (session('success'))
        <p role="status" style="color:#128c7e;font-weight:600;">
            {{ session('success') }}
        </p>
    @endif

    <form
        method="POST"
        action="{{ route('admin.campaigns.saved-popup', $campaign->id) }}"
    >
        @csrf
        @method('PUT')

        <input
            type="hidden"
            name="saved_popup_enabled"
            value="0"
        >

        <label style="display:flex;align-items:center;gap:10px;">
            <input
                type="checkbox"
                name="saved_popup_enabled"
                value="1"
                @checked(
                    old(
                        'saved_popup_enabled',
                        $campaign->saved_popup_enabled ?? false
                    )
                )
            >

            <span>Enable popup on Saved</span>
        </label>

        <div style="margin-top:18px;">
            <label for="saved_popup_title">
                Popup title
            </label>

            <input
                id="saved_popup_title"
                type="text"
                name="saved_popup_title"
                maxlength="120"
                value="{{ old(
                    'saved_popup_title',
                    $campaign->saved_popup_title ?? ''
                ) }}"
                placeholder="Leave blank to use the campaign name"
                style="display:block;width:100%;margin-top:6px;"
            >

            @error('saved_popup_title')
                <p class="error">{{ $message }}</p>
            @enderror
        </div>

        <div style="margin-top:18px;">
            <label for="saved_popup_message">
                Popup message
            </label>

            <textarea
                id="saved_popup_message"
                name="saved_popup_message"
                rows="5"
                maxlength="2000"
                placeholder="Leave blank to use the campaign description"
                style="display:block;width:100%;margin-top:6px;"
            >{{ old(
                'saved_popup_message',
                $campaign->saved_popup_message ?? ''
            ) }}</textarea>

            @error('saved_popup_message')
                <p class="error">{{ $message }}</p>
            @enderror
        </div>

        <div style="margin-top:18px;">
            <label style="display:flex;align-items:center;gap:10px;">
                <input
                    type="checkbox"
                    name="publish_again"
                    value="1"
                    @checked(old('publish_again', false))
                >

                <span>
                    Show again to users who already saw this announcement
                </span>
            </label>
        </div>

        <p class="help">
            Changing the popup title or message also publishes
            a new announcement revision.
        </p>

        <p class="help">
            Current revision:
            {{ $campaign->saved_popup_revision ?? 1 }}
        </p>

        <div class="actions" style="margin-top:18px;">
            <button type="submit">
                Save popup settings
            </button>
        </div>
    </form>
</section>