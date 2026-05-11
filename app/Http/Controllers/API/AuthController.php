<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\Request;

/**
 * AuthController
 *
 * @POST   /api/v1/auth/register  → register()
 * @POST   /api/v1/auth/login     → login()
 * @POST   /api/v1/auth/logout    → logout()        [auth]
 * @GET    /api/v1/auth/me        → me()            [auth]
 * @PUT    /api/v1/auth/profile   → updateProfile() [auth]
 * @PUT    /api/v1/auth/password  → changePassword()[auth]
 */
class AuthController extends Controller
{
    public function __construct(protected AuthService $authService) {}

    /** Register new user. Returns user + token. */
    public function register(RegisterRequest $request)
    {
        $result = $this->authService->register($request->validated());

        return $this->created([
            'user'  => new UserResource($result['user']),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ], 'Account created successfully.');
    }

    /** Login. Returns user + token. */
    public function login(LoginRequest $request)
    {
        $result = $this->authService->login(
            $request->email,
            $request->password,
            $request->header('X-Device-Name', 'API')
        );

        return $this->success([
            'user'       => new UserResource($result['user']),
            'token'      => $result['token'],
            'token_type' => 'Bearer',
        ], 'Logged in successfully.');
    }

    /** Logout (revoke current token). */
    public function logout(Request $request)
    {
        $this->authService->logout($request->user());
        return $this->success(null, 'Logged out successfully.');
    }

    /** Get authenticated user. */
    public function me(Request $request)
    {
        return $this->success(new UserResource($request->user()));
    }

    /** Update profile info. */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'name'    => 'sometimes|string|max:255',
            'phone'   => 'sometimes|string|max:20',
            'address' => 'sometimes|array',
        ]);

        $user = $this->authService->updateProfile($request->user(), $request->validated());
        return $this->success(new UserResource($user), 'Profile updated.');
    }

    /** Change password (revokes all tokens). */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $this->authService->changePassword(
            $request->user(),
            $request->current_password,
            $request->password
        );

        return $this->success(null, 'Password changed. Please log in again.');
    }
}
