<?php
/* UCC 排班系統（PHP 版）— 唯一對外 PHP 入口
 *   POST api.php  body={"fn":"getMonth","arg":{…}}  → 直接回該函式的結果（形狀與 Apps Script 版 google.script.run 相同）
 *   GET  api.php?diag=1 診斷；?smoke=1 煙霧測試 */
define('UCC', 1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// 設定檔：有 lib/config.php 就用它，沒有就用 lib/config.example.php（clone 下來可直接試用）
require __DIR__ . '/lib/' . (is_file(__DIR__ . '/lib/config.php') ? 'config' : 'config.example') . '.php';
foreach (['core', 'auth', 'sched', 'admin'] as $f) require __DIR__ . '/lib/' . $f . '.php';
date_default_timezone_set(CFG()['TZ']);
ini_set('error_log', dataDir_() . '/php-error.log');

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function out_($arr, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}
function text_($s, $code = 200) {
  http_response_code($code);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store');
  echo $s;
  exit;
}
set_error_handler(function ($no, $str, $file, $line) {
  if (!(error_reporting() & $no)) return false;
  if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED || $no === E_NOTICE) return true;
  throw new ErrorException($str, 0, $no, $file, $line);
});

/** 前端可呼叫的函式 → PHP 函式名 */
const API_MAP = [
  'getBootstrap' => 'apiGetBootstrap', 'login' => 'apiLogin', 'changePassword' => 'apiChangePassword',
  'getMonth' => 'apiGetMonth', 'submitShift' => 'apiSubmitShift', 'cancelShift' => 'apiCancelShift', 'setDone' => 'apiSetDone',
  'setLock' => 'apiSetLock', 'clearLock' => 'apiClearLock', 'setSlotOpen' => 'apiSetSlotOpen', 'saveNotes' => 'apiSaveNotes',
  'adminSkip' => 'apiAdminSkip', 'createNextMonth' => 'apiCreateNextMonth', 'createMonthsThrough' => 'apiCreateMonthsThrough',
  'adminResetPw' => 'apiAdminResetPw', 'adminPwStatus' => 'apiAdminPwStatus', 'adminUsers' => 'apiAdminUsers', 'setPerms' => 'apiSetPerms',
  'rosterAdd' => 'apiRosterAdd', 'rosterRemove' => 'apiRosterRemove', 'getUiSettings' => 'apiGetUiSettings', 'saveUiSettings' => 'apiSaveUiSettings',
  'exportAll' => 'apiExportAll', 'importAll' => 'apiImportAll', 'exportXlsx' => 'apiExportXlsx',
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
  if ($method === 'GET') {
    if (isset($_GET['diag'])) { ensureSetup_(); text_(diagText_()); }
    if (isset($_GET['smoke'])) { ensureSetup_(); text_(smokeText_()); }
    header('Location: ./', true, 302);
    exit;
  }
  if ($method !== 'POST') out_(['ok' => false, 'msg' => '不支援的請求方式'], 405);
  $max = 6 * 1024 * 1024;   // 整批搬移的 JSON 也放得下
  $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  if ($len > $max) out_(['ok' => false, 'msg' => '資料太大'], 413);
  $raw = file_get_contents('php://input', false, null, 0, $max + 1);
  if ($raw === false || strlen($raw) > $max) out_(['ok' => false, 'msg' => '資料太大'], 413);
  $req = json_decode($raw, true);
  if (!is_array($req)) out_(['ok' => false, 'msg' => '請求格式錯誤'], 400);
  $fn = (string)($req['fn'] ?? '');
  if (!isset(API_MAP[$fn])) out_(['ok' => false, 'msg' => '未知的功能：' . $fn], 404);
  $arg = $req['arg'] ?? [];
  if (!is_array($arg)) $arg = [];
  ensureSetup_();
  out_(call_user_func(API_MAP[$fn], $arg));
} catch (Throwable $e) {
  $m = $e->getMessage();
  error_log('[' . date('c') . '] ' . get_class($e) . ': ' . $m . ' @ ' . $e->getFile() . ':' . $e->getLine());
  if (stripos($m, 'database is locked') !== false || stripos($m, 'busy') !== false) out_(['ok' => false, 'msg' => '系統忙碌中，請稍候幾秒再試一次']);
  out_(['ok' => false, 'msg' => ($e instanceof Exception && !($e instanceof ErrorException) && preg_match('/\p{Han}/u', $m)) ? $m : '系統發生錯誤，請稍後再試；若持續發生請通知管理者']);
}
