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
        // Order matters: DemoWorkflowSeeder resolves the teams, roles and users
        // ManualTestingSeeder creates, then builds PP → PK → PABD → PRBL on top.
        $this->call(ManualTestingSeeder::class);
        $this->call(DemoWorkflowSeeder::class);
    }
}
