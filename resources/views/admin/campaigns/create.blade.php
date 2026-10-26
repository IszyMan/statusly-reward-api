@extends('admin.layout')

@section('title', 'Create campaign')

@section('content')
    <h1>Create campaign</h1>
    <p class="muted intro">
        Set the tasks, choose network rewards, and plan your budget.
    </p>

    <div class="notice">
        <strong>
            {{ ucfirst($providerEnvironment) }} environment.
        </strong>

        This form saves a draft and does not purchase data.

        @if ($providerEnvironment === 'sandbox')
            Sandbox transactions do not deliver real data.
        @else
            Select verified live bundles before activating a campaign.
        @endif
    </div>

    @if ($errors->any())
        <div class="error" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.campaigns.store') }}" id="campaign-form">
        @csrf

        <section class="card">
            <h2>Campaign details</h2>

            <label for="name">Campaign name</label>
            <input
                id="name"
                name="name"
                value="{{ old('name') }}"
                maxlength="150"
                placeholder="For example: Statusly Launch Rewards"
                required
            >

            <label for="description">Description</label>
            <textarea
                id="description"
                name="description"
                rows="3"
                maxlength="5000"
            >{{ old('description') }}</textarea>

            <label for="audience">Who can join?</label>
            <select id="audience" name="audience">
                <option value="all" @selected(old('audience', 'all') === 'all')>
                    New and existing users
                </option>
                <option value="new_devices" @selected(old('audience') === 'new_devices')>
                    New users only
                </option>
            </select>

            <div class="row">
                <div>
                    <label for="budget">Campaign budget (₦)</label>
                    <input
                        id="budget"
                        name="budget_naira"
                        type="number"
                        min="0.01"
                        max="999999999.99"
                        step="0.01"
                        value="{{ old('budget_naira', '100000') }}"
                        required
                    >
                </div>

                <div>
                    <label for="participant-limit">Participant limit</label>
                    <input
                        id="participant-limit"
                        name="participant_limit"
                        type="number"
                        min="1"
                        max="2147483647"
                        step="1"
                        value="{{ old('participant_limit', 500) }}"
                        required
                    >
                </div>
            </div>

            <small class="help">
                This is your chosen campaign limit.
                It is not a permanent limit on the app.
            </small>


            <h3 style="margin-top: 24px;">Referral credit limits</h3>

            <p class="help">
                Control how many qualified referrals each inviter can receive
                credit for during a selected period. Referrals above the limit
                remain queued for later processing.
            </p>

            <div class="row">
                <div>
                    <label for="referral-credit-limit">
                        Maximum credited referrals
                    </label>
                    <input
                        id="referral-credit-limit"
                        name="referral_credit_limit"
                        type="number"
                        min="1"
                        max="100000"
                        step="1"
                        value="{{ old('referral_credit_limit', 15) }}"
                        required
                    >
                </div>

                <div>
                    <label for="referral-credit-period">
                        Limit period
                    </label>
                    <select
                        id="referral-credit-period"
                        name="referral_credit_period"
                        required
                    >
                        <option
                            value="daily"
                            @selected(old('referral_credit_period', 'daily') === 'daily')
                        >
                            Per day
                        </option>
                        <option
                            value="weekly"
                            @selected(old('referral_credit_period') === 'weekly')
                        >
                            Per week
                        </option>
                    </select>
                </div>
            </div>


            <details style="margin-top: 20px;">
                <summary>Optional dates and terms</summary>

                <p class="help">
                    Enter dates in Lagos time. Blank dates mean no scheduled
                    start or deadline.
                </p>

                <label for="starts-at">Start date</label>
                <input
                    id="starts-at"
                    name="starts_at"
                    type="datetime-local"
                    value="{{ old('starts_at') }}"
                >

                <label for="enrollment-closes-at">Close new enrolments</label>
                <input
                    id="enrollment-closes-at"
                    name="enrollment_closes_at"
                    type="datetime-local"
                    value="{{ old('enrollment_closes_at') }}"
                >

                <label for="tasks-close-at">Task completion deadline</label>
                <input
                    id="tasks-close-at"
                    name="tasks_close_at"
                    type="datetime-local"
                    value="{{ old('tasks_close_at') }}"
                >

                <label for="terms">Campaign terms</label>
                <textarea
                    id="terms"
                    name="terms"
                    rows="4"
                    maxlength="10000"
                >{{ old('terms') }}</textarea>
            </details>
        </section>

        <div class="page-heading">
            <h2>Tasks and rewards</h2>

            <button type="button" class="secondary" id="add-task">
                Add another task
            </button>
        </div>

        <div id="tasks"></div>

        <section class="card">
            <h2>Budget summary</h2>

            <div class="estimate" id="budget-estimate" aria-live="polite"></div>

            <p class="help">
                For each participant, reserve the cost limit of every task.
                One task earns one reward, redeemed on one supported network.
                Network prices are not added together.
            </p>

            <div class="actions" style="margin-top: 20px;">
                <a href="{{ route('admin.campaigns.index') }}">Cancel</a>

                <button type="submit" id="save-campaign">
                    Save draft campaign
                </button>
            </div>
        </section>
    </form>

    <template id="task-template">
        <section class="card task-card" data-task>
            <div class="task-heading">
                <h3>Reward task</h3>

                <button type="button" class="danger" data-remove>
                    Remove task
                </button>
            </div>

            <label for="task-__INDEX__-title">Task title</label>
            <input
                id="task-__INDEX__-title"
                name="tasks[__INDEX__][title]"
                data-field="title"
                maxlength="150"
                placeholder="For example: Save 10 statuses"
                required
            >

            <div class="row">
                <div>
                    <label for="task-__INDEX__-type">Task type</label>
                    <select
                        id="task-__INDEX__-type"
                        name="tasks[__INDEX__][type]"
                        data-field="type"
                    >
                        <option value="save_media">Save photos or videos</option>
                        <option value="referrals">Refer new users</option>
                    </select>
                </div>

                <div>
                    <label for="task-__INDEX__-target">Required number</label>
                    <input
                        id="task-__INDEX__-target"
                        name="tasks[__INDEX__][target_count]"
                        data-field="target_count"
                        type="number"
                        min="1"
                        max="1000000"
                        value="10"
                        required
                    >
                </div>
            </div>



            <div data-frequency-fields>
                <label for="task-__INDEX__-frequency-days">
                    How often can this task earn a reward?
                </label>

                <select
                    id="task-__INDEX__-frequency-days"
                    name="tasks[__INDEX__][frequency_days]"
                    data-field="frequency_days"
                >
                    <option value="once">Once per participant</option>
                    <option value="1">Every day</option>
                    <option value="3">Every 3 days</option>
                    <option value="7">Every week</option>
                </select>

                <small class="help">
                    Each new cycle requires fresh qualifying saves.
                    Previous saves must not count towards the next cycle.
                </small>
            </div>


            <div class="row">
                <div>
                    <label for="task-__INDEX__-media">Eligible saved media</label>
                    <select
                        id="task-__INDEX__-media"
                        name="tasks[__INDEX__][media_type]"
                        data-field="media_type"
                    >
                        <option value="all">Photos and videos</option>
                        <option value="images">Photos only</option>
                        <option value="videos">Videos only</option>
                    </select>
                </div>

                <div>
                    <label for="task-__INDEX__-source">WhatsApp source</label>
                    <select
                        id="task-__INDEX__-source"
                        name="tasks[__INDEX__][source]"
                        data-field="source"
                    >
                        <option value="all">WhatsApp and Business</option>
                        <option value="whatsapp">WhatsApp only</option>
                        <option value="business">WhatsApp Business only</option>
                    </select>
                </div>
            </div>

            <div data-referral-fields hidden>
                <label for="task-__INDEX__-referral-saves">
                    Saves required from each invited user
                </label>

                <input
                    id="task-__INDEX__-referral-saves"
                    name="tasks[__INDEX__][referral_saves]"
                    data-field="referral_saves"
                    type="number"
                    min="1"
                    max="1000"
                    value="2"
                    required
                >

                <small class="help">
                    The invited user must be new and complete this many
                    distinct saves before the referral counts.
                    Media and source settings above apply to those saves.
                </small>
            </div>

            <label for="task-__INDEX__-ceiling">
                Maximum cost of one reward (₦)
            </label>

            <input
                id="task-__INDEX__-ceiling"
                name="tasks[__INDEX__][reward_ceiling_naira]"
                data-field="reward_ceiling_naira"
                type="number"
                min="0.01"
                max="999999999.99"
                step="0.01"
                value="100"
                required
            >

            <small class="help">
                Must cover the most expensive network bundle selected below.
                Increase this for larger rewards.
            </small>

            <h3 style="margin-top: 24px;">Network rewards</h3>

            <p class="help">
                Choose a bundle for each network you want to support.
                Leave a network unselected to exclude it from this task.
                Choose general data if that is what you intend to offer;
                some catalogue plans are voice or app-specific bundles.
            </p>

            <div class="network-grid">
                @foreach (['mtn' => 'MTN', 'airtel' => 'Airtel', 'glo' => 'Glo'] as $network => $label)
                    <div class="network-card">
                        <label for="task-__INDEX__-{{ $network }}">
                            {{ $label }} bundle
                        </label>

                        <select
                            id="task-__INDEX__-{{ $network }}"
                            name="tasks[__INDEX__][bundles][{{ $network }}]"
                            data-network="{{ $network }}"
                        >
                            <option value="">Loading catalogue…</option>
                        </select>

                        <button
                            type="button"
                            class="secondary"
                            data-reload="{{ $network }}"
                            style="margin-top: 10px;"
                        >
                            Refresh plans
                        </button>

                        <small
                            class="help"
                            data-network-message="{{ $network }}"
                            aria-live="polite"
                        ></small>

                        <label for="task-__INDEX__-{{ $network }}-validity">
                            Validity label, optional
                        </label>

                        <input
                            id="task-__INDEX__-{{ $network }}-validity"
                            name="tasks[__INDEX__][validity][{{ $network }}]"
                            data-validity="{{ $network }}"
                            maxlength="120"
                            placeholder="For example: 14 days"
                        >

                        <small class="help">
                            Copy the plan's actual validity.
                            The full provider plan name is also saved.
                        </small>
                    </div>
                @endforeach
            </div>
        </section>
    </template>
