<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $roles = UserRole::orderBy('name')->get();

        return Inertia::render('Settings/Roles/Index', ['roles' => $roles]);
    }

    public function create()
    {
        $permissions = Permission::query()
            ->whereNotNull('module')->where('module', '!=', '')
            ->whereNotNull('action')->where('action', '!=', '')
            ->orderBy('module')->orderBy('action')->get();

        return Inertia::render('Settings/Roles/Form', [
            'role' => null,
            'permissions' => $permissions,
            'selected' => [],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:user_roles,name',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'permission_ids' => 'array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);
        $role = UserRole::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $role->permissions()->sync($data['permission_ids'] ?? []);

        return redirect()->route('settings.roles.index')->with('success', 'Perfil criado.');
    }

    public function edit(UserRole $role)
    {
        $permissions = Permission::query()
            ->whereNotNull('module')->where('module', '!=', '')
            ->whereNotNull('action')->where('action', '!=', '')
            ->orderBy('module')->orderBy('action')->get();
        $selected = $role->permissions()->pluck('permissions.id')->all();

        return Inertia::render('Settings/Roles/Form', [
            'role' => $role,
            'permissions' => $permissions,
            'selected' => $selected,
        ]);
    }

    public function update(Request $request, UserRole $role)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:user_roles,name,'.$role->id,
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'permission_ids' => 'array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);
        $role->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $role->permissions()->sync($data['permission_ids'] ?? []);

        return redirect()->route('settings.roles.index')->with('success', 'Perfil atualizado.');
    }

    public function destroy(UserRole $role)
    {
        $role->permissions()->detach();
        $role->delete();

        return redirect()->route('settings.roles.index')->with('success', 'Perfil excluído.');
    }
}
