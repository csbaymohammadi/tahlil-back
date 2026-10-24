<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreatePanelAdministrator extends Command
{
    protected $signature = 'panel:admin-create';

    protected $description = 'Create an active panel administrator using protected interactive input';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Interactive input is required. Passwords cannot be passed as command arguments.');

            return self::FAILURE;
        }
        $username = trim((string) $this->ask('Username'));
        $email = trim((string) $this->ask('Contact email'));
        $name = trim((string) $this->ask('Display name'));
        $password = $this->secret('Password (15-128 characters)', false);
        $confirmation = $this->secret('Confirm password', false);
        $data = ['username' => $username, 'email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation];
        $validator = Validator::make($data, [
            'username' => ['required', 'string', 'min:3', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:100'],
            'password' => ['present', 'string', 'min:15', 'max:128', 'confirmed'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        if (User::withTrashed()->where('username_normalized', Str::lower($username))->exists()) {
            $this->error('That username already exists. No account was changed.');

            return self::FAILURE;
        }
        try {
            $user = new User;
            $user->forceFill(['username' => $username, 'email' => $email, 'name' => $name, 'is_active' => true,
                'password' => (new Argon2IdHasher(config('panel.argon')))->make($password), 'password_changed_at' => now()])->save();
        } catch (QueryException|\PDOException) {
            $this->error('Account storage is unavailable or conflicted; no account was created.');

            return self::FAILURE;
        }
        $this->info('Panel administrator created.');

        return self::SUCCESS;
    }
}
