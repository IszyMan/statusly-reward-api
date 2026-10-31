<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\VtpassService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use App\Services\RewardCampaignService;

class CampaignController extends Controller
{
    private const NETWORKS = [
        'mtn' => 'mtn-data',
        'airtel' => 'airtel-data',
        'glo' => 'glo-data',
    ];

    public function index()
    {
        $campaigns = DB::table('reward_campaigns')
            ->orderByDesc('id')
            ->paginate(15);

        return view('admin.campaigns.index', compact('campaigns'));
    }

    public function create()
    {
        $providerEnvironment = app(VtpassService::class)->environment();

        return view('admin.campaigns.create', [
            'providerEnvironment' => $providerEnvironment,
        ]);
    }


    public function edit(int $campaign)
    {
        $record = DB::table('reward_campaigns')
            ->where('id', $campaign)
            ->first();

        abort_if(! $record, 404);

        $hasParticipants = DB::table('reward_participants')
            ->where('reward_campaign_id', $campaign)
            ->exists();

        $tasks = DB::table('reward_campaign_tasks')
            ->where('reward_campaign_id', $campaign)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $formTasks = [];

        foreach ($tasks as $task) {
            $bundles = DB::table('reward_task_bundles')
                ->where('reward_campaign_task_id', $task->id)
                ->get()
                ->keyBy('network');

            $qualificationRules = is_array($task->qualification_rules)
                ? $task->qualification_rules
                : json_decode($task->qualification_rules ?? '{}', true);

            $formTasks[] = [
                'id' => $task->id,
                'title' => $task->title,
                'type' => $task->type,
                'target_count' => $task->target_count,
                'media_type' => $task->media_type,
                'source' => $task->source,
                'frequency_days' => $task->frequency_days ?? 'once',
                'referral_saves' => $qualificationRules['required_unique_saves'] ?? 2,
                'reward_ceiling_naira' => number_format(
                    $task->max_reward_cost_kobo / 100,
                    2,
                    '.',
                    ''
                ),
                'bundles' => [
                    'mtn' => $bundles->get('mtn')->variation_code ?? '',
                    'airtel' => $bundles->get('airtel')->variation_code ?? '',
                    'glo' => $bundles->get('glo')->variation_code ?? '',
                ],
                'validity' => [
                    'mtn' => $bundles->get('mtn')->validity_label ?? '',
                    'airtel' => $bundles->get('airtel')->validity_label ?? '',
                    'glo' => $bundles->get('glo')->validity_label ?? '',
                ],
            ];
        }

        $localDate = function ($value) {
            return $value
                ? CarbonImmutable::parse($value, 'UTC')
                    ->setTimezone('Africa/Lagos')
                    ->format('Y-m-d\TH:i')
                : '';
        };

        $record->budget_naira = number_format(
            $record->budget_kobo / 100,
            2,
            '.',
            ''
        );

        $record->starts_at_local = $localDate($record->starts_at);
        $record->enrollment_closes_at_local = $localDate(
            $record->enrollment_closes_at
        );
        $record->tasks_close_at_local = $localDate($record->tasks_close_at);

        return view('admin.campaigns.edit', [
            'campaign' => $record,
            'formTasks' => $formTasks,
            'hasParticipants' => $hasParticipants,
            'providerEnvironment' => app(VtpassService::class)->environment(),
        ]);
    }

