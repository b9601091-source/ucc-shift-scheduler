<?php
/* 後端測試（PHP 版）：php php/tests/run.php（最後一行印「通過 N／失敗 N」，有失敗時 exit 1）
 * 每個測試環境＝系統暫存資料夾下一個全新 SQLite，結束時清掉。 */
define('UCC', 1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
$LIB = dirname(__DIR__) . '/ucc/lib';
require $LIB . '/' . (is_file($LIB . '/config.php') ? 'config' : 'config.example') . '.php';
foreach (['core', 'auth', 'sched', 'admin'] as $f) require $LIB . '/' . $f . '.php';
date_default_timezone_set(CFG()['TZ']);
set_error_handler(function ($no, $str, $file, $line) {
  if (!(error_reporting() & $no)) return false;
  if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED || $no === E_NOTICE) return true;
  throw new ErrorException($str, 0, $no, $file, $line);
});
$PASS = 0; $FAILS = 0;
function j_($v) { $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR); return strlen($s) > 900 ? substr($s, 0, 900) . '…' : $s; }
function ok($cond, $label, $extra = null) { global $PASS, $FAILS; if ($cond) { $PASS++; return; } $FAILS++; echo '✗ ' . $label . (func_num_args() >= 3 ? '  → ' . j_($extra) : '') . "\n"; }
class Env {
  public $dir, $today; public static $all = [];
  function __construct($today) { $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ucc_test_' . getmypid() . '_' . count(self::$all) . '_' . bin2hex(random_bytes(3)); mkdir($this->dir, 0755, true); $this->today = $today; self::$all[] = $this; }
  function use_() { $GLOBALS['UCC_DATA_DIR'] = $this->dir; $GLOBALS['UCC_TODAY'] = $this->today; db_(); return $this; }
  /** 回傳值經與 api.php 相同的 JSON 編碼再解回：斷言看到的就是前端收到的 */
  function __call($fn, $args) { $this->use_(); $out = call_user_func_array($fn, $args); return json_decode(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), true); }
}
function env($today = '2026-09-08') { $C = new Env($today); $C->ensureSetup_(); return $C; }
/** 依 Apps Script 版 exportAll 的格式造一份匯出資料 */
function gasExport($names, $months, $fills = [], $props = []) {
  $ms = [];
  foreach ($months as $mn) {
    $info = decodeMonth_($mn); $y = $info['y']; $m = $info['m'];
    $nd = (int)(new DateTimeImmutable(isoOf_($y, $m, 1)))->format('t');
    $days = [];
    // 模擬格線含前後月補位（匯入應略過非當月）
    $days[] = ['iso' => isoAdd_(isoOf_($y, $m, 1), -1), 'inMonth' => false, 'amClosed' => true, 'pmClosed' => true, 'am' => null, 'pm' => null];
    for ($d = 1; $d <= $nd; $d++) {
      $iso = isoOf_($y, $m, $d); $open = dow_($iso) === 0;
      $day = ['iso' => $iso, 'inMonth' => true, 'amClosed' => !$open, 'pmClosed' => !$open, 'am' => $open ? '' : null, 'pm' => $open ? '' : null];
      foreach ($fills as $f) if ($f[0] === $iso) $day[$f[1]] = $f[2];
      $days[] = $day;
    }
    $order = []; foreach (array_values($names) as $i => $n) $order[] = ['seq' => chr(65 + $i), 'name' => $n, 'done' => false];
    $ms[] = ['name' => $mn, 'days' => $days, 'order' => $order, 'notes' => $mn === $months[0] ? "1. 測試注意事項\n2. 第二行" : ''];
  }
  return ['format' => 'ucc-export-1', 'months' => $ms, 'openDays' => [['iso' => '2026-10-10', 'open' => '是', 'note' => '國慶日'], ['iso' => '2026-10-24', 'open' => '否', 'note' => '光復節']],
    'props' => $props, 'log' => [['ts' => '2026-09-01 10:00:00', 'action' => '登入', 'name' => '王小明']]];
}
$NAMES = ['王小明', '陳小美', '林小華'];