@endsection

@push('scripts')
    <script>
        (() => {
            const initialTasks = @js(old('tasks', [
                [
                    'title' => 'Save 10 statuses',
                    'type' => 'save_media',
                    'target_count' => 10,
                    'media_type' => 'all',
                    'frequency_days' => 'once',
                    'source' => 'all',
                    'referral_saves' => 2,
                    'reward_ceiling_naira' => '100',
                    'bundles' => [
                        'mtn' => 'mtn-10mb-100',
                        'airtel' => 'airt-100',
                        'glo' => 'glo-dg-70',
                    ],
                ],
                [
                    'title' => 'Refer 3 new users',
                    'type' => 'referrals',
                    'target_count' => 3,
                    'media_type' => 'all',
                    'source' => 'all',
                    'referral_saves' => 2,
                    'reward_ceiling_naira' => '100',
                    'bundles' => [
                        'mtn' => 'mtn-10mb-100',
                        'airtel' => 'airt-100',
                        'glo' => 'glo-dg-70',
                    ],
                ],
            ]));

            const catalogueUrls = @js([
                'mtn' => route('admin.bundles.index', ['network' => 'mtn']),
                'airtel' => route('admin.bundles.index', ['network' => 'airtel']),
                'glo' => route('admin.bundles.index', ['network' => 'glo']),
            ]);

            const tasksContainer = document.getElementById('tasks');
            const template = document.getElementById('task-template');
            const form = document.getElementById('campaign-form');
            const budgetInput = document.getElementById('budget');
            const limitInput = document.getElementById('participant-limit');
            const estimate = document.getElementById('budget-estimate');
            const addButton = document.getElementById('add-task');
            const saveButton = document.getElementById('save-campaign');

            const catalogues = {};
            const requests = {};
            let nextIndex = 0;

            const currency = new Intl.NumberFormat('en-NG', {
                style: 'currency',
                currency: 'NGN',
                maximumFractionDigits: 2,
            });

            function updateEstimate() {
                let perParticipant = 0;

                tasksContainer.querySelectorAll(
                    '[data-field="reward_ceiling_naira"]'
                ).forEach((input) => {
                    perParticipant += Number(input.value) || 0;
                });

                const limit = Number(limitInput.value) || 0;
                const required = perParticipant * limit;
                const budget = Number(budgetInput.value) || 0;

                estimate.textContent =
                    `${currency.format(perParticipant)} reserved per participant. ` +
                    `${currency.format(required)} needed for ` +
                    `${limit.toLocaleString()} participants. ` +
                    (budget >= required
                        ? 'Your budget covers this limit.'
                        : 'Increase the budget or reduce the participant limit.');

                addButton.disabled =
                    tasksContainer.querySelectorAll('[data-task]').length >= 10;
            }

            async function fetchCatalogue(network, refresh = false) {
                if (requests[network]) {
                    return requests[network];
                }

                if (!refresh && catalogues[network]) {
                    return catalogues[network];
                }

                requests[network] = (async () => {
                    const response = await fetch(catalogueUrls[network], {
                        headers: {
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });

                    const payload = await response.json();

                    if (!response.ok || !Array.isArray(payload.plans)) {
                        throw new Error(
                            payload.message || 'Could not load plans.'
                        );
                    }

                    catalogues[network] = payload.plans;

                    return payload.plans;
                })();

                try {
                    return await requests[network];
                } finally {
                    delete requests[network];
                }
            }

            async function loadOptions(
                card,
                network,
                selectedCode = '',
                refresh = false
            ) {
                const select = card.querySelector(
                    `[data-network="${network}"]`
                );

                const message = card.querySelector(
                    `[data-network-message="${network}"]`
                );

                const reload = card.querySelector(
                    `[data-reload="${network}"]`
                );

                select.dataset.selection = selectedCode;
                select.disabled = true;
                reload.disabled = true;
                message.textContent = 'Loading provider plans…';

                try {
                    const plans = await fetchCatalogue(network, refresh);

                    select.replaceChildren(
                        new Option('Not offered on this network', '')
                    );

                    for (const plan of plans) {
                        const label =
                            `${plan.name} — ` +
                            currency.format(plan.price_kobo / 100);

                        select.add(new Option(label, plan.code));
                    }

                    select.value = selectedCode;

                    if (selectedCode && select.value !== selectedCode) {
                        message.textContent =
                            'The previous selection is unavailable. ' +
                            'Choose another plan.';
                    } else {
                        message.textContent = 'Provider catalogue loaded.';
                    }

                    select.dataset.selection = select.value;
                } catch (error) {
                    select.replaceChildren(
                        new Option('Catalogue unavailable — retry', '')
                    );

                    message.textContent =
                        'Could not load plans. Click Refresh plans.';
                } finally {
                    select.disabled = false;
                    reload.disabled = false;
                }
            }

            function addTask(values = {}) {
                if (tasksContainer.querySelectorAll('[data-task]').length >= 10) {
                    return;
                }

                const index = nextIndex++;

                const holder = document.createElement('div');

                // Only our static template is inserted as HTML.
                // Provider names are added with Option(), not innerHTML.
                holder.innerHTML = template.innerHTML.replaceAll(
                    '__INDEX__',
                    String(index)
                );

                const card = holder.firstElementChild;

                for (const input of card.querySelectorAll('[data-field]')) {
                    const key = input.dataset.field;

                    if (values[key] !== undefined && values[key] !== null) {
                        input.value = values[key];
                    }
                }

                
                const typeInput = card.querySelector('[data-field="type"]');
                const referralFields = card.querySelector('[data-referral-fields]');
                const referralSavesInput = card.querySelector('[data-field="referral_saves"]');
                const frequencyFields = card.querySelector('[data-frequency-fields]');

                function updateTaskType() {
                    const isReferralTask = typeInput.value === 'referrals';

                    referralFields.hidden = !isReferralTask;
                    referralSavesInput.required = isReferralTask;
                    frequencyFields.hidden = isReferralTask;
                }

                typeInput.addEventListener('change', updateTaskType);
                updateTaskType();

                card.querySelector('[data-remove]').addEventListener(
                    'click',
                    () => {
                        if (
                            tasksContainer.querySelectorAll('[data-task]')
                                .length === 1
                        ) {
                            alert('Keep at least one task.');
                            return;
                        }

                        card.remove();
                        updateEstimate();
                    }
                );

                for (const network of ['mtn', 'airtel', 'glo']) {
                    const select = card.querySelector(
                        `[data-network="${network}"]`
                    );

                    const validity = card.querySelector(
                        `[data-validity="${network}"]`
                    );

                    validity.value = values.validity?.[network] || '';

                    select.addEventListener('change', () => {
                        select.dataset.selection = select.value;
                    });

                    card.querySelector(
                        `[data-reload="${network}"]`
                    ).addEventListener('click', () => {
                        loadOptions(
                            card,
                            network,
                            select.dataset.selection || select.value,
                            true
                        );
                    });
                }

                tasksContainer.appendChild(card);

                for (const network of ['mtn', 'airtel', 'glo']) {
                    loadOptions(
                        card,
                        network,
                        values.bundles?.[network] || ''
                    );
                }

                updateEstimate();
            }

            addButton.addEventListener('click', () => addTask());

            tasksContainer.addEventListener('input', updateEstimate);
            budgetInput.addEventListener('input', updateEstimate);
            limitInput.addEventListener('input', updateEstimate);

            form.addEventListener('submit', (event) => {
                const cards = tasksContainer.querySelectorAll('[data-task]');

                for (const card of cards) {
                    const selects = [
                        ...card.querySelectorAll('[data-network]'),
                    ];

                    if (selects.some((select) => select.disabled)) {
                        event.preventDefault();
                        alert('Wait for the catalogues to finish loading.');
                        return;
                    }

                    if (!selects.some((select) => select.value)) {
                        event.preventDefault();
                        alert('Choose at least one network bundle per task.');
                        return;
                    }
                }

                saveButton.disabled = true;
                saveButton.textContent = 'Verifying bundles and saving…';
            });

            for (const task of Object.values(initialTasks)) {
                addTask(task);
            }

            window.addEventListener('pageshow', () => {
                saveButton.disabled = false;
                saveButton.textContent = 'Save draft campaign';
            });
        })();
    </script>
@endpush