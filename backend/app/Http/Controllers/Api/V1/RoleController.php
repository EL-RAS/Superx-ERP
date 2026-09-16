<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = Role::query()
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 10));

        return response()->json($roles);
    }

    public function matrix(): JsonResponse
    {
        $modules = collect(RbacService::modules())
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values();

        $actions = collect(RbacService::actions())
            ->map(fn (string $action) => ['key' => $action, 'label' => ucfirst($action)])
            ->values();

        return response()->json([
            'modules' => $modules,
            'actions' => $actions,
            'roles' => Role::query()->orderBy('is_system', 'desc')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'permissions' => 'sometimes|array',
        ]);

        $permissions = $validated['permissions'] ?? [];
        $this->validatePermissions($permissions);

        $slug = Str::slug($validated['name']);

        if (Role::withoutBusiness()
            ->where('business_id', $request->user()->business_id)
            ->where('slug', $slug)
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A role with this name already exists.'],
            ]);
        }

        $role = Role::create([
            'id' => (string) Str::uuid(),
            'business_id' => $request->user()->business_id,
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'permissions' => $permissions,
            'is_system' => false,
        ]);

        return response()->json($role, 201);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json($role);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $role->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json($role);
    }

    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => 'required|array',
        ]);

        $permissions = array_values($validated['permissions']);
        $this->validatePermissions($permissions);

        $role->update(['permissions' => $permissions]);

        return response()->json($role);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 422);
        }

        $assigned = User::withoutBusiness()
            ->where('business_id', $role->business_id)
            ->where('role', $role->slug)
            ->exists();

        if ($assigned) {
            return response()->json(['message' => 'Cannot delete a role that is assigned to users.'], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    private function validatePermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!is_string($permission) || !RbacService::isValidKey($permission)) {
                throw ValidationException::withMessages([
                    'permissions' => ["Invalid permission key: {$permission}"],
                ]);
            }
        }
    }
}
