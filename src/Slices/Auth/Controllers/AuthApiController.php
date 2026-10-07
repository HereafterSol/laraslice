<?php

namespace LaraSlice\Slices\Auth\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaraSlice\Slices\Auth\Services\AuthSliceService;

class AuthApiController extends Controller
{
    protected AuthSliceService $authService;

    public function __construct(AuthSliceService $service)
    {
        $this->authService = $service;
    }

    /**
     * POST /api/auth/login
     * Authenticate Flutter or mobile app client and return Bearer token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:100',
            'mfa_code' => 'nullable|string|max:20',
        ]);

        $result = $this->authService->issueApiToken(
            $request,
            $validated['email'],
            $validated['password'],
            $validated['device_name'] ?? 'Flutter Device',
            $validated['mfa_code'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        abort_unless(config('laraslice.auth.api_registration', false), 404);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $this->authService->registerUser($validated);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        if ($user = $request->user()) {
            if (method_exists($user, 'currentAccessToken') && $user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }
}
