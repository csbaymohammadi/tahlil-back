<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\RoleNames;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Administrator', 'Developer', 'SEO Manager', 'Writer'] as $name) {
            Role::withTrashed()->firstOrCreate(['name_normalized' => RoleNames::normalize($name)], ['name' => $name]);
        }
    }
}
