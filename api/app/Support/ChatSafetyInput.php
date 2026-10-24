<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Bounded request contracts; validation never reads a model or changes state. */
final class ChatSafetyInput
{
    public static function publicId($value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_\-]{1,150}$/D', $value) === 1;
    }

    public static function status(array $input): bool
    {
        return !array_diff(array_keys($input), ['blocked_after'])
            && (!array_key_exists('blocked_after', $input) || self::publicId($input['blocked_after']));
    }

    public static function consent(array $input): bool
    {
        return !array_diff(array_keys($input), ['policy_version', 'accepted'])
            && ($input['accepted'] ?? null) === true && ($input['policy_version'] ?? null) === ChatSafety::VERSION;
    }

    public static function block(array $input): bool
    {
        return array_keys($input) === ['user'] && self::publicId($input['user']);
    }

    public static function report(array $input): bool
    {
        return !array_diff(array_keys($input), ['request_id', 'type', 'target', 'reason', 'details', 'share_context'])
            && is_string($input['request_id'] ?? null) && Str::isUuid($input['request_id'])
            && in_array($input['type'] ?? null, ['user', 'message'], true) && self::publicId($input['target'] ?? null)
            && in_array($input['reason'] ?? null, ChatSafety::REASONS, true)
            && (!array_key_exists('details', $input) || (is_string($input['details']) && mb_strlen($input['details']) <= 2000))
            && ($input['share_context'] ?? null) === true;
    }

    public static function review(string $id, array $input): bool
    {
        return Str::isUuid($id) && !array_diff(array_keys($input), ['status', 'resolution'])
            && in_array($input['status'] ?? null, ['reviewing', 'resolved', 'dismissed'], true)
            && in_array($input['resolution'] ?? null, ChatSafety::RESOLUTIONS, true);
    }

    public static function reports(array $input): bool
    {
        return !array_diff(array_keys($input), ['before'])
            && (!array_key_exists('before', $input) || (is_string($input['before']) && Str::isUuid($input['before'])));
    }
}
