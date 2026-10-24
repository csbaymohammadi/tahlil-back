<?php

namespace Database\Seeders;

use App\Auth\SecurityEvents;
use App\Models\Role;
use App\Models\User;
use App\Support\PanelMutationLock;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PanelAdministratorSeeder extends Seeder
{
    public function run(): void
    {
        $settings = config('panel.seed_administrator');
        if (! is_string($settings['email']) || $settings['email'] === '' || ! is_string($settings['password']) || $settings['password'] === '') {
            return;
        }
        $settings['username'] = Str::trim($settings['username']);
        $settings['email'] = mb_strtolower(Str::trim($settings['email']));
        $settings['name'] = Str::trim($settings['name']);
        Validator::make($settings, [
            'username' => ['required', 'string', 'min:3', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'password' => ['present', 'string', 'min:15', 'max:128'],
        ])->validate();
        try {
            DB::transaction(function () use ($settings): void {
                PanelMutationLock::acquire();
                if (User::withTrashed()->where('username_normalized', mb_strtolower($settings['username']))->exists()) {
                    return;
                }
                if (User::withTrashed()->where('email', $settings['email'])->exists()) {
                    throw new \RuntimeException('The seed administrator email is reserved by an existing account; no account was changed.');
                }
                $user = new User;
                $user->forceFill([
                    'username' => $settings['username'], 'name' => $settings['name'], 'email' => $settings['email'],
                    'password' => (new Argon2IdHasher(config('panel.argon')))->make($settings['password']),
                    'is_active' => true, 'password_changed_at' => now(),
                    'role_id' => Role::where('name_normalized', 'administrator')->value('id'),
                ])->save();
                (new SecurityEvents)->record('admin_created', 'seed-'.Str::uuid(), 'success', $user->id);
            });
        } catch (QueryException|\PDOException) {
            throw new \RuntimeException('Seed administrator storage failed; no account changes were committed.');
        }

    }
}
