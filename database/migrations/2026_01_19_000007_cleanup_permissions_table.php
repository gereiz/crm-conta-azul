<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $used = [
            ['code' => 'roles.manage', 'module' => 'roles', 'action' => 'manage', 'description' => 'Gerenciar perfis'],
            ['code' => 'permissions.manage', 'module' => 'permissions', 'action' => 'manage', 'description' => 'Gerenciar permissões'],
            ['code' => 'messages.send', 'module' => 'messages', 'action' => 'send', 'description' => 'Enviar mensagens'],
            ['code' => 'users.view', 'module' => 'users', 'action' => 'view', 'description' => 'Visualizar usuários'],
            ['code' => 'users.manage', 'module' => 'users', 'action' => 'manage', 'description' => 'Gerenciar usuários'],
        ];

        foreach ($used as $u) {
            $existing = DB::table('permissions')->where('code', $u['code'])->first();
            if ($existing) {
                DB::table('permissions')->where('id', $existing->id)->update([
                    'module' => $u['module'],
                    'action' => $u['action'],
                    'description' => $existing->description ?: $u['description'],
                    'code' => $u['code'],
                ]);
            } else {
                $maybe = DB::table('permissions')
                    ->where('module', $u['module'])
                    ->where('action', $u['action'])
                    ->first();
                if ($maybe) {
                    DB::table('permissions')->where('id', $maybe->id)->update([
                        'code' => $u['code'],
                        'module' => $u['module'],
                        'action' => $u['action'],
                        'description' => $maybe->description ?: $u['description'],
                    ]);
                } else {
                    DB::table('permissions')->insert($u);
                }
            }
        }

        $invalids = DB::table('permissions')
            ->where(function ($q) {
                $q->whereNull('module')->orWhere('module', '');
            })
            ->orWhere(function ($q) {
                $q->whereNull('action')->orWhere('action', '');
            })
            ->get();

        $usedCodes = array_column($used, 'code');
        $usedMap = [];
        foreach ($used as $u) {
            $usedMap[$u['code']] = $u;
        }

        foreach ($invalids as $perm) {
            $isUsedCode = $perm->code && in_array($perm->code, $usedCodes, true);
            if ($isUsedCode) {
                $u = $usedMap[$perm->code];
                DB::table('permissions')->where('id', $perm->id)->update([
                    'module' => $u['module'],
                    'action' => $u['action'],
                    'description' => $perm->description ?: $u['description'],
                ]);
                continue;
            }

            $pivotExists = DB::table('role_permissions')->where('permission_id', $perm->id)->exists();
            if (!$pivotExists) {
                DB::table('permissions')->where('id', $perm->id)->delete();
            } else {
                if ($perm->code && strpos($perm->code, '.') !== false) {
                    [$m, $a] = explode('.', $perm->code, 2);
                    DB::table('permissions')->where('id', $perm->id)->update([
                        'module' => $m,
                        'action' => $a,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
    }
};
