<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            FeatureTestFileManagement20260303Seeder::class,
            FeatureTestNotificationsIndex20260303Seeder::class,
        ]);
    }
}
