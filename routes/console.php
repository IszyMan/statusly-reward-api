<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


\Illuminate\Support\Facades\Artisan::command(
    'vtpass:plans {network}',
    function () {
        $network = strtolower(
            (string) $this->argument('network')
        );

        if (! in_array($network, ['mtn', 'airtel', 'glo'], true)) {
            $this->error('Choose mtn, airtel, or glo.');

            return 1;
        }

        try {
            $plans = app(
                \App\Services\VtpassService::class
            )->variations($network);

            $selectedCode = config(
                "vtpass.reward_plans.{$network}.variation_code"
            );

            $rows = [];

            foreach ($plans as $plan) {
                if (! is_array($plan)) {
                    continue;
                }

                $code = (string) ($plan['variation_code'] ?? '');

                $rows[] = [
                    $code === $selectedCode ? 'YES' : '',
                    (string) ($plan['name'] ?? ''),
                    (string) ($plan['variation_amount'] ?? ''),
                    $code,
                ];
            }

            $this->table(
                ['Selected reward', 'Plan', 'Amount (NGN)', 'Code'],
                $rows
            );

            $found = collect($plans)->contains(
                fn ($plan) => is_array($plan)
                    && ($plan['variation_code'] ?? null) === $selectedCode
            );

            if (! $found) {
                $this->error(
                    'The configured reward plan was not found.'
                );

                return 1;
            }

            $this->info(
                'Catalogue retrieved. Selected reward plan found.'
            );

            return 0;
        } catch (\Throwable $exception) {
            $this->error(
                'Catalogue test failed. Error type: '
                .class_basename($exception)
            );

            $this->line(
                'Check the keys, base URL, and internet connection.'
            );

            return 1;
        }
    }
)->purpose('Check VTpass plans without purchasing data');


\Illuminate\Support\Facades\Artisan::command(
    'rewards:create-admin',
    function () {
        $name = trim((string) $this->ask('Your name'));
        $email = strtolower(trim((string) $this->ask('Admin email')));
        $password = (string) $this->secret('Password (at least 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = \Illuminate\Support\Facades\Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    'unique:users,email',
                ],
                'password' => [
                    'required',
                    'string',
                    'min:12',
                    'max:72',
                    'confirmed',
                ],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return 1;
        }

        \Illuminate\Support\Facades\DB::table('users')->insert([
            'name' => $name,
            'email' => $email,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'is_admin' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info('Admin account created. You can now sign in.');

        return 0;
    }
)->purpose('Create a rewards administrator account');
Schedule::command('rewards:check-pending')
    ->everyMinute()
    ->withoutOverlapping(30);

Schedule::command('rewards:process-referrals')
    ->everyMinute()
    ->withoutOverlapping(2);    
