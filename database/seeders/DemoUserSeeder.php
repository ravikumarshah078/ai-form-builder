<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The account the reviewers will sign in with.
 *
 * Credentials are also printed at the top of the README, as the brief
 * requires. updateOrCreate rather than create so re-seeding a live demo does
 * not fail on the unique email.
 */
class DemoUserSeeder extends Seeder
{
    public const EMAIL = 'demo@formbuilder.test';

    public const PASSWORD = 'password';

    public function run(): void
    {
        User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Demo Admin',
                // Hashed by the model's 'password' => 'hashed' cast.
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
            ]
        );
    }
}
