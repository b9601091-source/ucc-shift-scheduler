<?php
/* 帳號、登入、權限（對應 Apps Script 版 Code.gs 的「帳號與登入」「權限」兩節）
 * 密碼雜湊公式與 Apps Script 版相同：sha256(secret|姓名|密碼) 的十六進位；搬移時連 secret 一起匯入，原密碼就能直接登入。
 * token：姓名|到期毫秒|hmac-sha256 十六進位（與 Apps Script 版相同格式）。 */
if (!defined('UCC')) { http_response_code(404); exit; }

function secret_() {
  $s = prop_('AUTH_SECRET');
  if (!$s) { $s = bin2hex(random_bytes(32)); setProp_('AUTH_SECRET', $s); }
  return $s;
}
function hashPw_($name, $pw) { return hash('sha256', secret_() . '|' . $name . '|' . $pw); }
function userRow_($name) { return q1_('SELECT name, pw_hash, perms FROM users WHERE name = ?', [$name]); }
function isDefaultPw_($name) { $u = userRow_($name); return !$u || str_($u['pw_hash']) === ''; }
function checkPw_($name, $pw) {
  $u = userRow_($name);
  if (!$u || str_($u['pw_hash']) === '') return (string)$pw === CFG()['DEFAULT_PASSWORD'];
  return hash_equals((string)$u['pw_hash'], hashPw_($name, (string)$pw));
}
function setUser_($name, $fields) {
  $u = userRow_($name) ?: ['name' => $name, 'pw_hash' => '', 'perms' => ''];
  $u = array_merge($u, $fields);
  x_('INSERT INTO users (name, pw_hash, perms, updated) VALUES (?,?,?,?) ON CONFLICT(name) DO UPDATE SET pw_hash = excluded.pw_hash, perms = excluded.perms, updated = excluded.updated',
     [$name, (string)$u['pw_hash'], (string)$u['perms'], nowStr_()]);
}
function makeToken_($name) {
  $body = $name . '|' . (nowMs_() + CFG()['TOKEN_DAYS'] * 86400000);
  return $body . '|' . hash_hmac('sha256', $body, secret_());
}
/** token 有效就回姓名，否則 null */
function tokenName_($token) {
  $t = (string)$token;
  $i = strrpos($t, '|');
  if ($t === '' || $i === false) return null;
  $body = substr($t, 0, $i); $sig = substr($t, $i + 1);
  if (!hash_equals(hash_hmac('sha256', $body, secret_()), $sig)) return null;
  $parts = explode('|', $body);
  if (count($parts) !== 2 || (float)$parts[1] < nowMs_()) return null;
  return $parts[0];
}
function actorOf_($p) { return tokenName_($p['token'] ?? ''); }
function needLogin_() { return ['ok' => false, 'needLogin' => true, 'msg' => '登入已過期，請重新登入']; }

/* ---- 名單 ---- */
function isReadOnly_($name) { foreach (CFG()['STAFF_ACCOUNTS'] as $a) if ($a['name'] === $name) return true; return false; }
/** 最新月份的順位＋會務帳號 */
function rosterList_() {
  $out = []; $all = listMonths_();
  if ($all) foreach (order_($all[0]) as $o) if ($o['name'] !== '' && $o['seq'] !== '') $out[] = ['seq' => $o['seq'], 'name' => $o['name']];
  usort($out, function ($a, $b) { return strcmp($a['seq'], $b['seq']); });
  foreach (CFG()['STAFF_ACCOUNTS'] as $a) $out[] = ['seq' => $a['seq'], 'name' => $a['name']];
  return $out;
}
function rosterNames_() { return array_map(function ($r) { return $r['name']; }, rosterList_()); }
function seqToName_($seq) { foreach (rosterList_() as $r) if ($r['seq'] === (string)$seq) return $r['name']; return ''; }
/* 主機與本機都不一定有 mbstring，UTF-8 字元操作一律用 preg /u */
function chars_($s) { return preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY) ?: []; }
function ulen_($s) { return count(chars_($s)); }
function ucut_($s, $n) { return implode('', array_slice(chars_($s), 0, $n)); }
function utf8Chr_($cp) { return iconv('UCS-4BE', 'UTF-8', pack('N', $cp)); }
/** 姓名遮罩：只留頭尾，中間換 ○ */
function maskName_($n) {
  $c = chars_($n); $len = count($c);
  if ($len <= 1) return (string)$n;
  if ($len === 2) return $c[0] . '○';
  return $c[0] . str_repeat('○', $len - 2) . $c[$len - 1];
}
/** A~Z → Ａ~Ｚ */
function fwSeq_($seq) { return preg_replace_callback('/[A-Za-z]/', function ($m) { return utf8Chr_(ord($m[0]) + 0xFEE0); }, (string)$seq); }

