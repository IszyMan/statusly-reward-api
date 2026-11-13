@php
    $popup = $popup ?? null;
    $isEditing = $popup !== null;

    $value = function ($key, $default = '') use ($popup) {
        return old($key, $popup->{$key} ?? $default);
    };

    $templates = [
        'gift_card' => [
            'name' => 'Gift card',
            'description' => 'Gift above the centre',
        ],
        'gift_corner_left' => [
            'name' => 'Golden gift',
            'description' => 'Gold package at the upper left',
        ],
        'gift_corner_right' => [
            'name' => 'Pink surprise',
            'description' => 'Pink package at the upper right',
        ],
        'gift_seal' => [
            'name' => 'Gift seal',
            'description' => 'Purple gift in a circular badge',
        ],
        'simple_card' => [
            'name' => 'Simple card',
            'description' => 'Clean design without a gift',
        ],
    ];

    $selectedTemplate = $value('template', 'gift_card');

    $colors = [
        'background_start' => [
            'label' => 'Background top',
            'default' => '#E0FCFC',
        ],
        'background_end' => [
            'label' => 'Background bottom',
            'default' => '#99F3E9',
        ],
        'accent_color' => [
            'label' => 'Button colour',
            'default' => '#00BFA5',
        ],
        'text_color' => [
            'label' => 'Heading and message colour',
            'default' => '#173B36',
        ],
        'button_text_color' => [
            'label' => 'Button text colour',
            'default' => '#FFFFFF',
        ],
    ];
@endphp

