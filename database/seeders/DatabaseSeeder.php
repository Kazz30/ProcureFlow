<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database (LOCAL lang).
     * Sa live na AWS: HUWAG patakbuhin ang buong db:seed.
     * Ang gamitin: php artisan db:seed --class=MasterDataSeeder
     */
    public function run(): void
    {
        $this->call(MasterDataSeeder::class);
    }
}
