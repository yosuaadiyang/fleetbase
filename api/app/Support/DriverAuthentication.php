<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/** Shared limits across workers and bootstrap keys; never put identities in cache keys. */
final class DriverAuthentication
{
    private const ACTIONS = ['login', 'loginWithPhone', 'verifyCode', 'forgotPassword', 'resetPassword'];

    public static function check(Request $request, string $action): ?JsonResponse
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return null;
        }
        // Bound malformed requests as well as otherwise valid credential attempts.
        if ($limited = self::hit('ip:' . hash('sha256', (string) $request->ip()), 120)) {
            return $limited;
        }
        $identityField = $action === 'loginWithPhone' ? 'phone' : 'identity';
        $rules = [$identityField => ['required', 'string', 'max:254', function ($attribute, $value, $fail) {
            if (!is_string($value) || (!filter_var($value, FILTER_VALIDATE_EMAIL) && !preg_match('/^\+?[0-9]{8,15}$/D', $value))) {
                $fail('Use an email address or an international phone number.');
            }
        }]];
        if ($action === 'loginWithPhone') {
            $rules['phone'][] = 'regex:/^\+?[0-9]{8,15}$/D';
        }
        if (in_array($action, ['verifyCode', 'resetPassword'], true)) {
            $rules['code'] = ['required', 'string', 'regex:/^[0-9]{6}$/D'];
        }
        if ($action === 'verifyCode') {
            $rules['for'] = ['sometimes', 'required', 'string', 'in:driver_login'];
        }
        if (in_array($action, ['login', 'resetPassword'], true)) {
            $rules['password'] = ['required', 'string', 'max:4096'];
            if ($action === 'resetPassword') {
                $rules['password'][] = 'min:8';
            }
        }
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()], 422);
        }
        $identity = strtolower($request->input($identityField));
        if (!str_contains($identity, '@')) {
            $identity = '+' . ltrim($identity, '+');
        }
        $send = in_array($action, ['loginWithPhone', 'forgotPassword'], true);
        $group = $send ? 'send' : ($action === 'login' ? 'password' : 'code');
        // Identity-wide limit cannot be evaded by cycling organization bootstrap keys.
        return self::hit($group . ':' . hash('sha256', $identity), $send ? 3 : 10);
    }

    private static function hit(string $key, int $maximum): ?JsonResponse
    {
        $key = 'contrust:driver-auth:' . $key;
        if (RateLimiter::tooManyAttempts($key, $maximum)) {
            return response()->json(['errors' => ['Too many attempts. Please try again later.']], 429)
                ->header('Retry-After', (string) max(1, RateLimiter::availableIn($key)));
        }
        RateLimiter::hit($key, 300);
        return null;
    }
}
