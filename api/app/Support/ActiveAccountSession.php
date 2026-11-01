<?php

namespace App\Support;

use Fleetbase\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/** Revalidate offboarding on each request, including existing bearer sessions. */
final class ActiveAccountSession
{
    public static function driverProfile(User $user, $token): bool
    {
        // Cookie-authenticated Sanctum uses a TransientToken without `name`.
        $name = $token instanceof PersonalAccessToken ? $token->name : (is_string($token) ? $token : null);
        return $user->isType('driver') || (is_string($name) && Str::isUuid($name) && $name !== $user->uuid);
    }

    public static function allows(User $user, $company, bool $requireMembership = false): bool
    {
        if (!is_string($company) || !Str::isUuid($company)) return false;
        $db = $user->getConnection();
        $principal = $db->table('users')->where('uuid', $user->uuid)->whereNull('deleted_at')->where('status', 'active')->first(['type']);
        $organization = $db->table('companies')->where('uuid', $company)->whereNull('deleted_at')->first(['owner_uuid']);
        if (!$principal || !$organization) return false;
        $member = $db->table('company_users')->where('company_uuid', $company)->where('user_uuid', $user->uuid)
            ->where('status', 'active')->whereNull('deleted_at')->exists();
        // Match the already deployed socket-session owner exception. A plain
        // global admin or a cached role cannot grant access to another company.
        return $member || (!$requireMembership && $principal->type === 'admin' && $organization->owner_uuid === $user->uuid);
    }
}
