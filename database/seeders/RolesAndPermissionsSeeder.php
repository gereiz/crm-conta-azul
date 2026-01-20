<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\UserRole;
use App\Models\Permission;
use App\Models\User;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = UserRole::firstOrCreate(
            ['name' => 'Administrador'],
            ['description' => 'Acesso total', 'is_active' => true]
        );

        $operatorRole = UserRole::firstOrCreate(
            ['name' => 'Operador'],
            ['description' => 'Operações principais', 'is_active' => true]
        );

        $viewerRole = UserRole::firstOrCreate(
            ['name' => 'Visualizador'],
            ['description' => 'Acesso somente leitura', 'is_active' => true]
        );

        $permissions = [
            ['module' => 'messages', 'action' => 'send', 'description' => 'Enviar mensagens'],
            ['module' => 'users', 'action' => 'manage', 'description' => 'Gerenciar usuários'],
            ['module' => 'users', 'action' => 'view', 'description' => 'Visualizar usuários'],
            ['module' => 'roles', 'action' => 'manage', 'description' => 'Gerenciar perfis'],
            ['module' => 'permissions', 'action' => 'manage', 'description' => 'Gerenciar permissões'],
        ];

        foreach ($permissions as $p) {
            $code = strtolower("{$p['module']}.{$p['action']}");
            $perm = Permission::where('code', $code)->first();
            if (!$perm) {
                $perm = Permission::create([
                    'code' => $code,
                    'module' => $p['module'],
                    'action' => $p['action'],
                    'description' => $p['description'],
                ]);
            } else {
                $perm->update([
                    'module' => $p['module'],
                    'action' => $p['action'],
                    'description' => $p['description'],
                ]);
            }
            $adminRole->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $adminUser = User::where('role', 'admin')->first();
        if ($adminUser && !$adminUser->role_id) {
            $adminUser->role_id = $adminRole->id;
            $adminUser->save();
        }
    }
}