<style>
    .pp-editor,
    .pp-preview-modal {
        --pp-background-start: #E0FCFC;
        --pp-background-end: #99F3E9;
        --pp-accent: #00BFA5;
        --pp-text: #173B36;
        --pp-button-text: #FFFFFF;
    }

    .pp-editor .pp-field {
        margin-bottom: 20px;
    }

    .pp-editor .pp-field > label {
        display: block;
        margin-bottom: 7px;
        font-weight: 600;
    }

    .pp-editor .pp-field > input,
    .pp-editor .pp-field > select,
    .pp-editor .pp-field > textarea {
        width: 100%;
    }

    .pp-editor .pp-help {
        margin: 7px 0 0;
        color: var(--muted, #6b7773);
        font-size: 13px;
        line-height: 1.5;
    }

    .pp-editor .pp-section-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 20px;
    }

    .pp-editor .pp-section-heading h2 {
        margin: 0 0 5px;
    }

    .pp-editor .pp-template-fieldset {
        min-width: 0;
        margin: 0;
        padding: 0;
        border: 0;
    }

    .pp-editor .pp-template-fieldset > legend {
        margin-bottom: 12px;
        font-weight: 700;
    }

    .pp-editor .pp-template-grid {
        display: grid;
        grid-template-columns: repeat(
            auto-fit,
            minmax(min(100%, 235px), 1fr)
        );
        gap: 18px;
    }

    .pp-editor .pp-choice {
        position: relative;
        min-width: 0;
    }

    /* Keep real radio inputs for keyboard access and form submission. */
    .pp-editor .pp-choice-input {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: 0;
        opacity: 0;
        overflow: hidden;
    }

    .pp-editor .pp-choice-label {
        display: flex;
        flex-direction: column;
        height: 100%;
        margin: 0;
        padding: 0;
        overflow: hidden;
        cursor: pointer;
        border: 2px solid #e1e7e4;
        border-radius: 18px;
        background: #fff;
        transition:
            border-color 150ms ease,
            box-shadow 150ms ease;
    }

    .pp-editor .pp-choice-label:hover {
        border-color: #9db8ae;
    }

    .pp-editor .pp-choice-input:checked + .pp-choice-label {
        border-color: #128c7e;
        box-shadow: 0 0 0 3px rgba(18, 140, 126, 0.10);
    }

    .pp-editor .pp-choice-input:focus-visible + .pp-choice-label {
        outline: 3px solid #2563eb;
        outline-offset: 3px;
    }

    .pp-editor .pp-choice-footer {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 14px;
    }

    .pp-editor .pp-choice-copy {
        flex: 1;
        min-width: 0;
    }

    .pp-editor .pp-choice-name {
        display: block;
        color: #17201d;
        font-size: 14px;
        font-weight: 700;
    }

    .pp-editor .pp-choice-description {
        display: block;
        margin-top: 3px;
        color: #6b7773;
        font-size: 12px;
        font-weight: 400;
    }

    .pp-editor .pp-choice-check {
        display: grid;
        place-items: center;
        flex: 0 0 22px;
        width: 22px;
        height: 22px;
        border: 1px solid #ccd6d1;
        border-radius: 50%;
        color: transparent;
        background: #fff;
        font-size: 13px;
    }

    .pp-editor .pp-choice-input:checked
        + .pp-choice-label
        .pp-choice-check {
        color: #fff;
        border-color: #128c7e;
        background: #128c7e;
    }

    .pp-stage {
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 0;
        padding: 22px 14px 16px;
        background: #66716e;
    }

    .pp-scene {
        position: relative;
        width: 100%;
        max-width: 320px;
        padding-top: 28px;
        font-family: Arial, Helvetica, sans-serif;
        font-weight: 400;
    }

    .pp-scene[data-template="simple_card"] {
        padding-top: 0;
    }

    .pp-popup-card {
        width: 100%;
        padding: 38px 18px 18px;
        border: 1px solid rgba(255, 255, 255, 0.65);
        border-radius: 24px;
        background: linear-gradient(
            135deg,
            var(--pp-background-start),
            var(--pp-background-end)
        );
        color: var(--pp-text);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.14);
    }

    .pp-scene[data-template="simple_card"] .pp-popup-card {
        padding-top: 22px;
    }

    .pp-popup-heading {
        margin: 0;
        text-align: center;
        color: var(--pp-text);
        font-size: 21px;
        font-weight: 900;
        line-height: 1.2;
        overflow-wrap: anywhere;
    }

    .pp-popup-body {
        margin-top: 14px;
        padding: 16px 14px;
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.58);
        text-align: center;
    }

    .pp-popup-title {
        color: var(--pp-text);
        font-size: 19px;
        font-weight: 800;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }

    .pp-popup-message {
        margin-top: 9px;
        color: var(--pp-text);
        font-size: 13px;
        line-height: 1.45;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .pp-popup-button {
        display: block;
        margin-top: 16px;
        padding: 12px 16px;
        border: 1px solid #fff;
        border-radius: 999px;
        background: var(--pp-accent);
        color: var(--pp-button-text);
        text-align: center;
        font-size: 16px;
        font-weight: 800;
        line-height: 1.35;
    }

    .pp-dismiss {
        display: grid;
        place-items: center;
        width: 44px;
        height: 44px;
        margin: 14px auto 0;
        padding: 0;
        border: 1px solid rgba(255, 255, 255, 0.75);
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.12);
        color: #fff;
        font: 30px/1 Arial, sans-serif;
        text-decoration: none;
    }

    .pp-gift-anchor {
        position: absolute;
        top: 0;
        left: 50%;
        z-index: 1;
        display: grid;
        place-items: center;
        width: 62px;
        height: 62px;
        transform: translateX(-50%);
    }

    .pp-gift-emoji {
        font-size: 48px;
        line-height: 1;
    }

    .pp-scene[data-template="gift_corner_left"] .pp-gift-anchor {
        left: 18px;
        transform: rotate(-8deg);
    }

    .pp-scene[data-template="gift_corner_right"] .pp-gift-anchor {
        right: 18px;
        left: auto;
        transform: rotate(8deg);
    }

    .pp-scene[data-template="gift_seal"] .pp-gift-anchor {
        border: 3px solid #fff;
        border-radius: 50%;
        background: #f2e9ff;
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.10);
    }

    .pp-package {
        --gift-box: #ffc43a;
        --gift-lid: #ffd76d;
        --gift-ribbon: #e77922;

        position: relative;
        display: block;
        width: 60px;
        height: 60px;
    }

    .pp-scene[data-template="gift_corner_right"] .pp-package {
        --gift-box: #f271a5;
        --gift-lid: #ffa2c4;
        --gift-ribbon: #ffe8a3;
    }

    .pp-scene[data-template="gift_seal"] .pp-package {
        --gift-box: #9561df;
        --gift-lid: #b38af0;
        --gift-ribbon: #ffd56a;

        transform: scale(0.7);
    }

    .pp-gift-box {
        position: absolute;
        top: 24px;
        right: 7px;
        bottom: 1px;
        left: 7px;
        overflow: hidden;
        border-radius: 5px;
        background: var(--gift-box);
        box-shadow: 0 3px 6px rgba(0, 0, 0, 0.12);
    }

    .pp-gift-box::after {
        position: absolute;
        top: 7px;
        right: 0;
        left: 0;
        height: 7px;
        background: var(--gift-ribbon);
        content: "";
    }

    .pp-gift-lid {
        position: absolute;
        top: 18px;
        right: 3px;
        left: 3px;
        height: 12px;
        border-radius: 4px;
        background: var(--gift-lid);
    }

    .pp-gift-ribbon {
        position: absolute;
        top: 18px;
        bottom: 1px;
        left: 25px;
        width: 9px;
        background: var(--gift-ribbon);
    }

    .pp-package::before,
    .pp-package::after {
        position: absolute;
        top: 5px;
        width: 21px;
        height: 13px;
        box-sizing: border-box;
        border: 4px solid var(--gift-ribbon);
        border-radius: 20px;
        content: "";
    }

    .pp-package::before {
        left: 12px;
        transform: rotate(-29deg);
    }

    .pp-package::after {
        right: 12px;
        transform: rotate(29deg);
    }

    .pp-editor .pp-color-grid {
        display: grid;
        grid-template-columns: repeat(
            auto-fit,
            minmax(min(100%, 180px), 1fr)
        );
        gap: 18px;
        margin-top: 24px;
    }

    .pp-editor .pp-color-input {
        display: block;
        width: 100%;
        height: 48px;
        margin-top: 7px;
        padding: 4px;
        cursor: pointer;
    }

    .pp-editor .pp-checkbox-row {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin: 0;
        cursor: pointer;
    }

    .pp-editor .pp-checkbox-row input {
        flex: 0 0 auto;
        width: auto;
        margin-top: 4px;
    }

    .pp-preview-modal {
        width: min(430px, calc(100vw - 32px));
        max-height: calc(100dvh - 40px);
        padding: 0;
        overflow: auto;
        border: 0;
        border-radius: 22px;
        background: #fff;
        color: #17201d;
    }

    .pp-preview-modal::backdrop {
        background: rgba(0, 0, 0, 0.6);
    }

    .pp-preview-modal .pp-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 18px;
    }

    .pp-preview-modal .pp-modal-header h2 {
        margin: 0;
        font-size: 17px;
    }

    .pp-preview-modal .pp-modal-header button {
        padding: 7px 12px;
        border: 1px solid #dce4df;
        border-radius: 9px;
        background: #fff;
        color: #17201d;
        cursor: pointer;
    }

    .pp-preview-modal .pp-stage {
        padding: 28px 24px;
    }

    .pp-preview-modal .pp-dismiss {
        cursor: pointer;
    }

    .pp-preview-modal .pp-modal-note {
        margin: 0;
        padding: 14px 18px;
        color: #6b7773;
        font-size: 12px;
        line-height: 1.5;
    }
