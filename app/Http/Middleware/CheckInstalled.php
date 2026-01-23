<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Storage;

class CheckInstalled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isInstalled = file_exists(storage_path('installed'));
        
        // Se já estiver instalado e tentar acessar rotas de instalação, redireciona para home
        if ($isInstalled && $request->is('install*')) {
            return redirect('/');
        }

        // Se NÃO estiver instalado e tentar acessar outras rotas, redireciona para instalação
        if (!$isInstalled && !$request->is('install*')) {
            return redirect()->route('install.index');
        }

        return $next($request);
    }
}