/* ---- 登入失敗鎖定 ---- */
function failState_($name) { $f = jdec_(prop_('fail_' . $name)); return $f + ['n' => 0, 'until' => 0, 'last' => 0]; }
function lockedOutMin_($name) { $f = failState_($name); $now = nowMs_(); return ($f['until'] && $f['until'] > $now) ? (int)ceil(($f['until'] - $now) / 60000) : 0; }
function noteFail_($name) {
  $f = failState_($name); $now = nowMs_();
  if ($f['last'] && ($now - $f['last']) > CFG()['LOGIN_FAIL_WINDOW_MIN'] * 60000) $f['n'] = 0;
  $f['n'] = (int)$f['n'] + 1; $f['last'] = $now;
  if ($f['n'] >= CFG()['LOGIN_MAX_FAIL']) { $f['until'] = $now + CFG()['LOGIN_LOCK_MIN'] * 60000; $f['n'] = 0; }
  setProp_('fail_' . $name, jenc_($f));
  return $f;
}
function clearFail_($name) { delProp_('fail_' . $name); }

/* ---- 權限 ---- */
function PERMS() {
  return [
    ['k' => 'lock', 'label' => '班表鎖定'], ['k' => 'notes', 'label' => '編輯注意事項'], ['k' => 'skip', 'label' => '標記逾期放棄'],
    ['k' => 'create', 'label' => '建立月份分頁'], ['k' => 'openday', 'label' => '編輯開放日'], ['k' => 'resetpw', 'label' => '使用者密碼重設'],
    ['k' => 'roster', 'label' => '增減人員'], ['k' => 'manage', 'label' => '代管班表（取消他人的班、代交棒、鎖定期間仍可改）'],
    ['k' => 'brand', 'label' => '頁首頁尾文字'],
  ];
}
function isAdminUser_($name) { return $name !== null && $name !== '' && in_array($name, CFG()['ADMIN_NAMES'], true); }
function grantedOf_($name) { $u = $name ? userRow_($name) : null; $out = []; foreach (jdec_($u ? $u['perms'] : '') as $k) $out[$k] = true; return $out; }
function permsOf_($name) { $sup = isAdminUser_($name); $g = grantedOf_($name); $out = ['grant' => $sup]; foreach (PERMS() as $p) $out[$p['k']] = $sup || !empty($g[$p['k']]); return $out; }
function can_($name, $perm) { if (!$name) return false; if (isAdminUser_($name)) return true; if ($perm === 'grant') return false; return !empty(grantedOf_($name)[$perm]); }
function anyPerm_($name) { foreach (permsOf_($name) as $v) if ($v) return true; return false; }
function permLabel_($k) { if ($k === 'grant') return '權限授權'; foreach (PERMS() as $p) if ($p['k'] === $k) return $p['label']; return $k; }
/** 後臺守門：回 null＝通過，否則回給前端的錯誤陣列 */
function requireAdmin_($p, $perm) {
  $who = actorOf_($p);
  if (!$who) return needLogin_();
  if (!can_($who, $perm)) return ['ok' => false, 'msg' => '你沒有「' . permLabel_($perm) . '」的權限'];
  return null;
}
function requireSuper_($p) {
  $who = actorOf_($p);
  if (!$who) return needLogin_();
  if (!isAdminUser_($who)) return ['ok' => false, 'msg' => '只有超級管理者（config.php 的 ADMIN_NAMES）能授權'];
  return null;
}

/* ---- 鎖定 ---- */
function lockState_() {
  $obj = jdec_(prop_('LOCK'));
  if (!$obj || empty($obj['month'])) return ['locked' => false];
  $info = decodeMonth_($obj['month']);
  $until = isoOf_($info['y'], $info['m'], 1);
  if (strcmp(todayIso_(), $until) >= 0) { delProp_('LOCK'); return ['locked' => false, 'expired' => $obj['month']]; }
  return ['locked' => true, 'month' => $obj['month'], 'label' => $info['label'], 'until' => $until, 'by' => $obj['by'] ?? ''];
}
function lockBlocks_($actor) {
  $st = lockState_();
  if (!$st['locked'] || can_($actor, 'manage')) return null;
  return ['ok' => false, 'msg' => $st['label'] . ' 班表已由' . CFG()['UI']['contactShort'] . '鎖定，' . $st['until'] . ' 起自動解除'];
}