/* ================= 1. 空資料庫＋SETUP_ROSTER ================= */
echo "=== 空資料庫\n";
$E = env('2026-09-08');
$b = $E->apiGetBootstrap([]);
ok($b['months'] === [] && count($b['roster']) === 1 && $b['roster'][0]['label'] === 'Ｚ．會務人員', '沒有月份時只有會務帳號', $b['roster']);
ok(isset($b['ui']['pilotNote']) && $b['ui']['appVersion'] === APP_VERSION && count($b['ui']['changelog']) >= 1, 'bootstrap 帶 ui／版次／更新紀錄');
$t = $E->apiLogin(['seq' => 'Z', 'pw' => '0000']);
ok($t['ok'] && $t['readOnly'] === true, '會務人員可登入');

/* ================= 2. 整批搬移（Apps Script 格式） ================= */
echo "=== 整批搬移\n";
$secret = 'abc123secret';
$hashB = hash('sha256', $secret . '|陳小美|5678');
$exp = gasExport($NAMES, ['115/09', '115/10'], [['2026-10-04', 'pm', '林小華'], ['2026-09-06', 'am', '王小明']],
  ['AUTH_SECRET' => $secret, 'pw_陳小美' => $hashB, 'perm_會務人員' => '["lock","roster"]', 'LOCK' => '', 'UI_OVERRIDE' => '{"titleMobile":"藥師 搬移測試"}']);
$r = $E->apiImportAll(['token' => 'x', 'data' => $exp]);
ok(!$r['ok'] && !empty($r['needLogin']), '未登入不能匯入');
$r = $E->apiImportAll(['token' => $t['token'], 'data' => $exp]);
ok($r['ok'] && $r['counts']['months'] === 2 && $r['counts']['slots'] === 122 && $r['counts']['roster'] === 6 && $r['counts']['users'] === 2 && $r['counts']['openDays'] === 2 && $r['counts']['log'] === 1, '匯入計數（30+31 天×2 班、非當月補位被略過）', $r);
$b = $E->apiGetBootstrap([]);
ok(array_map(function ($m) { return $m['name']; }, $b['months']) === ['115/10', '115/09'] && $b['defaultMonth'] === '115/10', '月份新→舊、預設次月', $b['months']);
ok(array_map(function ($r) { return $r['label']; }, $b['roster']) === ['Ａ．王○明', 'Ｂ．陳○美', 'Ｃ．林○華', 'Ｚ．會務人員'], '登入下拉遮罩', $b['roster']);
ok($b['ui']['titleMobile'] === '藥師 搬移測試', 'UI_OVERRIDE 一併搬來');
ok($E->apiLogin(['seq' => 'B', 'pw' => '5678'])['ok'] === true && $E->apiLogin(['seq' => 'B', 'pw' => '0000'])['ok'] === false, '搬移後原密碼可登入、預設密碼不行（同 secret 同公式）');
$r2 = $E->apiImportAll(['token' => $t['token'], 'data' => $exp]);
ok(!$r2['ok'] && !empty($r2['needLogin']), '匯入後 secret 換了 → 舊 token 失效要重新登入');
$tZ = $E->apiLogin(['seq' => 'Z', 'pw' => '0000']);   // Z 密碼未搬（沒有 pw_會務人員）→ 仍 0000
ok($tZ['ok'], 'Z 仍用預設密碼');
$r2 = $E->apiImportAll(['token' => $tZ['token'], 'data' => $exp]);
ok(!$r2['ok'] && strpos($r2['msg'], '已有月份') !== false, '已有資料不帶 replace 會擋', $r2);

