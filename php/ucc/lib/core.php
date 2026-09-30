<?php
/* 核心：工具、SQLite 資料層、月份／班表資料組裝、診斷。
 * 慣例：api* 函式收一個 $p 陣列（對應 Apps Script 版的 payload），回傳陣列直接當 JSON 給前端（形狀與 Apps Script 版一致）。
 * 「使用者看得到的失敗」用 return ['ok'=>false,'msg'=>…]；程式錯誤才 throw。每次寫入都 log_()。 */
if (!defined('UCC')) { http_response_code(404); exit; }

/* ================= 工具 ================= */
function str_($v) { return $v === null ? '' : trim((string)$v); }
function jenc_($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function jdec_($s) { $v = json_decode(str_($s) === '' ? 'null' : (string)$s, true); return is_array($v) ? $v : []; }
function tz_() { static $tz = null; if (!$tz) $tz = new DateTimeZone(CFG()['TZ']); return $tz; }
/** 「今天」（測試可用 $GLOBALS['UCC_TODAY']='YYYY-MM-DD' 覆寫） */
function now_() {
  if (!empty($GLOBALS['UCC_TODAY'])) return new DateTimeImmutable($GLOBALS['UCC_TODAY'] . ' 09:00:00', tz_());
  return new DateTimeImmutable('now', tz_());
}
function nowMs_() { return now_()->getTimestamp() * 1000; }
function todayIso_() { return now_()->format('Y-m-d'); }
function nowStr_() { return now_()->format('Y-m-d H:i:s'); }
function isoAdd_($iso, $days) { return (new DateTimeImmutable($iso . ' 00:00:00', tz_()))->modify(($days >= 0 ? '+' : '') . (int)$days . ' day')->format('Y-m-d'); }
function dow_($iso) { return (int)(new DateTimeImmutable($iso . ' 00:00:00', tz_()))->format('w'); }
function isoOf_($y, $m, $d) { return sprintf('%04d-%02d-%02d', $y, $m, $d); }

/* ================= 資料層 ================= */
function dataDir_() { return !empty($GLOBALS['UCC_DATA_DIR']) ? $GLOBALS['UCC_DATA_DIR'] : dirname(__DIR__) . '/data'; }
function db_() {
  static $db = null, $path = null;
  $want = dataDir_() . '/' . CFG()['DB_FILE'];
  if ($db && $path === $want) return $db;
  if (!is_dir(dataDir_())) @mkdir(dataDir_(), 0755, true);
  $db = new SQLite3($want);
  $db->enableExceptions(true);
  $db->busyTimeout(15000);
  $db->exec('PRAGMA journal_mode=WAL');
  $db->exec('PRAGMA synchronous=NORMAL');
  $path = $want;
  return $db;
}
function ensureSchema_() {
  $db = db_();
  $db->exec('CREATE TABLE IF NOT EXISTS _props (k TEXT PRIMARY KEY, v TEXT)');
  $db->exec('CREATE TABLE IF NOT EXISTS months (name TEXT PRIMARY KEY, roc INTEGER, m INTEGER, created TEXT)');
  $db->exec('CREATE TABLE IF NOT EXISTS slots (month TEXT, iso TEXT, shift TEXT, closed INTEGER DEFAULT 0, name TEXT DEFAULT \'\', PRIMARY KEY (month, iso, shift))');
  $db->exec('CREATE TABLE IF NOT EXISTS roster (month TEXT, pos INTEGER, seq TEXT, name TEXT, done INTEGER DEFAULT 0, PRIMARY KEY (month, pos))');
  $db->exec('CREATE TABLE IF NOT EXISTS notes (month TEXT PRIMARY KEY, text TEXT DEFAULT \'\')');
  $db->exec('CREATE TABLE IF NOT EXISTS open_days (iso TEXT PRIMARY KEY, open INTEGER DEFAULT 0, note TEXT DEFAULT \'\')');
  $db->exec('CREATE TABLE IF NOT EXISTS users (name TEXT PRIMARY KEY, pw_hash TEXT DEFAULT \'\', perms TEXT DEFAULT \'\', updated TEXT)');
  $db->exec('CREATE TABLE IF NOT EXISTS log (id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT, action TEXT, month TEXT, iso TEXT, shift TEXT, name TEXT, note TEXT)');
  if (prop_('SCHEMA_VERSION') === null) setProp_('SCHEMA_VERSION', (string)SCHEMA_VERSION);
}
function q1_($sql, $params = []) { $st = db_()->prepare($sql); foreach ($params as $i => $v) $st->bindValue($i + 1, $v); return $st->execute()->fetchArray(SQLITE3_ASSOC) ?: null; }
function qa_($sql, $params = []) { $st = db_()->prepare($sql); foreach ($params as $i => $v) $st->bindValue($i + 1, $v); $r = $st->execute(); $out = []; while ($row = $r->fetchArray(SQLITE3_ASSOC)) $out[] = $row; return $out; }
function x_($sql, $params = []) { $st = db_()->prepare($sql); foreach ($params as $i => $v) $st->bindValue($i + 1, $v); $st->execute(); }
function prop_($k) { $r = q1_('SELECT v FROM _props WHERE k = ?', [$k]); return $r ? $r['v'] : null; }
function setProp_($k, $v) { x_('INSERT INTO _props (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, (string)$v]); }
function delProp_($k) { x_('DELETE FROM _props WHERE k = ?', [$k]); }
/** 寫入鎖：立即交易，同時只有一個寫入者；可巢狀 */
function withLock_($fn) {
  static $depth = 0;
  if ($depth > 0) return $fn();
  $db = db_();
  $db->exec('BEGIN IMMEDIATE');
  $depth++;
  try { $out = $fn(); $depth--; $db->exec('COMMIT'); return $out; }
  catch (Throwable $e) { $depth--; try { $db->exec('ROLLBACK'); } catch (Throwable $e2) { } throw $e; }
}
function log_($action, $month = '', $iso = '', $shift = '', $name = '', $note = '') {
  $sh = $shift === 'am' ? CFG()['UI']['shifts']['am']['label'] : ($shift === 'pm' ? CFG()['UI']['shifts']['pm']['label'] : '');
  try { x_('INSERT INTO log (ts, action, month, iso, shift, name, note) VALUES (?,?,?,?,?,?,?)', [nowStr_(), $action, (string)$month, (string)$iso, $sh, (string)$name, (string)$note]); } catch (Throwable $e) { }
}

/* ================= 月份 ================= */
function isMonthName_($n) { return preg_match('/^\d{3}\/\d{1,2}$/', $n) || preg_match('/^\d{5}$/', $n); }
/** 「115/10」→ ['roc'=>115,'y'=>2026,'m'=>10,'key'=>11510,'label'=>'115年10月'] */
function decodeMonth_($name) {
  if (strpos($name, '/') !== false) { $p = explode('/', $name); $roc = (int)$p[0]; $m = (int)$p[1]; }
  else { $roc = (int)substr($name, 0, 3); $m = (int)substr($name, 3); }
  return ['roc' => $roc, 'y' => $roc + 1911, 'm' => $m, 'key' => $roc * 100 + $m, 'label' => $roc . '年' . $m . '月'];
}
function encodeMonth_($roc, $m) { return $roc . '/' . str_pad((string)$m, 2, '0', STR_PAD_LEFT); }
/** 全部月份名稱，新到舊 */
function listMonths_() { $rows = qa_('SELECT name FROM months ORDER BY (roc*100+m) DESC'); return array_map(function ($r) { return $r['name']; }, $rows); }
function monthExists_($name) { return (bool)q1_('SELECT 1 FROM months WHERE name = ?', [$name]); }
function todayKey_() { $t = todayIso_(); return ((int)substr($t, 0, 4) - 1911) * 100 + (int)substr($t, 5, 2); }
function currentMonthSheet_() { $k = todayKey_(); foreach (listMonths_() as $n) if (decodeMonth_($n)['key'] === $k) return $n; return ''; }
/** 今天所在月起、所有已建立的月份 */
function affectedMonths_() { $k = todayKey_(); return array_values(array_filter(listMonths_(), function ($n) use ($k) { return decodeMonth_($n)['key'] >= $k; })); }

/** 順位（依 pos） */
function order_($month) {
  return array_map(function ($r) { return ['seq' => $r['seq'], 'name' => $r['name'], 'done' => (int)$r['done'] === 1, 'pos' => (int)$r['pos']]; },
    qa_('SELECT pos, seq, name, done FROM roster WHERE month = ? ORDER BY pos', [$month]));
}
function notes_($month) { $r = q1_('SELECT text FROM notes WHERE month = ?', [$month]); return $r ? (string)$r['text'] : ''; }
function slotsOf_($month) { $out = []; foreach (qa_('SELECT iso, shift, closed, name FROM slots WHERE month = ?', [$month]) as $r) $out[$r['iso'] . '#' . $r['shift']] = $r; return $out; }

/** 一個月的月曆：整週格線（含前後補位的非當月日），每日 am/pm 狀態；與 Apps Script 版 parseSheet_ 的 days 形狀相同 */
function days_($month) {
  $info = decodeMonth_($month); $y = $info['y']; $m = $info['m'];
  $slots = slotsOf_($month);
  $first = new DateTimeImmutable(isoOf_($y, $m, 1) . ' 00:00:00', tz_());
  $dow = (int)$first->format('w'); $nd = (int)$first->format('t');
  $weeks = (int)ceil(($dow + $nd) / 7);
  $cur = $first->modify('-' . $dow . ' day');
  $days = [];
  for ($i = 0; $i < $weeks * 7; $i++) {
    $iso = $cur->format('Y-m-d');
    $inMonth = (int)$cur->format('n') === $m && (int)$cur->format('Y') === $y;
    $am = $slots[$iso . '#am'] ?? null; $pm = $slots[$iso . '#pm'] ?? null;
    $amClosed = !$inMonth || !$am || (int)$am['closed'] === 1;
    $pmClosed = !$inMonth || !$pm || (int)$pm['closed'] === 1;
    $days[] = ['iso' => $iso, 'wd' => (int)$cur->format('w'), 'dayNum' => (int)$cur->format('j'), 'inMonth' => $inMonth,
      'open' => !($amClosed && $pmClosed),
      'am' => $amClosed ? null : (string)$am['name'], 'pm' => $pmClosed ? null : (string)$pm['name'],
      'amClosed' => $amClosed, 'pmClosed' => $pmClosed];
    $cur = $cur->modify('+1 day');
  }
  return $days;
}
/** 連續 LONG_HOLIDAY_MIN 個以上可排班日＝長連假 */
function longHolidays_($days) {
  $open = array_values(array_map(function ($d) { return $d['iso']; }, array_filter($days, function ($d) { return $d['open']; })));
  $out = []; $run = [];
  $flush = function () use (&$run, &$out) { if (count($run) >= CFG()['LONG_HOLIDAY_MIN']) foreach ($run as $x) $out[$x] = count($run); $run = []; };
  foreach ($open as $iso) {
    if (!$run) { $run[] = $iso; continue; }
    if (isoAdd_(end($run), 1) === $iso) $run[] = $iso; else { $flush(); $run[] = $iso; }
  }
  $flush();
  return $out;
}
/** 給前端的月份資料（與 Apps Script 版 getMonthData_ 相同形狀） */
function monthData_($name) {
  if (!monthExists_($name)) throw new Exception('找不到月份：' . $name);
  $info = decodeMonth_($name);
  $days = days_($name); $lh = longHolidays_($days);
  foreach ($days as &$d) $d['longHoliday'] = isset($lh[$d['iso']]);
  unset($d);
  $all = array_reverse(listMonths_());   // 舊→新
  $idx = array_search($name, $all, true);
  $prevs = $idx > 0 ? array_slice($all, max(0, $idx - 3), $idx - max(0, $idx - 3)) : [];
  $prevCounts = [];
  if ($prevs) {
    $ph = implode(',', array_fill(0, count($prevs), '?'));
    foreach (qa_("SELECT name, COUNT(*) AS n FROM slots WHERE month IN ($ph) AND closed = 0 AND name <> '' GROUP BY name", $prevs) as $r) $prevCounts[$r['name']] = (int)$r['n'];
  }
  return ['name' => $name, 'label' => $info['label'], 'year' => $info['y'], 'month' => $info['m'], 'days' => $days,
    'order' => array_map(function ($o) { return ['seq' => $o['seq'], 'name' => $o['name'], 'done' => $o['done']]; }, order_($name)),
    'notes' => notes_($name), 'prevMonths' => array_map(function ($mn) { return decodeMonth_($mn)['m'] . '月'; }, $prevs),
    'prevCounts' => (object)$prevCounts, 'today' => todayIso_()];
}

/* ================= 可排班日 ================= */
function ensureOpenDays_() {
  $n = (int)q1_('SELECT COUNT(*) AS n FROM open_days')['n'];
  if ($n > 0) return;
  foreach (HOLIDAYS() as $h) x_('INSERT OR IGNORE INTO open_days (iso, open, note) VALUES (?, 0, ?)', [$h[0], $h[1]]);
}
function openDaySet_() { $out = []; foreach (qa_('SELECT iso FROM open_days WHERE open = 1') as $r) $out[$r['iso']] = true; return $out; }

/* ================= 建立月份 ================= */
/** 寫入一個月的格線：週日自動開、其餘看 open_days；順位與注意事項由呼叫端給 */
function writeMonth_($name, $order, $notesText = '') {
  $info = decodeMonth_($name); $y = $info['y']; $m = $info['m'];
  $openSet = openDaySet_();
  x_('INSERT INTO months (name, roc, m, created) VALUES (?,?,?,?)', [$name, $info['roc'], $m, nowStr_()]);
  $nd = (int)(new DateTimeImmutable(isoOf_($y, $m, 1), tz_()))->format('t');
  for ($d = 1; $d <= $nd; $d++) {
    $iso = isoOf_($y, $m, $d);
    $open = dow_($iso) === 0 || isset($openSet[$iso]);
    foreach (['am', 'pm'] as $sh) x_('INSERT INTO slots (month, iso, shift, closed, name) VALUES (?,?,?,?,\'\')', [$name, $iso, $sh, $open ? 0 : 1]);
  }
  foreach (array_values($order) as $i => $o) x_('INSERT INTO roster (month, pos, seq, name, done) VALUES (?,?,?,?,?)', [$name, $i + 1, $o['seq'], $o['name'], !empty($o['done']) ? 1 : 0]);
  x_('INSERT INTO notes (month, text) VALUES (?, ?)', [$name, (string)$notesText]);
}
/** 從最新月份建下一個月：順位整體左移一位、完成註記歸零、注意事項清空 */
function createNextMonth_($by) {
  $all = listMonths_();
  if (!$all) return ['ok' => false, 'msg' => '資料庫裡沒有任何月份可以當版型'];
  $src = $all[0]; $si = decodeMonth_($src);
  $m = $si['m'] + 1; $roc = $si['roc'];
  if ($m > 12) { $m = 1; $roc += 1; }
  $target = encodeMonth_($roc, $m);
  if (monthExists_($target)) return ['ok' => false, 'msg' => '月份 ' . $target . ' 已經存在'];
  $order = order_($src);
  $rotated = array_merge(array_slice($order, 1), array_slice($order, 0, 1));
  foreach ($rotated as &$o) $o['done'] = false;
  unset($o);
  writeMonth_($target, $rotated, '');
  log_('建立次月', $target, '', '', $by, '來源 ' . $src);
  return ['ok' => true, 'month' => $target];
}

/* ================= 第一次建置 ================= */
function ensureSetup_() {
  ensureSchema_();
  ensureOpenDays_();
  if (!listMonths_() && CFG()['SETUP_ROSTER']) {
    $t = todayIso_(); $roc = (int)substr($t, 0, 4) - 1911; $m = (int)substr($t, 5, 2) + 1;
    if ($m > 12) { $m = 1; $roc++; }
    $order = [];
    foreach (array_values(CFG()['SETUP_ROSTER']) as $i => $n) $order[] = ['seq' => chr(65 + $i), 'name' => (string)$n, 'done' => false];
    withLock_(function () use ($roc, $m, $order) { writeMonth_(encodeMonth_($roc, $m), $order, ''); });
    log_('建立初始月份', encodeMonth_($roc, $m), '', '', '系統', 'SETUP_ROSTER ' . count($order) . ' 人');
  }
}

/* ================= 診斷 ================= */
function diagText_() {
  $o = ['APP_VERSION = ' . APP_VERSION, 'schema_version = ' . (prop_('SCHEMA_VERSION') ?? '?'), 'php = ' . PHP_VERSION, 'sqlite = ' . SQLite3::version()['versionString'],
    'time = ' . nowStr_(), 'months = ' . implode('、', listMonths_())];
  foreach (['months', 'slots', 'roster', 'notes', 'open_days', 'users', 'log'] as $t) $o[] = $t . ' = ' . q1_("SELECT COUNT(*) AS n FROM $t")['n'] . ' 列';
  $lk = lockState_(); $o[] = 'lock = ' . ($lk['locked'] ? $lk['month'] : '無');
  return implode("\n", $o) . "\n";
}
function smokeText_() {
  try {
    $b = apiGetBootstrap([]);
    if (!isset($b['months']) || !isset($b['roster'])) return 'SMOKE FAIL bootstrap';
    return 'SMOKE OK months=' . count($b['months']) . ' roster=' . count($b['roster']) . ' ' . APP_VERSION;
  } catch (Throwable $e) { return 'SMOKE FAIL ' . $e->getMessage(); }
}
