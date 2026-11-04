<?php
// Smoke-test credential helper: set | restore
require_once __DIR__ . '/../config/db.php';

$mode = $argv[1] ?? '';
$bak  = sys_get_temp_dir() . '/pms_hash.bak';
$pdo  = db();

if ($mode === 'set') {
    $h = $pdo->query('SELECT password_hash FROM user_account WHERE user_id = 1')->fetchColumn();
    file_put_contents($bak, $h);
    $pdo->prepare('UPDATE user_account SET password_hash = ? WHERE user_id = 1')
        ->execute([password_hash('pms-test-2026', PASSWORD_DEFAULT)]);
    echo "saved";
} elseif ($mode === 'restore') {
    if (is_file($bak)) {
        $pdo->prepare('UPDATE user_account SET password_hash = ? WHERE user_id = 1')
            ->execute([file_get_contents($bak)]);
        unlink($bak);
        echo "hash restored";
    }
}