/* ================= 3. 月份資料 ================= */
echo "=== 月份資料\n";
$tA = $E->apiLogin(['seq' => 'A', 'pw' => '0000']); $tB = $E->apiLogin(['seq' => 'B', 'pw' => '5678']);
$m10 = $E->apiGetMonth(['token' => $tA['token'], 'month' => '115/10']);
ok(count($m10['days']) === 35 && count(array_filter($m10['days'], function ($d) { return $d['inMonth']; })) === 31, '115/10 格線 5 週、31 天', count($m10['days']));
ok(count(array_filter($m10['days'], function ($d) { return $d['open']; })) === 4, '週日 4 天開放');
$d4 = null; foreach ($m10['days'] as $d) if ($d['iso'] === '2026-10-04') $d4 = $d;
ok($d4 && $d4['pm'] === '林小華' && $d4['am'] === '' && $d4['longHoliday'] === false, '10/4 晚班林小華', $d4);
ok($m10['days'][0]['inMonth'] === false && $m10['days'][0]['open'] === false && $m10['days'][0]['am'] === null, '補位日不開放');
ok($m10['prevMonths'] === ['9月'] && $m10['prevCounts'] === ['王小明' => 1], '前月班數', [$m10['prevMonths'], $m10['prevCounts']]);
ok($m10['viewer']['canAdmin'] === false && $m10['viewer']['defaultPw'] === '0000', 'A 無後臺');
$mB = $E->apiGetMonth(['token' => $tB['token'], 'month' => '115/10']);
ok($mB['viewer']['isSuper'] === false && $mB['viewer']['canAdmin'] === false, 'B 不是超管（超管＝會務人員）');
$mZ = $E->apiGetMonth(['token' => $tZ['token'], 'month' => '115/10']);
ok($mZ['viewer']['isSuper'] === true && $mZ['viewer']['perms']['grant'] === true && $mZ['viewer']['perms']['brand'] === true, 'Z 是超管、全權限');
ok($m10['notes'] === "1. 測試注意事項\n2. 第二行" || $E->apiGetMonth(['token' => $tA['token'], 'month' => '115/09'])['notes'] === "1. 測試注意事項\n2. 第二行", '注意事項搬來');

/* ================= 4. 填班／取消／交棒 ================= */
echo "=== 填班\n";
$r = $E->apiSubmitShift(['token' => $tA['token'], 'month' => '115/10', 'iso' => '2026-10-11', 'shift' => 'am', 'name' => '王小明']);
ok($r['ok'] && $r['data']['days'][0]['iso'] !== '', 'A 填 10/11 早班');
$r = $E->apiSubmitShift(['token' => $tB['token'], 'month' => '115/10', 'iso' => '2026-10-11', 'shift' => 'am', 'name' => '陳小美']);
ok(!$r['ok'] && strpos($r['msg'], '慢了一步') !== false, '搶同一格被擋', $r['msg']);
$r = $E->apiSubmitShift(['token' => $tA['token'], 'month' => '115/10', 'iso' => '2026-10-12', 'shift' => 'am', 'name' => '王小明']);
ok(!$r['ok'] && strpos($r['msg'], '不開放') !== false, '不開放日被擋');
$r = $E->apiSubmitShift(['token' => $tZ['token'], 'month' => '115/10', 'iso' => '2026-10-18', 'shift' => 'am', 'name' => '會務人員']);
ok(!$r['ok'] && strpos($r['msg'], '不參與排班') !== false, '會務人員不能填自己');
$r = $E->apiSubmitShift(['token' => $tZ['token'], 'month' => '115/10', 'iso' => '2026-10-18', 'shift' => 'am', 'name' => '陳小美']);
ok($r['ok'], '超管（會務）可代填藥師');
$r = $E->apiCancelShift(['token' => $tZ['token'], 'month' => '115/10', 'iso' => '2026-10-18', 'shift' => 'am']);
ok($r['ok'], '超管（會務）可取消代填的班');
$r = $E->apiCancelShift(['token' => $tB['token'], 'month' => '115/10', 'iso' => '2026-10-11', 'shift' => 'am']);
ok(!$r['ok'] && strpos($r['msg'], '只有本人') !== false, 'B 不能取消 A 的班');
$r = $E->apiCancelShift(['token' => $tZ['token'], 'month' => '115/10', 'iso' => '2026-10-11', 'shift' => 'am']);
ok($r['ok'], '超管可取消他人的班');
$r = $E->apiSetDone(['token' => $tA['token'], 'month' => '115/10', 'name' => '王小明', 'done' => true]);
ok($r['ok'] && $r['data']['order'][0]['done'] === true, 'A 交棒');
$r = $E->apiSetDone(['token' => $tA['token'], 'month' => '115/10', 'name' => '陳小美', 'done' => true]);
ok(!$r['ok'], 'A 不能替 B 交棒');
$r = $E->apiAdminSkip(['token' => $tZ['token'], 'month' => '115/10', 'name' => '陳小美']);
ok($r['ok'] && $r['data']['order'][1]['done'] === true, '超管標記 B 逾期');

