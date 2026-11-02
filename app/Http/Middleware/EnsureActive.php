<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActive
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_active, 403, 'Akun ini tidak aktif.');

        abort_if($request->user()->must_change_password && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && ! $request->is('api/v1/account/password', 'api/v1/auth/logout'), 423, 'Ganti kata sandi awalmu melalui pengaturan akun sebelum melanjutkan.');

        return $next($request);
    }
}
