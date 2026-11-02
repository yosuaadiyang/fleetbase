<?php

namespace App\Support;

use Illuminate\Support\Str;

final class OperationalReportInput
{
    public static function preview(array $input): bool
    {
        return !array_diff(array_keys($input), ['type', 'target'])
            && in_array($input['type'] ?? null, OperationalReportContext::TYPES, true)
            && ChatSafetyInput::publicId($input['target'] ?? null);
    }

    public static function report(array $input): bool
    {
        return !array_diff(array_keys($input), ['type', 'target', 'request_id', 'preview_receipt', 'reason', 'details', 'share_context', 'policy_version'])
            && self::preview(array_intersect_key($input, array_flip(['type', 'target'])))
            && is_string($input['request_id'] ?? null) && Str::isUuid($input['request_id'])
            && is_string($input['preview_receipt'] ?? null) && strlen($input['preview_receipt']) >= 10 && strlen($input['preview_receipt']) <= 1024
            && in_array($input['reason'] ?? null, ChatSafety::REASONS, true)
            && (!array_key_exists('details', $input) || (is_string($input['details']) && mb_strlen($input['details']) <= 2000))
            && ($input['share_context'] ?? null) === true
            && ($input['policy_version'] ?? null) === OperationalReports::POLICY_VERSION;
    }
}