/* ================= 5. 鎖定 ================= */
echo "=== 鎖定\n";
ok(!$E->apiSetLock(['token' => $tA['token'], 'month' => '115/10'])['ok'], 'A 不能鎖');
$r = $E->apiSetLock(['token' => $tZ['token'], 'month' => '115/10']);
ok($r['ok'] && $r['lock']['locked'] && $r['lock']['until'] === '2026-10-01', '鎖 115/10 到 10/1');
$r = $E->apiSubmitShift(['token' => $tA['token'], 'month' => '115/10', 'iso' => '2026-10-18', 'shift' => 'am', 'name' => '王小明']);
ok(!$r['ok'] && strpos($r['msg'], '鎖定') !== false, '鎖定期間 A 不能填');
$b = $E->apiGetBootstrap([]);
ok($b['defaultMonth'] === '115/09' && $b['lock']['locked'], '鎖定期間預設開當月');
ok($E->apiClearLock(['token' => $tZ['token']])['ok'] && !$E->apiGetBootstrap([])['lock']['locked'], '解鎖');
$E->today = '2026-10-01';   // 同一個資料庫、換「今天」
$E->apiSetLock(['token' => $tZ['token'], 'month' => '115/10']);
ok(!$E->apiSetLock(['token' => $tZ['token'], 'month' => '115/10'])['ok'], '到期月份鎖了沒作用');
$E->today = '2026-09-08';

/* ================= 6. 開放日、注意事項 ================= */
echo "=== 開放日與注意事項\n";
$r = $E->apiSetSlotOpen(['token' => $tZ['token'], 'month' => '115/10', 'iso' => '2026-10-10', 'shift' => 'am', 'open' => true]);
ok($r['ok'], '開放 10/10 早班');
$d10 = null; foreach ($r['data']['days'] as $d) if ($d['iso'] === '2026-10-10') $d10 = $d;
ok($d10['open'] && !$d10['amClosed'] && $d10['pmClosed'], '半開放：早班開、晚班仍 X', $d10);
$r = $E->apiSetSlotOpen(['token' => $tZ['token'], 'month' => '115/10', 'iso' => '2026-10-04', 'shift' => 'pm', 'open' => false]);
ok(!$r['ok'] && strpos($r['msg'], '林小華') !== false, '有人填班的格子不能關');
$r = $E->apiSaveNotes(['token' => $tZ['token'], 'month' => '115/10', 'notes' => "甲\r\n乙\n\n丙"]);
ok($r['ok'] && $r['data']['notes'] === "甲\n乙\n丙", '注意事項去空行', $r['data']['notes']);
ok(!$E->apiSaveNotes(['token' => $tA['token'], 'month' => '115/10', 'notes' => 'x'])['ok'], 'A 不能改注意事項');

/* ================= 7. 權限授權 ================= */
echo "=== 權限授權\n";
$r = $E->apiSetPerms(['token' => $tZ['token'], 'name' => '陳小美', 'perms' => ['notes', 'bogus']]);
ok($r['ok'] && strpos($r['msg'], '編輯注意事項') !== false, '超管授權 B notes');
ok($E->apiSaveNotes(['token' => $tB['token'], 'month' => '115/10', 'notes' => 'ok'])['ok'], 'B 授權後可改注意事項');
ok(!$E->apiSetLock(['token' => $tB['token'], 'month' => '115/10'])['ok'], 'B 仍不能鎖');
$au = $E->apiAdminUsers(['token' => $tZ['token']]);
ok($au['isSuper'] && count($au['keys']) === 9 && count($au['rows']) === 4, '名單與 9 個鍵');
$au2 = $E->apiAdminUsers(['token' => $tA['token']]);
ok(!$au2['ok'], 'A 不能看名單');
ok(!$E->apiSetPerms(['token' => $tB['token'], 'name' => '王小明', 'perms' => ['lock']])['ok'], 'B 不能授權');

