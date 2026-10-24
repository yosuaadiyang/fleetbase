<?php

declare(strict_types=1);

namespace Contrust\Privacy;

use PDO;
use RuntimeException;
use InvalidArgumentException;

/**
 * Count-only preflight for a verified operator's account erasure review.
 * This is not a deletion plan: a relationship does not prove ownership of a row.
 * No identifiers, contents, paths, credentials or coordinates enter the result.
 */
final class AccountDataInventory
{
    private const MAX_RELATED_IDS = 1000;

    private array $columns = [];

    private array $relationshipGaps = [];

    public function __construct(private PDO $db)
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new InvalidArgumentException('Unsupported inventory database.');
        }
        // Table/column names come from this connection's metadata, then pass a
        // strict identifier check before interpolation. Values are always bound.
        $sql = $driver === 'mysql'
            ? 'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            : "SELECT m.name AS table_name, p.name AS column_name FROM sqlite_master m JOIN pragma_table_info(m.name) p WHERE m.type = 'table'";
        foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (self::validIdentifier($column['table_name']) && self::validIdentifier($column['column_name'])) {
                $this->columns[$column['table_name']][] = $column['column_name'];
            }
        }
    }

    public function summarize(string $userUuid): array
    {
        $this->relationshipGaps = [];
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $userUuid)) {
            throw new InvalidArgumentException('An exact account UUID is required.');
        }
        if ($this->count('users', ['uuid' => [$userUuid]]) !== 1) {
            throw new RuntimeException('One existing account must be selected.');
        }

        $user = [$userUuid];
        $members = $this->ids('company_users', ['user_uuid' => $user]);
        $drivers = $this->ids('drivers', ['user_uuid' => $user]);
        $contacts = $this->ids('contacts', ['user_uuid' => $user]);
        $participants = $this->ids('chat_participants', ['user_uuid' => $user, 'company_user_uuid' => $members]);
        $messages = $this->ids('chat_messages', ['sender_uuid' => $participants]);
        $identities = array_values(array_unique(array_merge($user, $members, $drivers, $contacts)));
        $work = ['driver_uuid' => $drivers, 'reported_by_uuid' => $user, 'created_by_uuid' => $user];
        $orders = $this->ids('orders', ['driver_assigned_uuid' => $drivers, 'customer_uuid' => $contacts, 'created_by_uuid' => $user]);
        $issues = $this->ids('issues', $work);
        $fuel = $this->ids('fuel_reports', $work);
        $proofs = $this->ids('proofs', ['order_uuid' => $orders, 'subject_uuid' => $identities]);
        $subjects = array_values(array_unique(array_merge($identities, $orders, $issues, $fuel, $proofs, $messages)));
        if (count($subjects) > self::MAX_RELATED_IDS) {
            throw new RuntimeException('Inventory scope exceeds the bounded review limit; use a reviewed paginated inventory.');
        }

        $queries = [
            'users' => ['uuid' => $user],
            'company_users' => ['user_uuid' => $user],
            'drivers' => ['user_uuid' => $user],
            'contacts' => ['user_uuid' => $user],
            // Polymorphic type is deliberately not inferred here. These counts
            // are possible matches for review, never authority to delete tokens.
            'personal_access_tokens' => ['tokenable_id' => $identities],
            'user_devices' => ['user_uuid' => $user],
            'contrust_push_devices' => ['user_uuid' => $user],
            'contrust_chat_consents' => ['user_uuid' => $user],
            'contrust_chat_blocks' => ['user_uuid' => $user, 'blocked_user_uuid' => $user],
            'contrust_chat_reports' => ['reporter_uuid' => $user, 'target_user_uuid' => $user, 'reviewer_uuid' => $user],
            'positions' => ['subject_uuid' => $drivers],
            'orders' => ['uuid' => $orders],
            'issues' => ['uuid' => $issues],
            'fuel_reports' => ['uuid' => $fuel],
            'proofs' => ['uuid' => $proofs],
            'chat_participants' => ['uuid' => $participants],
            'chat_messages' => ['uuid' => $messages],
            'chat_receipts' => ['chat_participant_uuid' => $participants, 'participant_uuid' => $participants],
            'chat_attachments' => ['chat_message_uuid' => $messages],
            'comments' => ['author_uuid' => array_merge($user, $members), 'subject_uuid' => $subjects],
            'files' => ['uploader_uuid' => array_merge($user, $members), 'subject_uuid' => $subjects],
            'activity_log' => ['causer_id' => $identities, 'subject_id' => $subjects],
        ];
        $tables = [];
        foreach ($queries as $table => $selectors) {
            $count = $this->count($table, $selectors);
            $tables[$table] = [
                'coverage' => !isset($this->columns[$table]) ? 'table_not_found' : ($count === null ? 'unrecognized_schema' : 'known_relationships_only'),
                'candidate_rows' => $count,
            ];
        }

        return [
            'mode' => 'read_only_inventory',
            'execution_supported' => false,
            'complete_erasure_verified' => false,
            'retention_trigger' => 'verified_account_deletion',
            'tables' => $tables,
            'relationship_gaps' => array_values(array_unique($this->relationshipGaps)),
            'required_reviews' => [
                'Actual deployed schema, polymorphic types and relationships not covered by this inventory',
                'Personal information embedded in shared records, JSON, free text and files uploaded by other users',
                'Storage originals, derivatives, cache and orphaned objects; file rows alone are insufficient',
                'All relevant database schemas, external provider copies and encrypted backup snapshots',
                'Exact scoped deletion/redaction approval followed by independent result verification',
            ],
        ];
    }

    private static function validIdentifier(string $value): bool
    {
        return preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $value) === 1;
    }

    private function where(string $table, array $selectors): ?array
    {
        if (!isset($this->columns[$table])) {
            return null;
        }
        $clauses = [];
        $bindings = [];
        foreach ($selectors as $column => $values) {
            if (!in_array($column, $this->columns[$table], true)) {
                continue;
            }
            // Keep known-but-empty relations distinguishable from an unknown
            // schema, without turning an empty IN clause into an unscoped scan.
            if ($values === []) {
                $clauses[] = '0 = 1';
                continue;
            }
            if (count($values) > self::MAX_RELATED_IDS) {
                throw new RuntimeException('Inventory relationship exceeds the bounded review limit.');
            }
            $clauses[] = '`' . $column . '` IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
            array_push($bindings, ...$values);
        }

        return $clauses === [] ? null : ['(' . implode(' OR ', $clauses) . ')', $bindings];
    }

    private function count(string $table, array $selectors): ?int
    {
        $where = $this->where($table, $selectors);
        if ($where === null) {
            return null;
        }
        $query = $this->db->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where[0]);
        $query->execute($where[1]);

        return (int) $query->fetchColumn();
    }

    private function ids(string $table, array $selectors): array
    {
        $where = $this->where($table, $selectors);
        if ($where === null || !in_array('uuid', $this->columns[$table], true)) {
            $this->relationshipGaps[] = $table;
            return [];
        }
        $query = $this->db->prepare('SELECT DISTINCT uuid FROM `' . $table . '` WHERE ' . $where[0] . ' LIMIT ' . (self::MAX_RELATED_IDS + 1));
        $query->execute($where[1]);
        $ids = $query->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > self::MAX_RELATED_IDS) {
            throw new RuntimeException('Inventory relationship exceeds the bounded review limit.');
        }

        return array_values(array_filter($ids, static fn ($id) => is_string($id) && $id !== ''));
    }
}
