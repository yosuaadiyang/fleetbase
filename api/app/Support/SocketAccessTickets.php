<?php

namespace App\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/** Opaque read-only socket capability; never stores a plaintext API token. */
final class SocketAccessTickets
{
    public const COOKIE = 'contrust_socket';
    public const TTL_MINUTES = 10;

    public static function validToken(?PersonalAccessToken $token): bool
    {
        if (!$token || ($token->expires_at && $token->expires_at->isPast())) return false;
        $expiration = config('sanctum.expiration');
        return !$expiration || (is_numeric($expiration) && $token->created_at
            && $token->created_at->gt(now()->subMinutes((int) $expiration)));
    }

    public static function actor(?PersonalAccessToken $token): ?array
    {
        if (!self::validToken($token)) return null;
        $user = $token->tokenable;
        if (!$user instanceof User || $user->status !== 'active' || !Str::isUuid($user->company_uuid)) return null;
        $company = Company::find($user->company_uuid);
        if (!$company) return null;
        $driverToken = is_string($token->name) && Str::isUuid($token->name) && $token->name !== $user->uuid;
        $driver = null;
        if ($driverToken || $user->isType('driver')) {
            $driver = Driver::where('uuid', $token->name)->where('user_uuid', $user->uuid)->where('company_uuid', $company->uuid)->first();
            if (!$driver) return null;
        } elseif (!$user->isStaffAccount()) {
            return null;
        }
        $member = CompanyUser::where('company_uuid', $company->uuid)->where('user_uuid', $user->uuid)->where('status', 'active')->first();
        if (!$driver && !$member && !($user->isAdmin() && $company->owner_uuid === $user->uuid)) return null;
        return compact('token', 'user', 'company', 'driver', 'member');
    }

    public static function issue(PersonalAccessToken $token, ?string $existing = null): ?string
    {
        $actor = self::actor($token);
        if (!$actor) return null;
        $identity = ['token_id' => $token->getKey(), 'user_uuid' => $actor['user']->uuid,
            'company_uuid' => $actor['company']->uuid, 'token_name' => $token->name];
        $existing = is_string($existing) && preg_match('/^[a-f0-9]{64}$/D', $existing) ? $existing : null;
        $stored = $existing ? Cache::get(self::key($existing)) : null;
        $ticket = is_array($stored) && $stored === $identity ? $existing : bin2hex(random_bytes(32));
        Cache::put(self::key($ticket), $identity, now()->addMinutes(self::TTL_MINUTES));
        return $ticket;
    }

    public static function resolve(string $ticket): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $ticket)) return null;
        $identity = Cache::get(self::key($ticket));
        if (!is_array($identity)) return null;
        $token = PersonalAccessToken::find($identity['token_id'] ?? null);
        $actor = self::actor($token);
        if (!$actor || $actor['user']->uuid !== ($identity['user_uuid'] ?? null)
            || $actor['company']->uuid !== ($identity['company_uuid'] ?? null)
            || $token->name !== ($identity['token_name'] ?? null)) return null;
        return $actor;
    }

    private static function key(string $ticket): string
    {
        return 'contrust:socket-ticket:' . hash('sha256', $ticket);
    }
}
