<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CampaignPopupController extends Controller
{
    public function index()
    {
        $popups = DB::table('reward_campaign_popups as popups')
            ->join(
                'reward_campaigns as campaigns',
                'campaigns.id',
                '=',
                'popups.reward_campaign_id'
            )
            ->select([
                'popups.*',
                'campaigns.name as campaign_name',
                'campaigns.environment as campaign_environment',
                'campaigns.status as campaign_status',
            ])
            ->orderByDesc('popups.priority')
            ->orderByDesc('popups.id')
            ->paginate(15);

        return view('admin.popups.index', compact('popups'));
    }

    public function create()
    {
        return view('admin.popups.create', [
            'campaigns' => $this->campaigns(),
        ]);
    }

    public function store(Request $request)
    {
        $values = $this->validatedValues($request);

        $id = DB::table('reward_campaign_popups')->insertGetId([
            ...$values,
            'announcement_key' => 'popup:'.Str::uuid(),
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('admin.popups.edit', $id)
            ->with('success', 'Popup created.');
    }

    public function edit(int $popup)
    {
        $record = DB::table('reward_campaign_popups')
            ->where('id', $popup)
            ->first();

        abort_unless($record, 404);

        return view('admin.popups.edit', [
            'popup' => $record,
            'campaigns' => $this->campaigns(),
        ]);
    }

    public function update(Request $request, int $popup)
    {
        $values = $this->validatedValues($request);
        $publishAgain = $request->boolean('publish_again');

        DB::transaction(function () use (
            $popup,
            $values,
            $publishAgain
        ) {
            $record = DB::table('reward_campaign_popups')
                ->where('id', $popup)
                ->lockForUpdate()
                ->first();

            abort_unless($record, 404);

            $displayFields = [
                'reward_campaign_id',
                'heading',
                'title',
                'message',
                'template',
                'background_start',
                'background_end',
                'accent_color',
                'text_color',
                'button_text_color',
            ];

            $changed = false;

            foreach ($displayFields as $field) {
                if ((string) $record->{$field} !== (string) $values[$field]) {
                    $changed = true;
                    break;
                }
            }

            $revision = (int) $record->revision;

            if ($changed || $publishAgain) {
                $revision++;
            }

            DB::table('reward_campaign_popups')
                ->where('id', $popup)
                ->update([
                    ...$values,
                    'revision' => max(1, $revision),
                    'updated_at' => now(),
                ]);
        }, 3);

        return redirect()
            ->route('admin.popups.edit', $popup)
            ->with('success', 'Popup updated.');
    }

    private function campaigns()
    {
        return DB::table('reward_campaigns')
            ->orderByDesc('id')
            ->get([
                'id',
                'name',
                'code',
                'environment',
                'status',
            ]);
    }

    private function validatedValues(Request $request): array
    {
        $colorRule = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];

        $data = $request->validate([
            'reward_campaign_id' => [
                'required',
                'integer',
                Rule::exists('reward_campaigns', 'id'),
            ],
            'name' => ['required', 'string', 'max:160'],
            'heading' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:2000'],
            'template' => [
                'required',
                Rule::in([
                    'gift_card',
                    'gift_corner_left',
                    'gift_corner_right',
                    'gift_seal',
                    'simple_card',
                ]),
            ],
            'background_start' => $colorRule,
            'background_end' => $colorRule,
            'accent_color' => $colorRule,
            'text_color' => $colorRule,
            'button_text_color' => $colorRule,
            'enabled' => ['required', 'boolean'],
            'priority' => ['required', 'integer', 'between:0,100'],
            'publish_again' => ['nullable', 'boolean'],
        ]);

        return [
            'reward_campaign_id' => (int) $data['reward_campaign_id'],
            'name' => trim($data['name']),
            'heading' => trim($data['heading']),
            'title' => trim($data['title']),
            'message' => trim((string) ($data['message'] ?? '')),
            'template' => $data['template'],
            'background_start' => strtoupper($data['background_start']),
            'background_end' => strtoupper($data['background_end']),
            'accent_color' => strtoupper($data['accent_color']),
            'text_color' => strtoupper($data['text_color']),
            'button_text_color' => strtoupper($data['button_text_color']),
            'enabled' => $request->boolean('enabled'),
            'priority' => (int) $data['priority'],
        ];
    }
}