<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function create(array $input): User
    {

        Validator::make($input, [

            ...$this->profileRules(),

            'role' => [
                'sometimes',
                'in:student',
            ],

            'password' => array_values(array_filter(
                $this->passwordRules(),
                fn ($rule) => $rule !== 'confirmed'
            )),
            'password_confirmation' => ['required', 'same:password'],
        ], [
            'password_confirmation.same' => 'The password confirmation does not match.',
        ])->validate();

        return User::create([

            'name' => $input['name'],

            'email' => $input['email'],

            'password' => Hash::make(
                $input['password']
            ),

            'role' => 'student',

        ]);

    }
}
