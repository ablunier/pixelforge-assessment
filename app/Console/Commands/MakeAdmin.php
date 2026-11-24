<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Makes an existing user an admin, so they can see the admin report.
 */
#[Signature('user:make-admin {email : The email of the user to make an admin}')]
#[Description('Make a user an admin')]
class MakeAdmin extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No user has the email {$email}.");

            return self::FAILURE;
        }

        $user->is_admin = true;
        $user->save();

        $this->components->info("{$email} is now an admin.");

        return self::SUCCESS;
    }
}
