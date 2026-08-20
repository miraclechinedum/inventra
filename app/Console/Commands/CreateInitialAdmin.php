<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateInitialAdmin extends Command
{
    protected $signature = 'inventra:create-admin';

    protected $description = 'Interactively create the first Inventra administrator';

    public function handle(): int
    {
        if (User::query()->where('role', UserRole::Admin)->exists()) {
            $this->error('An administrator already exists.');

            return self::FAILURE;
        }

        $name = $this->ask('Name');
        $email = Str::lower(trim((string) $this->ask('Email address')));
        $phoneInput = $this->ask('Phone number (optional)');
        $phone = filled($phoneInput) ? User::normalizePhone((string) $phoneInput) : null;
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        $data = compact('name', 'email', 'phone', 'password');
        $data['password_confirmation'] = $confirmation;

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $validator->after(function ($validator) use ($phone, $phoneInput): void {
            if (filled($phoneInput) && $phone === null) {
                $validator->errors()->add('phone', 'The phone number must be a valid Nigerian mobile number.');
            }
        });

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->phone = $phone;
        $user->password = $password;
        $user->role = UserRole::Admin;
        $user->status = UserStatus::Active;
        $user->force_password_change = false;
        $user->quick_pin_setup_completed = true;
        $user->save();

        $this->info('Initial administrator created successfully.');

        return self::SUCCESS;
    }
}
