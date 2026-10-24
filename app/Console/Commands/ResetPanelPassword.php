<?php

namespace App\Console\Commands;

use App\Auth\SecurityEvents;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ResetPanelPassword extends Command
{
    protected $signature = 'panel:admin-reset-password {username}';

    protected $description = 'Reset an existing panel password and revoke every session';

    public function handle(SecurityEvents $events): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Interactive input is required.');

            return self::FAILURE;
        }
        $user = User::where('username_normalized', Str::lower(trim($this->argument('username'))))->first();
        if (! $user || ! $user->is_active) {
            $this->error('An active, non-deleted administrator with that username is required.');

            return self::FAILURE;
        }
        $password = $this->secret('New password (15-128 characters)', false);
        $confirmation = $this->secret('Confirm password', false);
        $validator = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], [
            'password' => ['present', 'string', 'min:15', 'max:128', 'confirmed'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $hasher = new Argon2IdHasher(config('panel.argon'));
        if ($hasher->check($password, $user->password)) {
            $this->error('The new password must differ from the current password.');

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($user, $password, $hasher, $events): void {
                $user->password = $hasher->make($password);
                $requestId = 'operator-'.Str::uuid();
                $user->saveWithSecurityContext($requestId);
                $events->record('admin_password_reset', $requestId, 'success', $user->id);
            });
        } catch (QueryException|\PDOException) {
            $this->error('Account storage is unavailable or conflicted; the password reset was rolled back.');

            return self::FAILURE;
        }
        $this->info('Password reset; all sessions revoked.');

        return self::SUCCESS;
    }
}
