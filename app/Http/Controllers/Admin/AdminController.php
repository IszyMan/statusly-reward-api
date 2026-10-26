<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function login()
    {
        if (Auth::check() && (bool) Auth::user()->is_admin) {
            return redirect()->route('admin.dashboard');
        }

        return response()
            ->view('admin.login')
            ->header('Cache-Control', 'no-store, private');
    }

    public function authenticate(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $credentials['email'] = strtolower(
            trim($credentials['email'])
        );

        $credentials['is_admin'] = true;

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'The email or password is incorrect.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    public function dashboard()
    {
        return view('admin.dashboard', [
            'deviceCount' => DB::table('reward_devices')->count(),

            'campaignCount' => DB::table('reward_campaigns')->count(),

            'participantCount' => DB::table('reward_participants')
                ->count(),

            'deliveredCount' => DB::table('reward_entitlements')
                ->where('status', 'delivered')
                ->count(),

            'campaigns' => DB::table('reward_campaigns')
                ->orderByDesc('id')
                ->paginate(10),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}