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

                $selectedBundles[] = [
                    'network' => $network,
                    'service_id' => $serviceId,
                    'variation_code' => $plan['code'],
                    'bundle_name' => $plan['name'],
                    'validity_label' => (
                        $task['validity'][$network] ?? null
                    ) ?: null,

                    // The provider name is preserved in full.
                    // We do not guess a size from the variation code.
                    'data_amount_mb' => null,

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
}