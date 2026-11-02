<?php

namespace App\Support;

use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;

/** Disabled until policy and contribution/report/review clients are accepted. */
final class OperationalReports
{
    public const POLICY_VERSION = '2026-11-01.1';

    public static function enabled(): bool
    {
        return ChatSafety::enabled() && config('contrust.operational_reports_enabled') === true;
    }

    private static function actorKey(array $actor): string
    {
        return json_encode([$actor['company']->uuid, $actor['user']->uuid, $actor['driver']?->uuid], JSON_THROW_ON_ERROR);
    }

    private static function receipt(): OperationalReportPreview
    {
        return new OperationalReportPreview((string) config('app.key'));
    }

    private static function selection(array $actor, array $input): ?array
    {
        $record = OperationalContentAccess::context($actor, $input['type'], $input['target']);
        if (!$record) return null;
        $author = $record['author_uuid'] ? OrganizationChat::members($actor['company']->uuid)->where('uuid', $record['author_uuid'])->first() : null;
        $text = $record['selected_text'] ?? ($record['type'] === 'proof' ? 'Referensi bukti pengiriman: ' : 'Referensi laporan bahan bakar: ') . $record['id'];
        return ['record' => $record, 'context' => ['type' => $record['type'],
            'target' => ['id' => $author?->public_id, 'name' => $author?->name ?? 'Tanpa atribusi pengguna aktif'],
            'message' => ['id' => $record['id'], 'content' => $text],
            'order_id' => $record['order_id'] ?? null, 'policy_version' => self::POLICY_VERSION]];
    }

    public static function preview(array $actor, array $input)
    {
        if (!OperationalReportInput::preview($input)) return ChatSafety::invalid();
        $selection = self::selection($actor, $input);
        if (!$selection) return ChatSafety::denied();
        return ChatSafety::reply(['type' => $selection['record']['type'], 'target' => $selection['record']['id'],
            'context' => $selection['context'], 'policy_version' => self::POLICY_VERSION,
            'preview_receipt' => self::receipt()->issue(self::actorKey($actor), $selection['context'], time()),
            'expires_in' => OperationalReportPreview::TTL]);
    }

    public static function report(array $actor, array $input)
    {
        if (!OperationalReportInput::report($input)) return ChatSafety::invalid();
        if ($denial = ChatSafety::contribution($actor['user'], $actor['company']->uuid)) return $denial;
        $company = $actor['company']->uuid; $user = $actor['user']->uuid;
        $digest = hash('sha256', json_encode([$input['type'], $input['target'], $input['preview_receipt'],
            $input['reason'], $input['details'] ?? '', $input['policy_version']], JSON_THROW_ON_ERROR));
        $old = DB::table('contrust_chat_reports')->where('company_uuid', $company)->where('reporter_uuid', $user)->where('request_id', $input['request_id'])->first();
        if ($old) return hash_equals($old->request_hash, $digest)
            ? ChatSafety::reply(['id' => $old->uuid, 'status' => $old->status])
            : ChatSafety::reply(['code' => 'CHAT_REPORT_CONFLICT', 'errors' => ['Report request already used.']], 409);
        $selection = self::selection($actor, $input);
        if (!$selection) return ChatSafety::denied();
        if (!self::receipt()->matches($input['preview_receipt'], self::actorKey($actor), $selection['context'], time())) {
            return ChatSafety::reply(['code' => 'CONTENT_REPORT_CHANGED', 'errors' => ['Preview changed or expired. Review again before sharing.']], 409);
        }
        $context = $selection['context'] + ['details' => trim(strip_tags($input['details'] ?? ''))];
        $id = (string) Str::orderedUuid();
        DB::table('contrust_chat_reports')->insert(['uuid' => $id, 'company_uuid' => $company, 'reporter_uuid' => $user,
            'target_user_uuid' => $selection['record']['author_uuid'], 'chat_message_uuid' => null,
            'request_id' => $input['request_id'], 'request_hash' => $digest, 'reason' => $input['reason'],
            'encrypted_context' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)),
            'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        return ChatSafety::reply(['id' => $id, 'status' => 'open'], 201);
    }
}