    public function bundles(string $network, VtpassService $vtpass)
    {
        try {
            $this->ensureProviderConfigured();

            return response()->json([
                'plans' => $this->catalogue($network, $vtpass),
            ])->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Could not load the '
                    .config('vtpass.environment')
                    .' catalogue. Check your connection and provider '
                    .'configuration, then try again.',
            ], 503);
        }
    }

    public function store(Request $request, VtpassService $vtpass)
    {
        $this->ensureProviderConfigured();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:10000'],

            'budget_naira' => [
                'required',
                'regex:/^\d{1,9}(?:\.\d{1,2})?$/',
            ],

            'participant_limit' => [
                'required',
                'integer',
                'min:1',
                'max:2147483647',
            ],

            'audience' => ['required', 'in:all,new_devices'],

            'referral_credit_limit' => [
                'required',
                'integer',
                'min:1',
                'max:100000',
            ],

            'referral_credit_period' => [
                'required',
                'in:daily,weekly',
            ],

            'starts_at' => [
                'nullable',
                'date_format:Y-m-d\TH:i',
            ],

            'enrollment_closes_at' => [
                'nullable',
                'date_format:Y-m-d\TH:i',
            ],

            'tasks_close_at' => [
                'nullable',
                'date_format:Y-m-d\TH:i',
            ],

            'tasks' => ['required', 'array', 'min:1', 'max:10'],
            'tasks.*' => [
                'array:title,type,target_count,media_type,source,'
                .'referral_saves,frequency_days,reward_ceiling_naira,'
                .'bundles,validity',
            ],

            'tasks.*.frequency_days' => [
                'nullable',
                'in:once,1,3,7',
            ],

            'tasks.*.title' => ['required', 'string', 'max:150'],
            'tasks.*.type' => ['required', 'in:save_media,referrals'],
            'tasks.*.target_count' => [
                'required',
                'integer',
                'min:1',
                'max:1000000',
            ],

            'tasks.*.media_type' => [
                'required',
                'in:all,images,videos',
            ],

            'tasks.*.source' => [
                'required',
                'in:all,whatsapp,business',
            ],

            'tasks.*.referral_saves' => [
                'nullable',
                'integer',
                'min:1',
                'max:1000',
            ],

            'tasks.*.reward_ceiling_naira' => [
                'required',
                'regex:/^\d{1,9}(?:\.\d{1,2})?$/',
            ],

            'tasks.*.bundles' => ['required', 'array:mtn,airtel,glo'],
            'tasks.*.bundles.*' => ['nullable', 'string', 'max:120'],

            'tasks.*.validity' => [
                'nullable',
                'array:mtn,airtel,glo',
            ],

            'tasks.*.validity.*' => [
                'nullable',
                'string',
                'max:120',
            ],
        ]);

        $startsAt = $this->date($data['starts_at'] ?? null);
        $enrollmentClosesAt = $this->date(
            $data['enrollment_closes_at'] ?? null
        );
        $tasksCloseAt = $this->date(
            $data['tasks_close_at'] ?? null
        );

        if ($startsAt && $enrollmentClosesAt
            && $enrollmentClosesAt->lessThanOrEqualTo($startsAt)) {
            $this->invalid(
                'enrollment_closes_at',
                'Enrolment must close after the campaign starts.'
            );
        }

        if ($startsAt && $tasksCloseAt
            && $tasksCloseAt->lessThanOrEqualTo($startsAt)) {
            $this->invalid(
                'tasks_close_at',
                'The task deadline must be after the campaign starts.'
            );
        }

        if ($enrollmentClosesAt && $tasksCloseAt
            && $tasksCloseAt->lessThan($enrollmentClosesAt)) {
            $this->invalid(
                'tasks_close_at',
                'The task deadline cannot be before enrolment closes.'
            );
        }

        $budgetKobo = $this->money($data['budget_naira']);
        $reservationPerParticipant = 0;
        $catalogues = [];
        $preparedTasks = [];

        foreach ($data['tasks'] as $index => $task) {
            if (
                $task['type'] === 'referrals'
                && (
                    ! isset($task['referral_saves'])
                    || (int) $task['referral_saves'] < 1
                    || (int) $task['referral_saves'] > 1000
                )
            ) {
                $this->invalid(
                    "tasks.{$index}.referral_saves",
                    'Referral tasks must require between 1 and 1000 qualifying saves.'
                );
            }
            $ceilingKobo = $this->money(
                $task['reward_ceiling_naira']
            );

            if ($ceilingKobo < 1) {
                $this->invalid(
                    "tasks.{$index}.reward_ceiling_naira",
                    'The reward cost limit must be greater than zero.'
                );
            }

            $selectedBundles = [];

            foreach (self::NETWORKS as $network => $serviceId) {
                $code = $task['bundles'][$network] ?? null;

                if (! $code) {
                    continue;
                }

                if (! array_key_exists($network, $catalogues)) {
                    try {
                        $catalogues[$network] = $this->catalogue(
                            $network,
                            $vtpass
                        );
                    } catch (Throwable $exception) {
                        $this->invalid(
                            "tasks.{$index}.bundles.{$network}",
                            'Could not verify the '
                            .strtoupper($network)
                            .' catalogue. No campaign was saved. '
                            .'Please try again.'
                        );
                    }
                }

                $matches = array_values(array_filter(
                    $catalogues[$network],
                    fn (array $plan) => $plan['code'] === $code
                ));

                if (count($matches) !== 1) {
                    $this->invalid(
                        "tasks.{$index}.bundles.{$network}",
                        'This bundle is unavailable or has an ambiguous '
                        .'variation code. Refresh and select another plan.'
                    );
                }

                $plan = $matches[0];

                if ($plan['price_kobo'] > $ceilingKobo) {
                    $this->invalid(
                        "tasks.{$index}.reward_ceiling_naira",
                        'The reward cost limit must cover every selected '
                        .'network bundle.'
                    );
                }

                $bundleDetails = $this->bundleDetails($plan['name']);

                $selectedBundles[] = [
                    'network' => $network,
                    'service_id' => $serviceId,
                    'variation_code' => $plan['code'],
                    'bundle_name' => $plan['name'],
                    'validity_label' => $bundleDetails['validity_label'],
                    'data_amount_mb' => $bundleDetails['data_amount_mb'],
                    'listed_price_kobo' => $plan['price_kobo'],
                    'enabled' => true,
                    'catalogue_checked_at' => now(),
                ];
            }

            if ($selectedBundles === []) {
                $this->invalid(
                    "tasks.{$index}.bundles",
                    'Choose at least one network bundle for each task.'
                );
            }

            $reservationPerParticipant += $ceilingKobo;

            $preparedTasks[] = [
                'title' => $task['title'],
                'type' => $task['type'],
                'target_count' => (int) $task['target_count'],
                'media_type' => $task['media_type'],
                'source' => $task['source'],
                'max_reward_cost_kobo' => $ceilingKobo,

                'qualification_rules' => $task['type'] === 'referrals'
                    ? json_encode([
                        'requires_new_device' => true,
                        'required_unique_saves' => (int) $task['referral_saves'],
                    ], JSON_THROW_ON_ERROR)
                    : null,

                'bundles' => $selectedBundles,
                'frequency_days' => $task['type'] === 'save_media'
                    && ($task['frequency_days'] ?? 'once') !== 'once'
                    ? (int) $task['frequency_days']
                    : null,
            ];
        }

        $requiredBudget = $reservationPerParticipant
            * (int) $data['participant_limit'];

        if ($budgetKobo < $requiredBudget) {
            $this->invalid(
                'budget_naira',
                'For this participant limit and these reward cost limits, '
                .'the budget must be at least ₦'
                .number_format($requiredBudget / 100, 2)
                .'. Increase the budget or reduce the participant limit.'
            );
        }

        DB::transaction(function () use (
            $data,
            $budgetKobo,
            $startsAt,
            $enrollmentClosesAt,
            $tasksCloseAt,
            $preparedTasks
        ) {
            $now = now();

            $campaignId = DB::table('reward_campaigns')->insertGetId([
                'code' => 'campaign-'.Str::uuid(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'terms' => $data['terms'] ?? null,
                'environment' => config('vtpass.environment'),
                'status' => 'draft',
                'audience' => $data['audience'],                
                'referral_credit_limit' => (int) $data['referral_credit_limit'],
                'referral_credit_period' => $data['referral_credit_period'],
                'budget_kobo' => $budgetKobo,
                'allocated_budget_kobo' => 0,
                'participant_limit' => $data['participant_limit'],
                'enrolled_count' => 0,
                'starts_at' => $startsAt,
                'enrollment_closes_at' => $enrollmentClosesAt,
                'tasks_close_at' => $tasksCloseAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($preparedTasks as $index => $task) {
                $bundles = $task['bundles'];
                unset($task['bundles']);

                $taskId = DB::table('reward_campaign_tasks')
                    ->insertGetId(array_merge($task, [
                        'reward_campaign_id' => $campaignId,
                        'code' => 'task-'.($index + 1),
                        'sort_order' => $index + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));

                foreach ($bundles as $bundle) {
                    DB::table('reward_task_bundles')->insert(
                        array_merge($bundle, [
                            'reward_campaign_task_id' => $taskId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])
                    );
                }
            }
        });

        return redirect()
            ->route('admin.campaigns.index')
            ->with('success','Campaign saved as a '.config('vtpass.environment').' draft.');
    }


    public function update(Request $request, int $campaign, VtpassService $vtpass)
    {
        $this->ensureProviderConfigured();

        $record = DB::table('reward_campaigns')
            ->where('id', $campaign)
            ->first();

        abort_if(! $record, 404);

        $hasParticipants = DB::table('reward_participants')
            ->where('reward_campaign_id', $campaign)
            ->exists();

        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'budget_naira' => [
                'required',
                'regex:/^\d{1,9}(?:\.\d{1,2})?$/',
            ],
            'participant_limit' => [
                'required', 'integer', 'min:1', 'max:2147483647',
            ],
            'audience' => ['required', 'in:all,new_devices'],
            'referral_credit_limit' => [
                'required', 'integer', 'min:1', 'max:100000',
            ],
            'referral_credit_period' => ['required', 'in:daily,weekly'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'enrollment_closes_at' => [
                'nullable', 'date_format:Y-m-d\TH:i',
            ],
            'tasks_close_at' => [
                'nullable', 'date_format:Y-m-d\TH:i',
            ],
        ];

        if (! $hasParticipants) {
            $rules += [
                'tasks' => ['required', 'array', 'min:1', 'max:10'],
                'tasks.*' => [
                    'array:title,type,target_count,media_type,source,'
                    .'referral_saves,frequency_days,reward_ceiling_naira,'
                    .'bundles,validity',
                ],
                'tasks.*.title' => ['required', 'string', 'max:150'],
                'tasks.*.type' => ['required', 'in:save_media,referrals'],
                'tasks.*.target_count' => [
                    'required', 'integer', 'min:1', 'max:1000000',
                ],
                'tasks.*.media_type' => ['required', 'in:all,images,videos'],
                'tasks.*.source' => [
                    'required', 'in:all,whatsapp,business',
                ],
                'tasks.*.referral_saves' => [
                    'nullable', 'integer', 'min:1', 'max:1000',
                ],
                'tasks.*.frequency_days' => ['nullable', 'in:once,1,3,7'],
                'tasks.*.reward_ceiling_naira' => [
                    'required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/',
                ],
                'tasks.*.bundles' => [
                    'required', 'array:mtn,airtel,glo',
                ],
                'tasks.*.bundles.*' => ['nullable', 'string', 'max:120'],
                'tasks.*.validity' => ['nullable', 'array:mtn,airtel,glo'],
                'tasks.*.validity.*' => ['nullable', 'string', 'max:120'],
            ];
        }

        if ($hasParticipants) {
            $rules['tasks'] = ['required', 'array', 'min:1', 'max:10'];

            $rules['tasks.*'] = [
                'required',
                'array:task_id,title',
            ];

            $rules['tasks.*.task_id'] = [
                'required',
                'integer',
                'min:1',
            ];

            $rules['tasks.*.title'] = [
                'required',
                'string',
                'max:150',
            ];
        }

        $data = $request->validate($rules);

        $startsAt = $this->date($data['starts_at'] ?? null);
        $enrollmentClosesAt = $this->date(
            $data['enrollment_closes_at'] ?? null
        );
        $tasksCloseAt = $this->date($data['tasks_close_at'] ?? null);

        if ($startsAt && $enrollmentClosesAt
            && $enrollmentClosesAt->lessThanOrEqualTo($startsAt)) {
            $this->invalid(
                'enrollment_closes_at',
                'Enrolment must close after the campaign starts.'
            );
        }

        if ($startsAt && $tasksCloseAt
            && $tasksCloseAt->lessThanOrEqualTo($startsAt)) {
            $this->invalid(
                'tasks_close_at',
                'The task deadline must be after the campaign starts.'
            );
        }

        if ($enrollmentClosesAt && $tasksCloseAt
            && $tasksCloseAt->lessThan($enrollmentClosesAt)) {
            $this->invalid(
                'tasks_close_at',
                'The task deadline cannot be before enrolment closes.'
            );
        }

        $budgetKobo = $this->money($data['budget_naira']);

        if ($budgetKobo < (int) $record->allocated_budget_kobo) {
            $this->invalid(
                'budget_naira',
                'The campaign budget cannot be lower than the amount already allocated.'
            );
        }

        $participantCount = DB::table('reward_participants')
            ->where('reward_campaign_id', $campaign)
            ->count();

        $minimumParticipants = max(
            (int) $record->enrolled_count,
            $participantCount
        );

        if ((int) $data['participant_limit'] < $minimumParticipants) {
            $this->invalid(
                'participant_limit',
                'The participant limit cannot be lower than the number already enrolled.'
            );
        }

        $preparedTasks = [];
        $reservationPerParticipant = 0;
        $catalogues = [];

        if (! $hasParticipants) {
            foreach ($data['tasks'] as $index => $task) {
                if ($task['type'] === 'referrals'
                    && (
                        ! isset($task['referral_saves'])
                        || (int) $task['referral_saves'] < 1
                        || (int) $task['referral_saves'] > 1000
                    )) {
                    $this->invalid(
                        "tasks.{$index}.referral_saves",
                        'Referral tasks must require between 1 and 1000 qualifying saves.'
                    );
                }

                $ceilingKobo = $this->money(
                    $task['reward_ceiling_naira']
                );

                if ($ceilingKobo < 1) {
                    $this->invalid(
                        "tasks.{$index}.reward_ceiling_naira",
                        'The reward cost limit must be greater than zero.'
                    );
                }

                $selectedBundles = [];

                foreach (self::NETWORKS as $network => $serviceId) {
                    $code = $task['bundles'][$network] ?? null;

                    if (! $code) {
                        continue;
                    }

                    if (! array_key_exists($network, $catalogues)) {
                        try {
                            $catalogues[$network] = $this->catalogue(
                                $network,
                                $vtpass
                            );
                        } catch (Throwable $exception) {
                            $this->invalid(
                                "tasks.{$index}.bundles.{$network}",
                                'Could not verify the '
                                .strtoupper($network)
                                .' catalogue. Please try again.'
                            );
                        }
                    }

                    $matches = array_values(array_filter(
                        $catalogues[$network],
                        fn (array $plan) => $plan['code'] === $code
                    ));

                    if (count($matches) !== 1) {
                        $this->invalid(
                            "tasks.{$index}.bundles.{$network}",
                            'This bundle is unavailable. Refresh the catalogue and select another plan.'
                        );
                    }

                    $plan = $matches[0];

                    if ($plan['price_kobo'] > $ceilingKobo) {
                        $this->invalid(
                            "tasks.{$index}.reward_ceiling_naira",
                            'The reward cost limit must cover every selected network bundle.'
                        );
                    }

                    $bundleDetails = $this->bundleDetails($plan['name']);

                    $selectedBundles[] = [
                        'network' => $network,
                        'service_id' => $serviceId,
                        'variation_code' => $plan['code'],
                        'bundle_name' => $plan['name'],
                        'validity_label' => $bundleDetails['validity_label'],
                        'data_amount_mb' => $bundleDetails['data_amount_mb'],
                        'listed_price_kobo' => $plan['price_kobo'],
                        'enabled' => true,
                        'catalogue_checked_at' => now(),
                    ];
                }

                if ($selectedBundles === []) {
                    $this->invalid(
                        "tasks.{$index}.bundles",
                        'Choose at least one network bundle for each task.'
                    );
                }

                $reservationPerParticipant += $ceilingKobo;

                $preparedTasks[] = [
                    'title' => $task['title'],
                    'type' => $task['type'],
                    'target_count' => (int) $task['target_count'],
                    'media_type' => $task['media_type'],
                    'source' => $task['source'],
                    'max_reward_cost_kobo' => $ceilingKobo,
                    'qualification_rules' => $task['type'] === 'referrals'
                        ? json_encode([
                            'requires_new_device' => true,
                            'required_unique_saves' => (int) $task['referral_saves'],
                        ], JSON_THROW_ON_ERROR)
                        : null,
                    'bundles' => $selectedBundles,
                    'frequency_days' => $task['type'] === 'save_media'
                        && ($task['frequency_days'] ?? 'once') !== 'once'
                        ? (int) $task['frequency_days']
                        : null,
                ];
            }

            $requiredBudget = $reservationPerParticipant
                * (int) $data['participant_limit'];

            if ($budgetKobo < $requiredBudget) {
                $this->invalid(
                    'budget_naira',
                    'The budget must be at least ₦'
                    .number_format($requiredBudget / 100, 2)
                    .' for the selected tasks and participant limit.'
                );
            }
        }

        DB::transaction(function () use (
            $campaign,
            $data,
            $budgetKobo,
            $startsAt,
            $enrollmentClosesAt,
            $tasksCloseAt,
            $preparedTasks,
            $hasParticipants
        ) {
            $lockedCampaign = DB::table('reward_campaigns')
                ->where('id', $campaign)
                ->lockForUpdate()
                ->first();

            abort_if(! $lockedCampaign, 404);

            // Recheck participation while holding the campaign lock.
            $participantsNowExist = DB::table('reward_participants')
                ->where('reward_campaign_id', $campaign)
                ->exists();

            if ($participantsNowExist && ! $hasParticipants) {
                throw ValidationException::withMessages([
                    'tasks' => 'A participant joined while this campaign was being edited. Reload the page before editing again.',
                ]);
            }

            if ($budgetKobo < (int) $lockedCampaign->allocated_budget_kobo) {
                throw ValidationException::withMessages([
                    'budget_naira' => 'The budget cannot be lower than the amount already allocated.',
                ]);
            }

            DB::table('reward_campaigns')
                ->where('id', $campaign)
                ->update([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'terms' => $data['terms'] ?? null,
                    'audience' => $data['audience'],
                    'referral_credit_limit' => (int) $data['referral_credit_limit'],
                    'referral_credit_period' => $data['referral_credit_period'],
                    'budget_kobo' => $budgetKobo,
                    'participant_limit' => (int) $data['participant_limit'],
                    'starts_at' => $startsAt,
                    'enrollment_closes_at' => $enrollmentClosesAt,
                    'tasks_close_at' => $tasksCloseAt,
                    'updated_at' => now(),
                ]);

            // Existing participants: update titles only.
            // Do not alter task rules, bundles, progress, or entitlements.
            if ($hasParticipants || $participantsNowExist) {
                if (! $hasParticipants && $participantsNowExist) {
                    throw ValidationException::withMessages([
                        'tasks' => 'A participant joined while this campaign was being edited. Reload the page before editing again.',
                    ]);
                }

                $existingTaskIds = DB::table('reward_campaign_tasks')
                    ->where('reward_campaign_id', $campaign)
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $submittedTasks = $data['tasks'] ?? [];

                $submittedTaskIds = collect($submittedTasks)
                    ->pluck('task_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $sortedExistingIds = $existingTaskIds;
                $sortedSubmittedIds = $submittedTaskIds;

                sort($sortedExistingIds);
                sort($sortedSubmittedIds);

                if (
                    count($submittedTaskIds) !== count(array_unique($submittedTaskIds))
                    || $sortedExistingIds !== $sortedSubmittedIds
                ) {
                    throw ValidationException::withMessages([
                        'tasks' => 'The task list has changed. Reload the campaign before saving task titles.',
                    ]);
                }

                foreach ($submittedTasks as $submittedTask) {
                    DB::table('reward_campaign_tasks')
                        ->where('reward_campaign_id', $campaign)
                        ->where('id', (int) $submittedTask['task_id'])
                        ->update([
                            'title' => $submittedTask['title'],
                            'updated_at' => now(),
                        ]);
                }

                return;
            }

            $existingTasks = DB::table('reward_campaign_tasks')
                ->where('reward_campaign_id', $campaign)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            // Refuse to remove tasks with progress. Their history may be
            // referenced by entitlements or task cycles.
            foreach ($existingTasks as $index => $existingTask) {
                if (! isset($preparedTasks[$index])) {
                    $hasProgress = DB::table('reward_task_progress')
                        ->where('reward_campaign_task_id', $existingTask->id)
                        ->exists();

                    if ($hasProgress) {
                        throw ValidationException::withMessages([
                            'tasks' => 'A task with recorded progress cannot be removed. Keep it in the campaign or edit its existing details.',
                        ]);
                    }

                    DB::table('reward_task_bundles')
                        ->where('reward_campaign_task_id', $existingTask->id)
                        ->delete();

                    DB::table('reward_campaign_tasks')
                        ->where('id', $existingTask->id)
                        ->delete();
                }
            }

            foreach ($preparedTasks as $index => $task) {
                $bundles = $task['bundles'];
                unset($task['bundles']);

                $existingTask = $existingTasks->get($index);

                if ($existingTask) {
                    $taskId = $existingTask->id;

                    DB::table('reward_campaign_tasks')
                        ->where('id', $taskId)
                        ->update(array_merge($task, [
                            'sort_order' => $index + 1,
                            'updated_at' => now(),
                        ]));
                } else {
                    $taskId = DB::table('reward_campaign_tasks')
                        ->insertGetId(array_merge($task, [
                            'reward_campaign_id' => $campaign,
                            'code' => 'task-'.Str::uuid(),
                            'sort_order' => $index + 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]));
                }

                $selectedNetworks = [];

                foreach ($bundles as $bundle) {
                    $selectedNetworks[] = $bundle['network'];

                    $existingBundle = DB::table('reward_task_bundles')
                        ->where('reward_campaign_task_id', $taskId)
                        ->where('network', $bundle['network'])
                        ->first();

                    if ($existingBundle) {
                        DB::table('reward_task_bundles')
                            ->where('id', $existingBundle->id)
                            ->update(array_merge($bundle, [
                                'updated_at' => now(),
                            ]));
                    } else {
                        DB::table('reward_task_bundles')->insert(
                            array_merge($bundle, [
                                'reward_campaign_task_id' => $taskId,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ])
                        );
                    }
                }

                // Disable bundles removed from the form instead of deleting
                // rows that may be referenced elsewhere.
                DB::table('reward_task_bundles')
                    ->where('reward_campaign_task_id', $taskId)
                    ->whereNotIn('network', $selectedNetworks)
                    ->update([
                        'enabled' => false,
                        'updated_at' => now(),
                    ]);
            }
        });

        return redirect()
            ->route('admin.campaigns.index')
            ->with('success', 'Campaign updated successfully.');
    }


    private function catalogue(
        string $network,
        VtpassService $vtpass
    ): array {
        $plans = [];

        foreach ($vtpass->variations($network) as $variation) {
            if (! is_array($variation)) {
                continue;
            }

            $name = trim((string) ($variation['name'] ?? ''));
            $code = trim((string) (
                $variation['variation_code'] ?? ''
            ));
            $amount = (string) (
                $variation['variation_amount'] ?? ''
            );

            if ($name === '' || $code === ''
                || Str::length($name) > 255
                || Str::length($code) > 120
                || ! preg_match(
                    '/^\d{1,9}(?:\.\d{1,2})?$/',
                    $amount
                )) {
                continue;
            }

            $priceKobo = $this->money($amount);

            if ($priceKobo < 1) {
                continue;
            }

            $plans[] = [
                'name' => $name,
                'code' => $code,
                'price_kobo' => $priceKobo,
            ];
        }

        return $plans;
    }


    private function bundleDetails(string $bundleName): array
    {
        $dataAmountMb = null;
        $validityLabel = null;

        // Extract data amount: MB or GB.
        if (preg_match(
            '/(\d+(?:\.\d+)?)\s*(GB|MB)\b/i',
            $bundleName,
            $matches
        )) {
            $amount = (float) $matches[1];
            $unit = strtoupper($matches[2]);

            $dataAmountMb = (int) round(
                $unit === 'GB' ? $amount * 1024 : $amount
            );
        }

        // Extract validity: days, hours, weeks.
        if (preg_match(
            '/(\d+(?:\.\d+)?)\s*(days?|d|hrs?|hours?|weeks?|wks?)\b/i',
            $bundleName,
            $matches
        )) {
            $number = $matches[1];
            $unit = strtolower($matches[2]);

            if (in_array($unit, ['d', 'day', 'days'], true)) {
                $validityLabel = $number . ' ' . (
                    (float) $number === 1.0 ? 'Day' : 'Days'
                );
            } elseif (in_array($unit, ['hr', 'hrs', 'hour', 'hours'], true)) {
                $validityLabel = $number . ' ' . (
                    (float) $number === 1.0 ? 'Hour' : 'Hours'
                );
            } else {
                $validityLabel = $number . ' ' . (
                    (float) $number === 1.0 ? 'Week' : 'Weeks'
                );
            }
        }

        return [
            'data_amount_mb' => $dataAmountMb,
            'validity_label' => $validityLabel,
        ];
    }


    private function money(string $value): int
    {
        $parts = explode('.', $value, 2);

        return ((int) $parts[0] * 100)
            + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        return CarbonImmutable::createFromFormat(
            '!Y-m-d\TH:i',
            $value,
            'Africa/Lagos'
        )->utc();
    }

    private function ensureProviderConfigured(): void
    {
        app(VtpassService::class)->assertConfigured();
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => $message,
        ]);
    }

    public function activate(
        int $campaign,
        RewardCampaignService $campaigns
    ) {
        $campaigns->activate($campaign);

        return redirect()
            ->route('admin.campaigns.index')
            ->with('success', 'Sandbox campaign activated.');
    }


    public function toggleVisibility(int $campaign)
    {
        $record = DB::table('reward_campaigns')
            ->where('id', $campaign)
            ->first();

        abort_if(! $record, 404);

        $showInApp = ! (bool) $record->show_in_app;

        DB::table('reward_campaigns')
            ->where('id', $campaign)
            ->update([
                'show_in_app' => $showInApp,
                'updated_at' => now(),
            ]);

        return redirect()
            ->route('admin.campaigns.index')
            ->with(
                'success',
                $showInApp
                    ? 'Campaign is now visible in the app.'
                    : 'Campaign is now hidden from the app.'
            );
    }

}