/* ---- 頁首頁尾文字覆蓋 ---- */
function UI_KEYS() {
  return [['k' => 'titleDesktop', 'label' => '電腦版頁首標題'], ['k' => 'titleMobile', 'label' => '手機版頁首標題（字要少）'],
    ['k' => 'venueLine', 'label' => '頁首第二行（地點與班別時間，電腦版）'], ['k' => 'systemName', 'label' => '系統名稱（頁尾、瀏覽器標題）'],
    ['k' => 'loginScope', 'label' => '登入框上方一行'], ['k' => 'forgotLine', 'label' => '登入頁忘記密碼說明'],
    ['k' => 'confidentialNote', 'label' => '頁尾紅字（保密提醒）'], ['k' => 'contactLine', 'label' => '頁尾聯絡方式（可留空）']];
}
function uiOverride_() { return jdec_(prop_('UI_OVERRIDE')); }
function effectiveUi_() {
  $ui = CFG()['UI']; $o = uiOverride_();
  foreach (UI_KEYS() as $x) if (isset($o[$x['k']]) && is_string($o[$x['k']])) $ui[$x['k']] = $o[$x['k']];
  return $ui;
}

/* ================= 登入 API ================= */
function apiLogin($p = []) {
  $seq = str_($p['seq'] ?? '');
  if ($seq === '') return ['ok' => false, 'msg' => '請先選擇使用者'];
  $name = seqToName_($seq);
  if ($name === '') return ['ok' => false, 'msg' => '名單裡沒有這個代號，請洽' . CFG()['UI']['contactShort']];
  $wait = lockedOutMin_($name);
  if ($wait > 0) { log_('登入遭鎖定', '', '', '', $name, '剩 ' . $wait . ' 分鐘'); return ['ok' => false, 'msg' => '密碼錯誤次數過多，已鎖定，請 ' . $wait . ' 分鐘後再試，或洽' . CFG()['UI']['contactShort'] . '重設密碼']; }
  if (!checkPw_($name, (string)($p['pw'] ?? ''))) {
    $st = noteFail_($name);
    if ($st['until'] > nowMs_()) { log_('登入失敗·觸發鎖定', '', '', '', $name, '連錯 ' . CFG()['LOGIN_MAX_FAIL'] . ' 次'); return ['ok' => false, 'msg' => '密碼錯誤 ' . CFG()['LOGIN_MAX_FAIL'] . ' 次，已鎖定 ' . CFG()['LOGIN_LOCK_MIN'] . ' 分鐘。忘記密碼請洽' . CFG()['UI']['contactShort'] . '重設']; }
    log_('登入失敗', '', '', '', $name, '第 ' . $st['n'] . ' 次');
    return ['ok' => false, 'msg' => '密碼不正確（再錯 ' . (CFG()['LOGIN_MAX_FAIL'] - $st['n']) . ' 次會鎖定 ' . CFG()['LOGIN_LOCK_MIN'] . ' 分鐘），忘記請洽' . CFG()['UI']['contactShort'] . '重設'];
  }
  clearFail_($name);
  log_('登入', '', '', '', $name, '');
  return ['ok' => true, 'token' => makeToken_($name), 'name' => $name, 'isDefault' => isDefaultPw_($name), 'readOnly' => isReadOnly_($name)];
}
function apiChangePassword($p = []) {
  $name = actorOf_($p);
  if (!$name) return needLogin_();
  if (!checkPw_($name, (string)($p['oldPw'] ?? ''))) return ['ok' => false, 'msg' => '目前密碼不正確'];
  $np = (string)($p['newPw'] ?? '');
  if (!preg_match('/^[0-9]{4,6}$/', $np)) return ['ok' => false, 'msg' => '新密碼請設 4~6 位數字'];
  if ($np === CFG()['DEFAULT_PASSWORD']) return ['ok' => false, 'msg' => '新密碼不能跟預設的一樣'];
  withLock_(function () use ($name, $np) { setUser_($name, ['pw_hash' => hashPw_($name, $np)]); });
  log_('修改密碼', '', '', '', $name, '');
  return ['ok' => true, 'msg' => '密碼已更新'];
}
function apiAdminResetPw($p = []) {
  if ($d = requireAdmin_($p, 'resetpw')) return $d;
  $name = str_($p['name'] ?? '');
  if ($name === '') return ['ok' => false, 'msg' => '請選擇要重設的藥師'];
  withLock_(function () use ($name) { if (userRow_($name)) setUser_($name, ['pw_hash' => '']); clearFail_($name); });
  log_('窗口重設密碼', '', '', '', $name, '打回預設 ' . CFG()['DEFAULT_PASSWORD'] . '，並解除登入鎖定（操作者 ' . actorOf_($p) . '）');
  return ['ok' => true, 'msg' => $name . ' 的密碼已重設為 ' . CFG()['DEFAULT_PASSWORD'] . '，登入鎖定也一併解除'];
}
function apiAdminPwStatus($p = []) {
  if ($d = requireAdmin_($p, 'resetpw')) return $d;
  return ['ok' => true, 'rows' => array_map(function ($n) { return ['name' => $n, 'isDefault' => isDefaultPw_($n), 'lockedMin' => lockedOutMin_($n)]; }, rosterNames_())];
}
function apiAdminUsers($p = []) {
  $who = actorOf_($p);
  if (!$who) return needLogin_();
  $sup = isAdminUser_($who);
  if (!$sup && !can_($who, 'roster')) return ['ok' => false, 'msg' => '你沒有「增減人員」的權限'];
  $rows = array_map(function ($r) use ($sup) {
    $o = ['seq' => $r['seq'], 'name' => $r['name'], 'staff' => isReadOnly_($r['name']), 'superAdmin' => isAdminUser_($r['name'])];
    if ($sup) $o['perms'] = array_keys(grantedOf_($r['name']));
    return $o;
  }, rosterList_());
  return ['ok' => true, 'rows' => $rows, 'keys' => $sup ? PERMS() : [], 'isSuper' => $sup];
}
function apiSetPerms($p = []) {
  if ($d = requireSuper_($p)) return $d;
  $name = str_($p['name'] ?? '');
  if (!in_array($name, rosterNames_(), true)) return ['ok' => false, 'msg' => '名單裡沒有「' . $name . '」'];
  if (isAdminUser_($name)) return ['ok' => false, 'msg' => $name . ' 是超級管理者，本來就有全部功能'];
  $valid = []; foreach (PERMS() as $x) $valid[$x['k']] = true;
  $list = array_values(array_filter(array_map('strval', (array)($p['perms'] ?? [])), function ($k) use ($valid) { return isset($valid[$k]); }));
  withLock_(function () use ($name, $list) { setUser_($name, ['perms' => $list ? jenc_($list) : '']); });
  $txt = implode('、', array_map('permLabel_', $list)) ?: '（全部收回）';
  log_('權限授權', '', '', '', $name, $txt . '（操作者 ' . actorOf_($p) . '）');
  return ['ok' => true, 'msg' => $name . ' 的後臺權限已更新：' . ($list ? $txt : '無')];
}
function apiGetUiSettings($p = []) {
  if ($d = requireAdmin_($p, 'brand')) return $d;
  $eff = effectiveUi_(); $vals = [];
  foreach (UI_KEYS() as $x) $vals[$x['k']] = (string)($eff[$x['k']] ?? '');
  return ['ok' => true, 'keys' => UI_KEYS(), 'values' => (object)$vals, 'overridden' => array_keys(uiOverride_())];
}
function apiSaveUiSettings($p = []) {
  if ($d = requireAdmin_($p, 'brand')) return $d;
  $vals = is_array($p['values'] ?? null) ? $p['values'] : [];
  if (!empty($vals['__reset'])) $vals = [];
  $o = [];
  foreach (UI_KEYS() as $x) {
    if (!array_key_exists($x['k'], $vals)) continue;
    $v = ucut_(trim(str_replace(['<', '>'], '', (string)($vals[$x['k']] ?? ''))), 200);
    if ($v !== (string)(CFG()['UI'][$x['k']] ?? '')) $o[$x['k']] = $v;
  }
  if ($o) setProp_('UI_OVERRIDE', jenc_($o)); else delProp_('UI_OVERRIDE');
  log_('修改頁首頁尾文字', '', '', '', actorOf_($p), implode('、', array_keys($o)) ?: '（全部還原為設定檔）');
  return ['ok' => true, 'msg' => $o ? '已儲存，大家重新整理後生效' : '已還原為設定檔的值', 'ui' => effectiveUi_()];
}
