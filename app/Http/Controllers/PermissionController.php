<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Inertia\Inertia;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index(Request $request)
    {
        $permissions = Permission::query()
            ->whereNotNull('module')->where('module', '!=', '')
            ->whereNotNull('action')->where('action', '!=', '')
            ->orderBy('module')->orderBy('action')
            ->get();
        return Inertia::render('Settings/Permissions/Index', ['permissions' => $permissions]);
    }
    
    public function create()
    {
        return Inertia::render('Settings/Permissions/Form', [
            'permission' => null,
        ]);
    }
    
    public function store(Request $request)
    {
        $data = $request->validate([
            'module' => 'required|string|max:255',
            'action' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);
        $code = strtolower(trim($data['module'])).'.'.strtolower(trim($data['action']));
        Permission::updateOrCreate(
            ['code' => $code],
            [
                'module' => $data['module'],
                'action' => $data['action'],
                'description' => $data['description'] ?? null,
            ]
        );
        return redirect()->route('settings.permissions.index')->with('success', 'Permissão criada.');
    }
    
    public function edit(Permission $permission)
    {
        return Inertia::render('Settings/Permissions/Form', [
            'permission' => $permission,
        ]);
    }
    
    public function update(Request $request, Permission $permission)
    {
        $data = $request->validate([
            'module' => 'required|string|max:255',
            'action' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);
        // Garantir unicidade combinada
        $exists = Permission::where('module', $data['module'])
            ->where('action', $data['action'])
            ->where('id', '!=', $permission->id)
            ->exists();
        if ($exists) {
            return back()->with('error', 'Já existe uma permissão com este módulo/ação.');
        }
        $permission->update([
            'module' => $data['module'],
            'action' => $data['action'],
            'description' => $data['description'] ?? null,
        ]);
        $permission->code = strtolower(trim($permission->module)).'.'.strtolower(trim($permission->action));
        $permission->save();
        return redirect()->route('settings.permissions.index')->with('success', 'Permissão atualizada.');
    }
    
    public function destroy(Permission $permission)
    {
        $permission->delete();
        return redirect()->route('settings.permissions.index')->with('success', 'Permissão excluída.');
    }
}
