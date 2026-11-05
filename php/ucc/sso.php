<?php
/* 快速登入（LINE／Google）的進入點與回呼：只做轉址，邏輯在 lib/sso.php
 *   GET  sso.php?start=line|google   登入：導到提供者
 *   GET  sso.php?go=<一次性票>        綁定：票由 apiSsoBindTicket 發
 *   GET  sso.php?code=…&state=…      LINE 回呼
 *   POST sso.php (id_token, state)   Google 回呼（form_post）
 * 成功登入 → 回首頁並把 token 放在 #sso_tok=…；其他結果 → 首頁?sso=bound|unbound|cancel|error&m=… */
define('UCC', 1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
foreach (['config', 'core', 'auth', 'sched', 'admin', 'sso'] as $f) require __DIR__ . '/lib/' . $f . '.php';
date_default_timezone_set(CFG()['TZ']);
ini_set('error_log', dataDir_() . '/php-error.log');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
set_error_handler(function ($no, $str, $file, $line) {
  if (!(error_reporting() & $no)) return false;
  if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED || $no === E_NOTICE) return true;
  throw new ErrorException($str, 0, $no, $file, $line);
});
try {
  ensureSetup_();
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($method === 'GET' && (isset($_GET['start']) || isset($_GET['go']))) {
    $url = ssoHandleStart_(str_($_GET['start'] ?? ''), str_($_GET['go'] ?? ''));
  } elseif (isset($_REQUEST['state'])) {
    $url = ssoHandleCallback_($_REQUEST);
  } else {
    $url = ssoBaseUrl_();
  }
} catch (Throwable $e) {
  error_log('[' . date('c') . '] sso ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  $url = ssoBaseUrl_() . '?sso=error&m=server';
}
header('Location: ' . $url, true, 302);
exit;
