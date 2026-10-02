<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the first Administrator of an existing Business that has none — for example the original
 * installation's Business, or a Business an operator must recover.
 *
 * It never chooses a tenant. The Business is named with --business; only an installation with a
 * single Business may omit it. A Business that already has an Administrator is refused: further
 * Administrators are added through staff management. New Businesses come from signup, through
 * App\Actions\Business\ProvisionBusiness, not from this command.
 */
class CreateInitialAdmin extends Command
{
    protected $signature = 'inventra:create-admin {--business= : The id of the Business to create the Administrator in}';

    protected $description = 'Interactively create the first Administrator of an existing Business';

    public function handle(): int
    {
        $business = $this->business();

        if ($business === null) {
            return self::FAILURE;
        }

        if (! $business->isActive()) {
            $this->error('The business is not active.');

            return self::FAILURE;
        }

        if ($business->users()->where('role', UserRole::Admin)->exists()) {
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
        $user->business_id = $business->getKey();
        $user->name = $name;
        $user->email = $email;
        $user->phone = $phone;
        $user->password = $password;
        $user->role = UserRole::Admin;
        $user->status = UserStatus::Active;
        $user->force_password_change = false;
        $user->quick_pin_setup_completed = true;
        $user->save();

        $this->info("Administrator created for business #{$business->getKey()}.");

        return self::SUCCESS;
    }

    /** The named Business, or the only one; never a guess between several. */
    private function business(): ?Business
    {
        $option = $this->option('business');

        if ($option !== null) {
            $business = ctype_digit((string) $option) ? Business::query()->find((int) $option) : null;

            if ($business === null) {
                $this->error('No business has that id.');
            }

            return $business;
        }

        $businesses = Business::query()->limit(2)->get();

        if ($businesses->count() !== 1) {
            $this->error($businesses->isEmpty()
                ? 'No business exists yet. Businesses are created through signup.'
                : 'More than one business exists. Name the business with --business=<id>.');

            return null;
        }

        return $businesses->first();
    }
}
