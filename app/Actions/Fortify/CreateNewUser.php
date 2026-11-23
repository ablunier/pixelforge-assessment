<?php

namespace App\Actions\Fortify;

use App\Actions\TopUp;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /** The Top-up every new account starts with: $1.00. */
    public const int STARTING_CREDITS_NANODOLLARS = 1_000_000_000;

    public function __construct(private readonly TopUp $topUp) {}

    /**
     * Validate and create a newly registered user, with their starting Top-up.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->topUp->handle($user, Money::ofNanodollars(self::STARTING_CREDITS_NANODOLLARS));

            return $user;
        });
    }
}
