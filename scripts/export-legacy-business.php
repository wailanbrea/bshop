<?php

// Read-only conversion from a verified backup; the former live database is never changed.
$root = dirname(__DIR__);
$file = $argv[1] ?? '';
if (realpath(dirname($file)) !== realpath($root.'/.runtime') || ! preg_match('/^bshop-backup-[0-9-]+\.sqlite$/', basename($file))) {
    throw new RuntimeException('Use an explicit verified BShop backup inside .runtime.');
}
$db = new SQLite3($file, SQLITE3_OPEN_READONLY);
if ($db->querySingle('PRAGMA integrity_check') !== 'ok') {
    throw new RuntimeException('Source backup failed integrity verification.');
}
$query = function (string $sql) use ($db): array {
    $rows = [];
    $result = $db->query($sql);
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
};
$business = $query("SELECT * FROM tenants WHERE slug = 'keylashop'")[0] ?? throw new RuntimeException('KeylaShop source profile not found.');
$id = (int) $business['id'];
$members = $query("SELECT * FROM tenant_memberships WHERE tenant_id = $id");
$userIds = array_column($members, 'user_id');
if ($userIds === []) {
    throw new RuntimeException('Business has no users.');
}
$memberUser = array_column($members, 'user_id', 'id');
$users = $query('SELECT * FROM users WHERE id IN ('.implode(',', array_map('intval', $userIds)).')');
foreach ($users as &$user) {
    unset($user['is_platform_admin']);
    foreach ($members as $member) {
        if ($member['user_id'] === $user['id'] && $member['status'] !== 'active') {
            $user['is_active'] = 0;
        }
    }
}
unset($user);
$tables = ['users' => $users];
$rename = ['tenant_roles' => 'roles', 'tenant_membership_roles' => 'role_user', 'membership_store_access' => 'store_user', 'tenant_invitations' => 'team_invitations', 'tenant_settings' => 'business_settings', 'tenant_storage_quotas' => 'storage_quota'];
$tableNames = $query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
foreach ($tableNames as $entry) {
    $name = $entry['name'];
    if (in_array($name, ['tenants', 'tenant_memberships', 'users', 'saas_plans', 'tenant_subscriptions'], true)) {
        continue;
    }
    $columns = array_column($query('PRAGMA table_info("'.$name.'")'), 'name');
    if (! in_array('tenant_id', $columns, true)) {
        continue;
    }
    $target = $rename[$name] ?? $name;
    $rows = $query('SELECT * FROM "'.$name.'" WHERE tenant_id = '.$id);
    foreach ($rows as &$row) {
        unset($row['tenant_id']);
        if (in_array($name, ['tenant_settings', 'tenant_storage_quotas'], true)) {
            $row['id'] = 1;
        }
        if (array_key_exists('membership_id', $row)) {
            $row['user_id'] = $row['membership_id'] === null ? null : ($memberUser[$row['membership_id']] ?? null);
            unset($row['membership_id']);
        }
        if (isset($row['invited_by_membership_id'])) {
            $row['invited_by_user_id'] = $memberUser[$row['invited_by_membership_id']];
            unset($row['invited_by_membership_id']);
        }
        if (isset($row['performed_by_membership_id'])) {
            $row['performed_by_user_id'] = $memberUser[$row['performed_by_membership_id']] ?? null;
            unset($row['performed_by_membership_id']);
        }
        if (isset($row['created_by_membership_id'])) {
            $row['created_by_user_id'] = $memberUser[$row['created_by_membership_id']];
            unset($row['created_by_membership_id']);
        }
        if (isset($row['uploaded_by_membership_id'])) {
            $row['uploaded_by_user_id'] = $memberUser[$row['uploaded_by_membership_id']] ?? null;
            unset($row['uploaded_by_membership_id']);
        }
        unset($row['default_store_id'], $row['primary_product_id']);
        if ($target === 'roles') {
            $row['permissions'] = str_replace('tenant.manage', 'business.manage', $row['permissions']);
        }
    }
    unset($row);
    $tables[$target] = $rows;
}
unset($business['slug'], $business['status']);
$business['id'] = 1;
$tables['business_profile'] = [$business];
$output = $root.'/.runtime/single-business-source.json';
if (file_exists($output)) {
    throw new RuntimeException('Source export already exists; refusing to overwrite.');
}
file_put_contents($output, json_encode(['source_backup' => $file, 'source_sha256' => hash_file('sha256', $file), 'tables' => $tables], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Read-only source: $file; KeylaShop business ID $id.\n";
foreach ($tables as $table => $rows) {
    if ($rows !== []) {
        echo "$table: ".count($rows)." records retained.\n";
    }
}
echo "Verified export stored privately in .runtime; previous database and other demo profiles remain intact.\n";