</style>

@if (session('success'))
    <div class="success" role="status">
        {{ session('success') }}
    </div>
@endif

@if ($errors->any())
    <div class="error" role="alert">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form
    id="popup-editor"
    class="pp-editor"
    method="POST"
    action="{{ $isEditing
        ? route('admin.popups.update', $popup->id)
        : route('admin.popups.store') }}"
>
    @csrf

    @if ($isEditing)
        @method('PUT')
    @endif

    <section class="card">
        <h2>Popup details</h2>

        <div class="pp-field">
            <label for="popup-name">Internal name</label>

            <input
                id="popup-name"
                name="name"
                type="text"
                maxlength="160"
                required
                value="{{ $value('name') }}"
                placeholder="November referral announcement"
            >

            <p class="pp-help">
                This name appears only in the admin panel.
            </p>
        </div>

        <div class="pp-field">
            <label for="popup-campaign">Linked campaign</label>

            <select
                id="popup-campaign"
                name="reward_campaign_id"
                required
            >
                <option value="">Select a campaign</option>

                @foreach ($campaigns as $campaign)
                    <option
                        value="{{ $campaign->id }}"
                        @selected(
                            (string) $value('reward_campaign_id')
                            === (string) $campaign->id
                        )
                    >
                        {{ $campaign->name }}
                        — {{ strtoupper($campaign->environment) }}
                        — {{ ucfirst($campaign->status) }}
                    </option>
                @endforeach
            </select>

            <p class="pp-help">
                The popup inherits its campaign's environment and dates.
                It appears only while that campaign is active and visible.
                Campaign joining still checks eligibility.
            </p>
        </div>

        <div class="pp-field">
            <label for="popup-heading">Heading</label>

            <input
                id="popup-heading"
                name="heading"
                type="text"
                maxlength="120"
                required
                value="{{ $value('heading', 'Rewards available!') }}"
                placeholder="Congrats! You qualify"
                data-popup-text="heading"
            >
        </div>

        <div class="pp-field">
            <label for="popup-title">Main title</label>

            <input
                id="popup-title"
                name="title"
                type="text"
                maxlength="160"
                required
                value="{{ $value('title') }}"
                placeholder="Refer 2 friends and earn 100MB"
                data-popup-text="title"
            >
        </div>

        <div class="pp-field">
            <label for="popup-message">Description</label>

            <textarea
                id="popup-message"
                name="message"
                rows="4"
                maxlength="2000"
                data-popup-text="message"
            >{{ $value('message') }}</textarea>

            <p class="pp-help">
                Match the description to the linked campaign's actual
                task requirements and available rewards.
            </p>
        </div>
    </section>

    <section class="card">
        <div class="pp-section-heading">
            <div>
                <h2>Choose a design</h2>

                <p class="pp-help">
                    Select a template below.
                    Your text and colours update all previews.
                </p>
            </div>

            <button
                type="button"
                id="popup-open-preview"
            >
                Preview selected design
            </button>
        </div>

        <fieldset class="pp-template-fieldset">
            <legend>Popup template</legend>

            <div class="pp-template-grid">
                @foreach ($templates as $templateKey => $template)
                    <div class="pp-choice">
                        <input
                            class="pp-choice-input"
                            id="template-{{ $templateKey }}"
                            type="radio"
                            name="template"
                            value="{{ $templateKey }}"
                            required
                            @checked($selectedTemplate === $templateKey)
                        >

                        <label
                            class="pp-choice-label"
                            for="template-{{ $templateKey }}"
                        >
                            <span class="pp-stage" aria-hidden="true">
                                <span
                                    class="pp-scene"
                                    data-popup-scene
                                    data-template="{{ $templateKey }}"
                                >
                                    @if ($templateKey !== 'simple_card')
                                        <span class="pp-gift-anchor">
                                            @if ($templateKey === 'gift_card')
                                                <span class="pp-gift-emoji">
                                                    🎁
                                                </span>
                                            @else
                                                <span class="pp-package">
                                                    <span class="pp-gift-box"></span>
                                                    <span class="pp-gift-lid"></span>
                                                    <span class="pp-gift-ribbon"></span>
                                                </span>
                                            @endif
                                        </span>
                                    @endif

                                    <span
                                        class="pp-popup-card"
                                        style="display:block;"
                                    >
                                        <span
                                            class="pp-popup-heading"
                                            style="display:block;"
                                            data-preview-text="heading"
                                        >
                                            {{ $value('heading', 'Rewards available!') }}
                                        </span>

                                        <span
                                            class="pp-popup-body"
                                            style="display:block;"
                                        >
                                            <span
                                                class="pp-popup-title"
                                                style="display:block;"
                                                data-preview-text="title"
                                            >
                                                {{ $value('title')
                                                    ?: 'Complete tasks and earn rewards' }}
                                            </span>

                                            <span
                                                class="pp-popup-message"
                                                style="display:block;"
                                                data-preview-text="message"
                                            >
                                                {{ $value('message')
                                                    ?: 'View the campaign requirements and available rewards.' }}
                                            </span>
                                        </span>

                                        <span class="pp-popup-button">
                                            View rewards
                                        </span>
                                    </span>

                                    <span class="pp-dismiss">×</span>
                                </span>
                            </span>

                            <span class="pp-choice-footer">
                                <span class="pp-choice-copy">
                                    <span class="pp-choice-name">
                                        {{ $template['name'] }}
                                    </span>

                                    <span class="pp-choice-description">
                                        {{ $template['description'] }}
                                    </span>
                                </span>

                                <span
                                    class="pp-choice-check"
                                    aria-hidden="true"
                                >
                                    ✓
                                </span>
                            </span>
                        </label>
                    </div>
                @endforeach
            </div>
        </fieldset>

        @error('template')
            <p class="error">{{ $message }}</p>
        @enderror

        <div class="pp-color-grid">
            @foreach ($colors as $key => $settings)
                @php
                    $colorValue = (string) $value(
                        $key,
                        $settings['default']
                    );

                    if (! preg_match('/^#[0-9a-fA-F]{6}$/', $colorValue)) {
                        $colorValue = $settings['default'];
                    }
                @endphp

                <div>
                    <label for="{{ $key }}">
                        {{ $settings['label'] }}
                    </label>

                    <input
                        class="pp-color-input"
                        id="{{ $key }}"
                        name="{{ $key }}"
                        type="color"
                        value="{{ $colorValue }}"
                        required
                        data-popup-color="{{ $key }}"
                    >
                </div>
            @endforeach
        </div>

        <p class="pp-help">
            Choose contrasting text and background colours.
            Browser previews closely match the Flutter templates;
            emoji appearance and text wrapping may vary by device.
        </p>
    </section>

    <section class="card">
        <h2>Publishing</h2>

        <input type="hidden" name="enabled" value="0">

        <label class="pp-checkbox-row">
            <input
                type="checkbox"
                name="enabled"
                value="1"
                @checked($value('enabled', false))
            >

            <span>Enable this popup</span>
        </label>

        <div class="pp-field" style="margin-top:20px;">
            <label for="popup-priority">Priority</label>

            <input
                id="popup-priority"
                name="priority"
                type="number"
                min="0"
                max="100"
                required
                value="{{ $value('priority', 0) }}"
            >

            <p class="pp-help">
                Higher-priority unseen popups appear first.
                Only one popup appears per check.
            </p>
        </div>

        @if ($isEditing)
            <label class="pp-checkbox-row">
                <input
                    type="checkbox"
                    name="publish_again"
                    value="1"
                    @checked(old('publish_again', false))
                >

                <span>
                    Publish again for users who already saw this popup
                </span>
            </label>

            <p class="pp-help">
                Current revision: {{ $popup->revision }}.
                Changing displayed content also creates a new revision.
            </p>
        @endif

        <div class="actions" style="margin-top:20px;">
            <a href="{{ route('admin.popups.index') }}">
                Back to popups
            </a>

            <button type="submit">
                {{ $isEditing ? 'Save popup changes' : 'Create popup' }}
            </button>
        </div>
    </section>
