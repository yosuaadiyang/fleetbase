<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Administrative cache operations require the application's authenticated token. */
final class AuthenticatedOpcacheRequest
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->get('key');
        if (!is_string($key) || $key === '' || strlen($key) > 4096) {
            throw new HttpException(403, 'This action is unauthorized.');
        }
        try {
            $value = Crypt::decrypt($key);
        } catch (DecryptException $error) {
            throw new HttpException(403, 'This action is unauthorized.');
        }
        if (!is_string($value) || !hash_equals('opcache', $value)) {
            throw new HttpException(403, 'This action is unauthorized.');
        }
        return $next($request);
    }
}
