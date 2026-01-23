<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class InstallController extends Controller
{
    public function index()
    {
        $requirements = [
            'PHP Version >= 8.1' => version_compare(phpversion(), '8.1.0', '>='),
            'BCMath' => extension_loaded('bcmath'),
            'Ctype' => extension_loaded('ctype'),
            'Fileinfo' => extension_loaded('fileinfo'),
            'JSON' => extension_loaded('json'),
            'Mbstring' => extension_loaded('mbstring'),
            'OpenSSL' => extension_loaded('openssl'),
            'PDO' => extension_loaded('pdo'),
            'Tokenizer' => extension_loaded('tokenizer'),
            'XML' => extension_loaded('xml'),
        ];

        $allMet = !in_array(false, $requirements);

        return Inertia::render('Install/Index', [
            'requirements' => $requirements,
            'allMet' => $allMet
        ]);
    }

    public function environment()
    {
        return Inertia::render('Install/Environment');
    }

    public function saveEnvironment(Request $request)
    {
        $request->validate([
            'app_url' => 'required|url',
            'db_host' => 'required',
            'db_port' => 'required',
            'db_database' => 'required',
            'db_username' => 'required',
            'db_password' => 'nullable',
        ]);

        $envContent = file_get_contents(base_path('.env.example'));
        
        $key = 'base64:' . base64_encode(random_bytes(32));

        $replacements = [
            'APP_URL' => $request->app_url,
            'DB_HOST' => $request->db_host,
            'DB_PORT' => $request->db_port,
            'DB_DATABASE' => $request->db_database,
            'DB_USERNAME' => $request->db_username,
            'DB_PASSWORD' => $request->db_password ?? '',
            'APP_KEY' => $key,
            'DB_CONNECTION' => 'mysql', // Ensure it's mysql, not sqlite from example if any
        ];

        foreach ($replacements as $key => $value) {
            $envContent = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $envContent);
        }

        file_put_contents(base_path('.env'), $envContent);

        // Force set configuration in runtime to allow current request to proceed
        config([
            'app.key' => $key,
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $request->db_host,
            'database.connections.mysql.port' => $request->db_port,
            'database.connections.mysql.database' => $request->db_database,
            'database.connections.mysql.username' => $request->db_username,
            'database.connections.mysql.password' => $request->db_password,
        ]);

        // Purge session database connection to ensure it uses the new config
        try {
            // Se o driver for database, purge nele
            if (config('session.driver') === 'database') {
                DB::purge('mysql');
            }
            // Purge na conexão padrão também para garantir
            DB::purge(config('database.default'));
        } catch (\Exception $e) {}

        Artisan::call('config:clear');
        
        // Force Re-connect database with new config for the rest of request
        try {
            DB::reconnect('mysql');
        } catch (\Exception $e) {}
        
        try {
            $newEncrypter = new \Illuminate\Encryption\Encrypter(base64_decode(substr($key, 7)), config('app.cipher'));
            app()->instance('encrypter', $newEncrypter);
        } catch (\Exception $e) {
        }

        return redirect()->route('install.database');
    }

    public function database()
    {
        return Inertia::render('Install/Database');
    }

    public function migrate()
    {
        try {
            // Force config reload to pick up new .env values
            DB::purge('mysql');
            
            Artisan::call('migrate:fresh', ['--force' => true]);
            Artisan::call('db:seed', ['--force' => true]);
            
            return redirect()->route('install.admin');
        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao migrar banco de dados: ' . $e->getMessage()]);
        }
    }

    public function createUser()
    {
        return Inertia::render('Install/Admin');
    }

    public function storeUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|confirmed|min:8',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'email_verified_at' => now(),
        ]);

        // Assign Admin Role (assuming Spatie Permissions or similar used in Seeder)
        // If DatabaseSeeder runs RolesAndPermissionsSeeder, 'admin' role should exist.
        try {
            $user->assignRole('admin');
        } catch (\Exception $e) {
            // Fallback or ignore if role system not set up yet
        }

        return redirect()->route('install.finish');
    }

    public function finish()
    {
        // Create installed file
        file_put_contents(storage_path('installed'), 'INSTALLED ON ' . now());
        
        return Inertia::render('Install/Finish');
    }
}
