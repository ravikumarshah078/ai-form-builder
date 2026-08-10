<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Entry point for `php artisan db:seed`.
 *
 * Order matters: the demo user has to exist before anything can own a form.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoUserSeeder::class,
            DemoFormSeeder::class,
        ]);
    }
}