/* ================= 8. 增減人員 ================= */
echo "=== 增減人員\n";
$r = $E->apiRosterAdd(['token' => $tZ['token'], 'name' => '黃小婷']);
ok($r['ok'] && $r['seq'] === 'D', '加入黃小婷 D', $r);
$o = $E->apiGetMonth(['token' => $tA['token'], 'month' => '115/10'])['order'];
ok(count($o) === 4 && $o[3]['name'] === '黃小婷' && $o[3]['done'] === false, '115/10 順位 4 人');
ok(count($E->apiGetMonth(['token' => $tA['token'], 'month' => '115/09'])['order']) === 4, '115/09（本月）也加了');
ok($E->apiLogin(['seq' => 'D', 'pw' => '0000'])['ok'], '新人可登入');
$r = $E->apiRosterRemove(['token' => $tZ['token'], 'name' => '林小華']);
ok(!$r['ok'] && strpos($r['msg'], '115年10月') !== false, '林小華有班 → 擋', $r['msg']);
$E->apiCancelShift(['token' => $tZ['token'], 'month' => '115/10', 'iso' => '2026-10-04', 'shift' => 'pm']);
$r = $E->apiRosterRemove(['token' => $tZ['token'], 'name' => '林小華']);
ok($r['ok'], '取消班後可移除');
$o = $E->apiGetMonth(['token' => $tA['token'], 'month' => '115/10'])['order'];
ok(array_map(function ($x) { return $x['seq'] . $x['name']; }, $o) === ['A王小明', 'B陳小美', 'D黃小婷'], '順位重排 A,B,D', $o);
ok(!$E->apiRosterRemove(['token' => $tZ['token'], 'name' => '會務人員'])['ok'], '會務帳號不能移除');
$r = $E->apiRosterAdd(['token' => $tZ['token'], 'name' => '林小華']);
ok($r['ok'] && $r['seq'] === 'C', '再加回 → 重用 C');

/* ================= 9. 建立月份 ================= */
echo "=== 建立月份\n";
$r = $E->apiCreateNextMonth(['token' => $tZ['token']]);
ok($r['ok'] && $r['month'] === '115/11' && $r['data']['name'] === '115/11', '建立 115/11', $r['month'] ?? $r);
$o = $r['data']['order'];
ok(array_map(function ($x) { return $x['seq'] . $x['name']; }, $o) === ['B陳小美', 'D黃小婷', 'C林小華', 'A王小明'] && !array_filter($o, function ($x) { return $x['done']; }), '順位左移、完成歸零', $o);
$open11 = array_filter($r['data']['days'], function ($d) { return $d['open']; });
ok(count($open11) === 5, '115/11 週日 5 天（11/1、8、15、22、29）', count($open11));
$r = $E->apiCreateMonthsThrough(['token' => $tZ['token'], 'roc' => 116, 'month' => 2]);
ok($r['ok'] && $r['made'] === ['115/12', '116/01', '116/02'], '一路建到 116/2', $r);
$m2 = $E->apiGetMonth(['token' => $tA['token'], 'month' => '116/02']);
ok($m2['year'] === 2027 && $m2['month'] === 2 && count(array_filter($m2['days'], function ($d) { return $d['inMonth']; })) === 28, '116/02 ＝ 2027 年 2 月 28 天');
ok($E->apiGetBootstrap([])['months'][0]['name'] === '116/02', '最新月份排最前');