</form>

<dialog
    id="popup-preview-modal"
    class="pp-preview-modal"
    aria-labelledby="popup-preview-title"
>
    <div class="pp-modal-header">
        <h2 id="popup-preview-title">Popup preview</h2>

        <button
            type="button"
            id="popup-close-preview"
            autofocus
        >
            Close preview
        </button>
    </div>

    <div
        id="popup-preview-stage"
        class="pp-stage"
    ></div>

    <p class="pp-modal-note">
        Preview only. The View rewards button does not navigate here.
    </p>
</dialog>

<script>
(() => {
    const editor = document.getElementById('popup-editor');
    const modal = document.getElementById('popup-preview-modal');
    const previewStage = document.getElementById('popup-preview-stage');
    const openButton = document.getElementById('popup-open-preview');
    const closeButton = document.getElementById('popup-close-preview');

    if (!editor || !modal || !previewStage) return;

    const defaults = {
        heading: 'Rewards available!',
        title: 'Complete tasks and earn rewards',
        message: 'View the campaign requirements and available rewards.',
    };

    const colorProperties = {
        background_start: '--pp-background-start',
        background_end: '--pp-background-end',
        accent_color: '--pp-accent',
        text_color: '--pp-text',
        button_text_color: '--pp-button-text',
    };

    function updateTextPreviews() {
        editor.querySelectorAll('[data-popup-text]').forEach((input) => {
            const key = input.dataset.popupText;
            const text = input.value.trim() || defaults[key];

            [editor, modal].forEach((root) => {
                root.querySelectorAll(
                    `[data-preview-text="${key}"]`
                ).forEach((element) => {
                    // Treat admin-entered content as text, not HTML.
                    element.textContent = text;
                });
            });
        });
    }

    function updateColorPreviews() {
        editor.querySelectorAll('[data-popup-color]').forEach((input) => {
            const property = colorProperties[input.dataset.popupColor];
            const color = input.value;

            if (!property || !/^#[0-9a-fA-F]{6}$/.test(color)) {
                return;
            }

            editor.style.setProperty(property, color);
            modal.style.setProperty(property, color);
        });
    }

    function selectedRadio() {
        return editor.querySelector(
            'input[name="template"]:checked'
        );
    }

    function updatePreviewButton() {
        openButton.disabled = !selectedRadio();
    }

    function renderLargePreview() {
        const radio = selectedRadio();

        if (!radio) return;

        const choice = radio.closest('.pp-choice');
        const scene = choice.querySelector('[data-popup-scene]');

        if (!scene) return;

        const clone = scene.cloneNode(true);

        // Turn the decorative × into a real close button
        // inside the larger preview.
        const decorativeClose = clone.querySelector('.pp-dismiss');

        if (decorativeClose) {
            const actualClose = document.createElement('button');

            actualClose.type = 'button';
            actualClose.className = 'pp-dismiss';
            actualClose.setAttribute('aria-label', 'Close popup preview');
            actualClose.textContent = '×';

            actualClose.addEventListener('click', () => {
                modal.close();
            });

            decorativeClose.replaceWith(actualClose);
        }

        previewStage.replaceChildren(clone);

        updateTextPreviews();
        updateColorPreviews();
    }

    editor.addEventListener('input', (event) => {
        if (event.target.matches('[data-popup-text]')) {
            updateTextPreviews();
        }

        if (event.target.matches('[data-popup-color]')) {
            updateColorPreviews();
        }
    });

    editor.addEventListener('change', (event) => {
        if (event.target.matches('input[name="template"]')) {
            updatePreviewButton();

            if (modal.open) {
                renderLargePreview();
            }
        }
    });

    openButton.addEventListener('click', () => {
        if (!selectedRadio()) return;

        renderLargePreview();
        modal.showModal();
    });

    closeButton.addEventListener('click', () => {
        modal.close();
    });

    updateTextPreviews();
    updateColorPreviews();
    updatePreviewButton();
})();
</script>