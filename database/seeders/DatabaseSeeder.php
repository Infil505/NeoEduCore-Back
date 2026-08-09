<?php

namespace Database\Seeders;

use Database\Seeders\CoreTablesSeeder;
use Illuminate\Database\Seeder;

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