/* ================= 10. 頁首頁尾文字、密碼 ================= */
echo "=== 頁首頁尾與密碼\n";
$u = $E->apiGetUiSettings(['token' => $tZ['token']]);
ok($u['ok'] && count($u['keys']) === 8 && $u['values']['titleMobile'] === '藥師 搬移測試' && $u['overridden'] === ['titleMobile'], '讀到覆蓋值');
$u = $E->apiSaveUiSettings(['token' => $tZ['token'], 'values' => ['__reset' => true]]);
ok($u['ok'] && $u['ui']['titleMobile'] === CFG()['UI']['titleMobile'], '還原');
$u = $E->apiSaveUiSettings(['token' => $tZ['token'], 'values' => ['titleDesktop' => '<b>x</b> 新標題']]);
ok($u['ui']['titleDesktop'] === 'bx/b 新標題', '去尖括號', $u['ui']['titleDesktop']);
$r = $E->apiChangePassword(['token' => $tA['token'], 'oldPw' => '0000', 'newPw' => '1234']);
ok($r['ok'] && $E->apiLogin(['seq' => 'A', 'pw' => '1234'])['ok'] && !$E->apiLogin(['seq' => 'A', 'pw' => '0000'])['ok'], 'A 改密碼');
for ($i = 0; $i < 10; $i++) $E->apiLogin(['seq' => 'A', 'pw' => '9999']);
$r = $E->apiLogin(['seq' => 'A', 'pw' => '1234']);
ok(!$r['ok'] && strpos($r['msg'], '鎖定') !== false, '連錯 10 次鎖定，正確密碼也不放行', $r['msg']);
ok($E->apiAdminResetPw(['token' => $tZ['token'], 'name' => '王小明'])['ok'] && $E->apiLogin(['seq' => 'A', 'pw' => '0000'])['ok'], '重設密碼並解鎖');
$st = $E->apiAdminPwStatus(['token' => $tZ['token']]);
ok($st['ok'] && count($st['rows']) === 5, '密碼狀態 5 人');

/* ================= 11. 匯出 ================= */
echo "=== 匯出\n";
$x = $E->apiExportXlsx(['token' => $tA['token'], 'month' => '115/10']);
ok($x['ok'] && substr(base64_decode($x['base64']), 0, 2) === 'PK' && strpos($x['filename'], '.xlsx') !== false, 'xlsx 是 zip', $x['filename'] ?? $x);
$bin = base64_decode($x['base64']);
ok(substr_count($bin, '>林小華<') === 1 && strpos($bin, '排序') !== false && strpos($bin, '10/4') !== false, 'xlsx 內容（林小華只在順位欄出現一次、有順位標題與日期）', substr_count($bin, '>林小華<'));
$xa = $E->apiExportXlsx(['token' => $tA['token'], 'month' => 'all']);
ok($xa['ok'] && substr_count(base64_decode($xa['base64']), 'worksheets/sheet') >= 6, '全部月份多張工作表');
$ex = $E->apiExportAll(['token' => $tZ['token']]);
ok($ex['ok'] && count($ex['data']['months']) === 6 && isset($ex['data']['props']['AUTH_SECRET']) && isset($ex['data']['props']['pw_陳小美']), '備份 JSON 含 secret 與密碼雜湊');
ok(!$E->apiExportAll(['token' => $tA['token']])['ok'], 'A 不能備份');
// 匯出再匯入到新環境 → 一致
$F = env('2026-09-08');
$r = $F->apiImportAll(['token' => $F->apiLogin(['seq' => 'Z', 'pw' => '0000'])['token'], 'data' => $ex['data']]);
ok($r['ok'] && $r['counts']['months'] === 6, '本系統匯出可再匯入', $r);
ok($F->apiLogin(['seq' => 'B', 'pw' => '5678'])['ok'], '匯入後 B 密碼仍可登入');
$mF = $F->apiGetMonth(['token' => $F->apiLogin(['seq' => 'A', 'pw' => '0000'])['token'], 'month' => '115/10']);
$mE = $E->apiGetMonth(['token' => $E->apiLogin(['seq' => 'A', 'pw' => '0000'])['token'], 'month' => '115/10']);
ok($mF['days'] === $mE['days'] && $mF['order'] === $mE['order'] && $mF['notes'] === $mE['notes'], '匯出入前後月份資料一致');

/* ================= 12. 診斷 ================= */
$E->use_();
ok(strpos(diagText_(), 'APP_VERSION = ' . APP_VERSION) === 0 && strpos(smokeText_(), 'SMOKE OK') === 0, 'diag／smoke');

foreach (Env::$all as $e) { foreach (glob($e->dir . '/*') as $f) @unlink($f); @rmdir($e->dir); }
echo "通過 {$PASS}／失敗 {$FAILS}\n";
exit($FAILS ? 1 : 0);
