<?php

namespace Database\Seeders;

use App\Models\Admin\User;
use Database\Seeders\CoreTablesSeeder;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Enums\UserStatus;
use App\Enums\UserType;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // populate core tables with initial data
        $this->call(CoreTablesSeeder::class);
    }
}
