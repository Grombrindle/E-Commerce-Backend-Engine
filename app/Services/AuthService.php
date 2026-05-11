<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * AuthService — Handles user registration, login, logout.
 */
class AuthService
{
    /**
     * Register a new customer account.
     */
    public function register(array $data): array
    {
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'], // hashed via model cast
            'phone'    => $data['phone']   ?? null,
            'address'  => $data['address'] ?? null,
            'role'     => 'customer',
        ]);

        $token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Login existing user and issue token.
     *
     * @throws ValidationException
     */
    public function login(string $email, string $password, string $deviceName = 'API'): array
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Revoke old tokens (optional: keep only last N)
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName, ['*'], now()->addDays(30))->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Revoke current token.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    /**
     * Update user profile.
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->update(array_filter([
            'name'    => $data['name']    ?? null,
            'phone'   => $data['phone']   ?? null,
            'address' => $data['address'] ?? null,
        ], fn($v) => $v !== null));

        return $user->fresh();
    }

    /**
     * Change user password.
     *
     * @throws ValidationException
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $newPassword]);
        // Revoke all tokens so user has to re-login
        $user->tokens()->delete();
    }
}
