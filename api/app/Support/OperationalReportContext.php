<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Read-only selection for a future operational-content report.
 * Scope must come from a freshly authenticated server-side actor, never HTTP
 * input. This class does not grant staff permissions or publish a report.
 */
final class OperationalReportContext
{
    public const TYPES = ['order_comment', 'issue', 'fuel_report', 'proof'];
    private const ORDER_TYPE = 'Fleetbase\\FleetOps\\Models\\Order';
    private array $columns = [];

    public function __construct(private PDO $db)
    {
        if (!in_array($db->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Operational content lookup unavailable.');
        }
    }

    /**
     * staff_targets contains only exact records independently authorized by
     * the caller through the original resource policy (including row rules). A driver never inherits them, including a staff-linked driver.
     * No location, financial values, file bytes/paths/URLs or related feed is
     * selected. Null deliberately does not distinguish absent/forbidden data.
     */
    public function resolve(array $scope, string $type, string $publicId): ?array
    {
        $targets = $scope['staff_targets'] ?? null;
        if (!is_array($targets)) return null;
        foreach ($targets as $targetType => $ids) {
            if (!in_array($targetType, self::TYPES, true) || !is_array($ids) || count($ids) > 100) return null;
            foreach ($ids as $id) if (!self::publicId($id)) return null;
        }
        if (!in_array($type, self::TYPES, true) || !self::publicId($publicId)
            || !self::uuid($scope['company_uuid'] ?? null) || !self::uuid($scope['user_uuid'] ?? null)
            || !array_key_exists('driver_uuid', $scope)
            || ($scope['driver_uuid'] !== null && !self::uuid($scope['driver_uuid']))) {
            return null;
        }
        $company = $scope['company_uuid'];
        $user = $scope['user_uuid'];
        $driver = $scope['driver_uuid'];
        if (!$this->one('SELECT u.uuid FROM users u WHERE u.uuid = :user AND u.company_uuid = :company'
            . " AND u.status = 'active'" . $this->live('users', 'u'), compact('user', 'company'))
            || !$this->one('SELECT m.uuid FROM company_users m WHERE m.user_uuid = :user AND m.company_uuid = :company'
                . " AND m.status = 'active'" . $this->live('company_users', 'm'), compact('user', 'company'))) {
            return null;
        }
        if ($driver !== null) {
            if (!$this->one('SELECT d.uuid FROM drivers d WHERE d.uuid = :driver AND d.user_uuid = :user'
                . ' AND d.company_uuid = :company' . $this->live('drivers', 'd'), compact('driver', 'user', 'company'))) {
                return null;
            }
        } elseif (!in_array($publicId, $targets[$type] ?? [], true)) {
            return null;
        }
        $bindings = ['company' => $company, 'public_id' => $publicId];
        $own = $driver === null ? '' : ' AND r.driver_uuid = :driver AND r.reported_by_uuid = :reporter';
        if ($driver !== null) $bindings['driver'] = $driver;
        if ($type === 'issue' || $type === 'fuel_report') {
            if ($driver !== null) $bindings['reporter'] = $user;
            $table = $type === 'issue' ? 'issues' : 'fuel_reports';
            $text = $type === 'issue' ? ', r.report AS selected_text' : '';
            $row = $this->one('SELECT r.uuid, r.public_id, r.reported_by_uuid AS author_uuid' . $text
                . ' FROM ' . $table . ' r WHERE r.company_uuid = :company AND r.public_id = :public_id'
                . $own . $this->live($table, 'r'), $bindings);
        } else {
            $table = $type === 'order_comment' ? 'comments' : 'proofs';
            $join = $type === 'order_comment' ? 'r.subject_uuid' : 'r.order_uuid';
            $fields = $type === 'order_comment' ? ', r.author_uuid, r.content AS selected_text' : '';
            $where = $type === 'order_comment' ? ' AND r.subject_type = :order_type' : '';
            if ($type === 'order_comment') $bindings['order_type'] = self::ORDER_TYPE;
            $row = $this->one('SELECT r.uuid, r.public_id, o.public_id AS order_id' . $fields
                . ' FROM ' . $table . ' r JOIN orders o ON o.uuid = ' . $join . ' AND o.company_uuid = r.company_uuid'
                . ' WHERE r.company_uuid = :company AND r.public_id = :public_id' . $where
                . ($driver === null ? '' : ' AND o.driver_assigned_uuid = :driver')
                . $this->live($table, 'r') . $this->live('orders', 'o'), $bindings);
        }
        if (!$row) return null;
        // A proof subject/current assignee is not evidence of its uploader.
        $author = $row['author_uuid'] ?? null;
        if (!self::uuid($author) || !$this->one('SELECT u.uuid FROM users u JOIN company_users m ON m.user_uuid = u.uuid'
            . ' AND m.company_uuid = u.company_uuid WHERE u.uuid = :user AND u.company_uuid = :company'
            . " AND u.status = 'active' AND m.status = 'active'" . $this->live('users', 'u')
            . $this->live('company_users', 'm'), ['user' => $author, 'company' => $company])) {
            $author = null;
        }
        $result = ['type' => $type, 'id' => $row['public_id'], 'author_uuid' => $author];
        if (isset($row['order_id'])) $result['order_id'] = $row['order_id'];
        if (isset($row['selected_text']) && is_string($row['selected_text'])) {
            $result['selected_text'] = mb_substr($row['selected_text'], 0, 4000);
        }
        return $result;
    }

    private function one(string $sql, array $bindings): ?array
    {
        try {
            $query = $this->db->prepare($sql);
            $query->execute($bindings);
            return $query->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $exception) {
            // SQL parameters and schema details must not reach a response/log.
            throw new RuntimeException('Operational content lookup unavailable.');
        }
    }

    private function live(string $table, string $alias): string
    {
        if (!array_key_exists($table, $this->columns)) {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $query = $this->db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $query->execute([$table]);
                $this->columns[$table] = $query->fetchAll(PDO::FETCH_COLUMN);
            } else {
                // Names are private constants above, never request values.
                $query = $this->db->query('PRAGMA table_info(' . $table . ')');
                $this->columns[$table] = array_column($query->fetchAll(PDO::FETCH_ASSOC), 'name');
            }
        }
        return in_array('deleted_at', $this->columns[$table], true) ? ' AND ' . $alias . '.deleted_at IS NULL' : '';
    }

    private static function publicId($value): bool
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9_-]{1,150}\z/D', $value) === 1;
    }

    private static function uuid($value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/iD', $value) === 1;
    }
}
