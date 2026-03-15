<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * List of valid country codes (ISO 3166-1 alpha-2).
     */
    protected array $validCountryCodes = [
        'NA', 'ZA', 'BW', 'ZW', 'ZM', 'AO', 'MZ', 'LS', 'SZ', // Southern Africa
        'US', 'GB', 'DE', 'FR', 'AU', 'CA', // Major markets
    ];

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $tenant = app('current_tenant');
        $tenantId = $tenant?->id;

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->where('tenant_id', $tenantId),
            ],
            'username' => [
                'nullable',
                'string',
                'min:3',
                'max:50',
                'regex:/^[a-zA-Z0-9_-]+$/',
                Rule::unique('users')->where('tenant_id', $tenantId),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^\+?[1-9]\d{1,14}$/', // E.164 format
                Rule::unique('users')->where('tenant_id', $tenantId),
            ],
            'date_of_birth' => [
                'required',
                'date',
                'before:' . now()->subYears(18)->format('Y-m-d'), // Must be 18+
            ],
            'country_code' => [
                'required',
                'string',
                'size:2',
                Rule::in($this->validCountryCodes),
            ],
            'terms_accepted' => [
                'required',
                'accepted',
            ],
            'password' => $this->passwordRules(),
        ], [
            'date_of_birth.before' => 'You must be at least 18 years old to register.',
            'username.regex' => 'Username may only contain letters, numbers, dashes, and underscores.',
            'phone.regex' => 'Please enter a valid phone number in international format (e.g., +264811234567).',
            'terms_accepted.accepted' => 'You must accept the Terms of Service and Privacy Policy.',
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'username' => $input['username'] ?? null,
            'phone' => $input['phone'] ?? null,
            'date_of_birth' => $input['date_of_birth'],
            'country_code' => $input['country_code'],
            'terms_accepted_at' => now(),
            'tenant_id' => $tenantId,
        ]);

        // Assign player role for tenant users
        if ($tenantId) {
            $user->assignRole('player', $tenantId);
        }

        return $user;
    }
}
