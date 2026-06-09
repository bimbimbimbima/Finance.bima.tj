<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/DB.php';
header('Content-Type: text/html; charset=utf-8');
$checks = [];
$checks[] = ['PHP version', PHP_VERSION, true];
$checks[] = ['HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'yes' : 'no', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')];
$checks[] = ['Session status', session_status() === PHP_SESSION_ACTIVE ? 'active' : 'inactive', session_status() === PHP_SESSION_ACTIVE];
$checks[] = ['Cookie secure', ini_get('session.cookie_secure'), ini_get('session.cookie_secure') == '1'];
$checks[] = ['Cookie httponly', ini_get('session.cookie_httponly'), ini_get('session.cookie_httponly') == '1'];
$checks[] = ['Cookie samesite', ini_get('session.cookie_samesite') ?: 'not set', strtolower((string)ini_get('session.cookie_samesite')) === 'none'];
try {
    DB::connect();
    $checks[] = ['Database', 'connected', true];
} catch (Throwable $e) {
    $checks[] = ['Database', $e->getMessage(), false];
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Finance B24 health</title>
<style>body{font-family:Arial;padding:24px;background:#f6f8fb}.box{background:#fff;border-radius:12px;padding:20px;max-width:900px;margin:auto;box-shadow:0 6px 22px #0001}td,th{padding:8px 10px;border-bottom:1px solid #e5e7eb;text-align:left}.ok{color:#15803d}.bad{color:#dc2626}code{background:#f1f5f9;padding:2px 5px;border-radius:4px}</style></head><body><div class="box"><h2>Finance B24 diagnostics</h2><table><thead><tr><th>Check</th><th>Value</th><th>Status</th></tr></thead><tbody>
<?php foreach ($checks as [$k,$v,$ok]): ?><tr><td><?=htmlspecialchars($k)?></td><td><?=htmlspecialchars((string)$v)?></td><td class="<?=$ok?'ok':'bad'?>"><?=$ok?'OK':'CHECK'?></td></tr><?php endforeach; ?>
</tbody></table><p>Для Bitrix24 в Chrome/Edge должны быть: HTTPS = OK, Cookie secure = OK, Cookie samesite = None.</p></div></body></html>
