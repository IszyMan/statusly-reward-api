<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegisteredDeviceController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,blocked,archived'],
        ]);

        $search = trim($data['search'] ?? '');
        $status = $data['status'] ?? '';

        // Include all registered devices, even without campaign participation.
        $query = DB::table('reward_devices as devices')
            ->select([
                'devices.id',
                'devices.public_id',
                'devices.referral_code',
                'devices.first_seen_at',
                'devices.last_seen_at',
                'devices.blocked_at',
                'devices.archived_at',
            ])
            ->selectSub(function (Builder $query) {
                $query
                    ->from('reward_participants as participants')
                    ->selectRaw(
                        'COUNT(DISTINCT participants.reward_campaign_id)'
                    )
                    ->whereColumn(
                        'participants.reward_device_id',
                        'devices.id'
                    );
            }, 'campaigns_count');

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search) {
                $query
                    ->where('devices.public_id', $search)
                    ->orWhere(
                        'devices.referral_code',
                        strtoupper($search)
                    );

                if (ctype_digit($search)) {
                    $query->orWhere('devices.id', (int) $search);
                }
            });
        }

        if ($status === 'archived') {
            $query->whereNotNull('devices.archived_at');
        } elseif ($status === 'blocked') {
            $query
                ->whereNull('devices.archived_at')
                ->whereNotNull('devices.blocked_at');
        } elseif ($status === 'active') {
            $query
                ->whereNull('devices.archived_at')
                ->whereNull('devices.blocked_at');
        }

        $devices = $query
            ->orderByDesc('devices.id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.devices.registered', compact(
            'devices',
            'search',
            'status'
        ));
    }
}