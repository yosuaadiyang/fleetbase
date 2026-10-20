<?php

namespace App\Support;

use Fleetbase\Models\User;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/** Only current authenticated Android sessions receive generic wake-up messages. */
final class DriverPushDevices
{
    public const TABLE = 'contrust_push_devices';
    public const APPS = ['website.contrust.navigator', 'website.contrust.navigator.qa'];

    public static function validInput(array $input): bool
    {
        return !array_diff(array_keys($input), ['token', 'installation_id', 'app_identifier', 'platform'])
            && is_string($input['token'] ?? null)
            && preg_match('/^[A-Za-z0-9_:\-]{20,4096}$/D', $input['token']) === 1
            && (!isset($input['installation_id']) || (is_string($input['installation_id']) && Str::isUuid($input['installation_id'])))
            && ($input['platform'] ?? null) === 'android'
            && in_array($input['app_identifier'] ?? null, self::APPS, true);
    }

    public static function register(array $actor, array $input): string
    {
        return DB::transaction(function () use ($actor, $input) {
            // Serialize this user's cap and token rotation across concurrent requests.
            User::where('uuid', $actor['user']->uuid)->lockForUpdate()->firstOrFail();
            $installation = $input['installation_id'] ?? DB::table(self::TABLE)->where('token_hash', hash('sha256', $input['token']))->value('installation_id') ?? (string) Str::uuid();
            DB::table(self::TABLE)->where(fn ($q) => $q->where('installation_id', $installation)
                ->orWhere('token_hash', hash('sha256', $input['token'])))->delete();
            DB::table(self::TABLE)->insert([
                'uuid' => (string) Str::uuid(), 'token_hash' => hash('sha256', $input['token']),
                'installation_id' => $installation, 'encrypted_token' => Crypt::encryptString($input['token']),
                'token_id' => $actor['token']->getKey(), 'user_uuid' => $actor['user']->uuid,
                'company_uuid' => $actor['company']->uuid, 'app_identifier' => $input['app_identifier'],
                'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $stale = DB::table(self::TABLE)->where('user_uuid', $actor['user']->uuid)
                ->orderByDesc('last_seen_at')->orderByDesc('uuid')->skip(5)->take(1000)->pluck('uuid');
            if ($stale->isNotEmpty()) DB::table(self::TABLE)->whereIn('uuid', $stale)->delete();
            return $installation;
        }, 5);
    }

    public static function revokeSession(PersonalAccessToken $token): void
    {
        DB::transaction(function () use ($token) {
            DB::table(self::TABLE)->where('token_id', $token->getKey())->delete();
            $token->delete();
        });
    }

    /** Revalidate organization, driver and live PAT immediately before transport. */
    public static function eligible(User $user): array
    {
        $result = [];
        $rows = DB::table(self::TABLE)->where('user_uuid', $user->uuid)->where('company_uuid', $user->company_uuid)
            ->where('last_seen_at', '>', now()->subDays(30))->orderByDesc('last_seen_at')->orderByDesc('uuid')->limit(5)->get();
        foreach ($rows as $row) {
            $actor = SocketAccessTickets::actor(PersonalAccessToken::find($row->token_id));
            if (!$actor || !$actor['driver'] || $actor['user']->uuid !== $row->user_uuid
                || $actor['company']->uuid !== $row->company_uuid || !in_array($row->app_identifier, self::APPS, true)) continue;
            try { $token = Crypt::decryptString($row->encrypted_token); }
            catch (\Throwable $exception) { continue; }
            if (hash_equals($row->token_hash, hash('sha256', $token))) $result[] = $token;
        }
        return array_values(array_unique($result));
    }

    public static function forgetToken(string $token): void
    {
        DB::table(self::TABLE)->where('token_hash', hash('sha256', $token))->delete();
    }
}
