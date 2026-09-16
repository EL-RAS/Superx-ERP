<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePlatformOwner;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::withoutBusiness()
            ->where('username', $validated['username'])
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        // STRICT central-domain auth: this endpoint serves ONLY the SuperX
        // platform owner. Tenant / business users (central mirror records as
        // well as any user attached to a business) must authenticate through
        // their dedicated store subdomain via POST /api/v1/tenant-login.
        if ($user->role !== EnsurePlatformOwner::OWNER_ROLE) {
            return response()->json([
                'message' => 'Tenant accounts must log in through their dedicated store subdomain.',
            ], 403);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => ['Your account has been deactivated. Please contact an administrator.'],
            ]);
        }

        $token = $user->createToken('owner-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
            'business' => null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->where('business_id', $request->user()->business_id)
                    ->ignore($request->user()->id),
            ],
            'avatar' => 'sometimes|nullable|string|max:2097152',
        ]);

        $user = $request->user();

        if (array_key_exists('name', $validated)) {
            $user->name = $validated['name'];
        }

        if (array_key_exists('email', $validated)) {
            $user->email = $validated['email'];
        }

        if (array_key_exists('avatar', $validated)) {
            $user->avatar = $validated['avatar'] ?? null;
        }

        $user->save();

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => ['required', 'confirmed', new StrongPassword],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Password changed successfully. Please sign in again.']);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role,
            'avatar' => $user->avatar,
            'is_active' => $user->is_active,
            'is_platform_owner' => $user->role === EnsurePlatformOwner::OWNER_ROLE,
        ];
    }
}
