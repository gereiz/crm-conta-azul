<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);
        $users = User::with('roleRef')->orderBy('created_at', 'desc')->get();
        $roles = UserRole::orderBy('name')->get();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'nullable|in:admin,operator,viewer',
            'role_id' => 'nullable|integer|exists:user_roles,id',
            'is_active' => 'boolean',
        ]);

        $roleId = $request->role_id;
        if (!$roleId && $request->role) {
            $map = [
                'admin' => 'Administrador',
                'operator' => 'Operador',
                'viewer' => 'Visualizador',
            ];
            $name = $map[$request->role] ?? null;
            $roleId = $name ? UserRole::whereRaw('LOWER(name)=LOWER(?)', [$name])->value('id') : null;
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role ?? ($roleId ? 'viewer' : 'operator'),
            'is_active' => $request->is_active ?? true,
            'role_id' => $roleId,
        ]);

        return redirect()->route('users.index')->with('success', 'Usuário criado com sucesso!');
    }
    
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'role' => 'nullable|in:admin,operator,viewer',
            'role_id' => 'nullable|integer|exists:user_roles,id',
            'is_active' => 'boolean',
        ]);
        
        $roleId = $request->role_id;
        if (!$roleId && $request->role) {
            $map = [
                'admin' => 'Administrador',
                'operator' => 'Operador',
                'viewer' => 'Visualizador',
            ];
            $name = $map[$request->role] ?? null;
            $roleId = $name ? UserRole::whereRaw('LOWER(name)=LOWER(?)', [$name])->value('id') : null;
        }
        
        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'is_active' => $request->is_active ?? $user->is_active,
            'role_id' => $roleId,
        ];
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }
        if ($request->role) {
            $data['role'] = $request->role;
        } elseif ($roleId) {
            $target = UserRole::find($roleId);
            $lower = strtolower($target?->name ?? '');
            $data['role'] = $lower === 'administrador' ? 'admin' : (in_array($lower, ['operador', 'operacional']) ? 'operator' : 'viewer');
        }
        
        $user->update($data);
        
        return redirect()->route('users.index')->with('success', 'Usuário atualizado com sucesso!');
    }
    
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);
        $user->delete();
        return redirect()->route('users.index')->with('success', 'Usuário excluído com sucesso!');
    }


    
}
