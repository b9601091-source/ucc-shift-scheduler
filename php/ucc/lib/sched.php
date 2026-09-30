<?php
/* 班表 API：讀月份、填班、取消、交棒、鎖定、開放日、注意事項、建立月份（對應 Apps Script 版 Code.gs 同名函式，回傳形狀相同） */
if (!defined('UCC')) { http_response_code(404); exit; }

function apiGetBootstrap($p = []) {
  $months = listMonths_();
  $out = ['months' => array_map(function ($n) { return ['name' => $n, 'label' => decodeMonth_($n)['label']]; }, $months),
    'today' => todayIso_(),
    'rules' => ['maxShifts' => CFG()['MAX_SHIFTS_PER_MONTH'], 'maxConsecutive' => CFG()['MAX_CONSECUTIVE_DAYS'], 'deadlineDay' => CFG()['DEADLINE_DAY'],
      'openDay' => CFG()['OPEN_DAY'], 'maxFail' => CFG()['LOGIN_MAX_FAIL'], 'lockMin' => CFG()['LOGIN_LOCK_MIN']],
    'ui' => effectiveUi_() + ['pilotNote' => CFG()['PILOT_NOTE'], 'appVersion' => APP_VERSION, 'changelog' => CHANGELOG()]];
  // 預設顯示「次月」
  $t = todayIso_(); $dRoc = (int)substr($t, 0, 4) - 1911; $dMon = (int)substr($t, 5, 2) + 1;
  if ($dMon > 12) { $dMon = 1; $dRoc++; }
  $wantKey = $dRoc * 100 + $dMon; $pick = '';
  foreach ($months as $n) if (!$pick && decodeMonth_($n)['key'] === $wantKey) $pick = $n;
  $out['defaultMonth'] = $pick ?: ($months ? $months[0] : '');
  $out['lock'] = lockState_();
  $out['currentMonth'] = currentMonthSheet_();
  if ($out['lock']['locked'] && $out['currentMonth']) $out['defaultMonth'] = $out['currentMonth'];
  $out['roster'] = array_map(function ($r) { return ['seq' => $r['seq'], 'label' => fwSeq_($r['seq']) . '．' . (isReadOnly_($r['name']) ? $r['name'] : maskName_($r['name']))]; }, rosterList_());
  return $out;
}
function apiGetMonth($p = []) {
  $who = actorOf_($p);
  if (!$who) return ['needLogin' => true];
  $m = str_($p['month'] ?? '');
  if ($m === '' && !listMonths_()) {
    // 還沒有任何月份（例如剛裝好、尚未整批搬移）：回空的月份，讓超級管理者能登入進後臺做匯入
    $out = ['name' => '', 'label' => '尚無月份資料', 'year' => 0, 'month' => 0, 'days' => [], 'order' => [], 'notes' => '', 'prevMonths' => [], 'prevCounts' => (object)[], 'today' => todayIso_(), 'empty' => true];
  } else {
    if (!monthExists_($m)) return ['ok' => false, 'msg' => '找不到月份 ' . $m];
    $out = monthData_($m);
  }
  $out['lock'] = lockState_(); $out['forcedMonth'] = false;
  $out['viewer'] = ['name' => $who, 'readOnly' => isReadOnly_($who), 'isDefaultPw' => isDefaultPw_($who), 'defaultPw' => CFG()['DEFAULT_PASSWORD'],
    'perms' => permsOf_($who), 'isSuper' => isAdminUser_($who), 'canAdmin' => anyPerm_($who)];
  return $out;
}
function slot_($month, $iso, $shift) { return q1_('SELECT closed, name FROM slots WHERE month = ? AND iso = ? AND shift = ?', [$month, $iso, $shift]); }

function apiSubmitShift($p = []) {
  $actor = actorOf_($p);
  if (!$actor) return needLogin_();
  $m = str_($p['month'] ?? ''); $iso = str_($p['iso'] ?? ''); $sh = str_($p['shift'] ?? ''); $name = str_($p['name'] ?? '');
  // 會務帳號不參與排班：不能填自己；有「代管班表」權限時可替藥師代填
  if (isReadOnly_($actor) && ($name === $actor || !can_($actor, 'manage'))) return ['ok' => false, 'msg' => '此帳號不參與排班，不能填寫或修改班表'];
  if (isReadOnly_($name)) return ['ok' => false, 'msg' => '會務帳號不能被排班'];
  if ($lk = lockBlocks_($actor)) return $lk;
  if (!in_array($sh, ['am', 'pm'], true) || $name === '') return ['ok' => false, 'msg' => '參數不完整'];
  return withLock_(function () use ($m, $iso, $sh, $name, $actor, $p) {
    $s = slot_($m, $iso, $sh);
    if (!$s) return ['ok' => false, 'msg' => '找不到這一格（' . $iso . ' ' . $sh . '）'];
    if ((int)$s['closed'] === 1) return ['ok' => false, 'msg' => '這一天不開放排班'];
    if (str_($s['name']) !== '') return ['ok' => false, 'msg' => '慢了一步：這一格已由「' . $s['name'] . '」填走，請重新整理'];
    x_('UPDATE slots SET name = ? WHERE month = ? AND iso = ? AND shift = ?', [$name, $m, $iso, $sh]);
    $note = str_($p['warnings'] ?? '');
    if ($actor !== $name) $note = trim('代填（操作者 ' . $actor . '）　' . $note);
    log_('填班', $m, $iso, $sh, $name, $note);
    return ['ok' => true, 'data' => monthData_($m)];
  });
}
function apiCancelShift($p = []) {
  $actor = actorOf_($p);
  if (!$actor) return needLogin_();
  // 會務帳號：只有「代管班表」權限才能取消別人的班
  if (isReadOnly_($actor) && !can_($actor, 'manage')) return ['ok' => false, 'msg' => '此帳號不參與排班，不能填寫或修改班表'];
  if ($lk = lockBlocks_($actor)) return $lk;
  $m = str_($p['month'] ?? ''); $iso = str_($p['iso'] ?? ''); $sh = str_($p['shift'] ?? '');
  return withLock_(function () use ($m, $iso, $sh, $actor) {
    $s = slot_($m, $iso, $sh);
    if (!$s || str_($s['name']) === '') return ['ok' => false, 'msg' => '這一格本來就是空的'];
    $isAdmin = can_($actor, 'manage');
    if ($s['name'] !== $actor && !$isAdmin) return ['ok' => false, 'msg' => '這是「' . $s['name'] . '」的班，只有本人或' . CFG()['UI']['contactShort'] . '能取消'];
    x_('UPDATE slots SET name = \'\' WHERE month = ? AND iso = ? AND shift = ?', [$m, $iso, $sh]);
    log_($isAdmin && $s['name'] !== $actor ? '窗口取消' : '取消', $m, $iso, $sh, $s['name'], $isAdmin && $s['name'] !== $actor ? '操作者 ' . $actor : '');
    return ['ok' => true, 'data' => monthData_($m)];
  });
}
function setDone_($month, $name, $done, $action) {
  return withLock_(function () use ($month, $name, $done, $action) {
    $hit = q1_('SELECT pos FROM roster WHERE month = ? AND name = ?', [$month, $name]);
    if (!$hit) return ['ok' => false, 'msg' => '順位表裡找不到「' . $name . '」'];
    x_('UPDATE roster SET done = ? WHERE month = ? AND pos = ?', [$done ? 1 : 0, $month, (int)$hit['pos']]);
    log_($action, $month, '', '', $name, '');
    return ['ok' => true, 'data' => monthData_($month)];
  });
}
function apiSetDone($p = []) {
  $actor = actorOf_($p);
  if (!$actor) return needLogin_();
  $name = str_($p['name'] ?? '');
  // 會務帳號不在順位：不能替自己交棒；有「代管班表」權限時可代人交棒
  if (isReadOnly_($actor) && ($name === $actor || !can_($actor, 'manage'))) return ['ok' => false, 'msg' => '此帳號不參與排班，不能填寫或修改班表'];
  if ($lk = lockBlocks_($actor)) return $lk;
  if (!can_($actor, 'manage') && $actor !== $name) return ['ok' => false, 'msg' => '只能替自己交棒；要代人交棒請用後臺管理'];
  $done = !empty($p['done']) && $p['done'] !== 'false';
  return setDone_(str_($p['month'] ?? ''), $name, $done, $done ? '完成填表' : '取消完成');
}
function apiAdminSkip($p = []) {
  if ($d = requireAdmin_($p, 'skip')) return $d;
  return setDone_(str_($p['month'] ?? ''), str_($p['name'] ?? ''), true, '標記逾期放棄（' . actorOf_($p) . '）');
}

/* ---- 鎖定 ---- */
function apiSetLock($p = []) {
  if ($d = requireAdmin_($p, 'lock')) return $d;
  $m = str_($p['month'] ?? '');
  if (!isMonthName_($m) || !monthExists_($m)) return ['ok' => false, 'msg' => '請選擇要鎖定的月份'];
  $info = decodeMonth_($m); $until = isoOf_($info['y'], $info['m'], 1);
  if (strcmp(todayIso_(), $until) >= 0) return ['ok' => false, 'msg' => $info['label'] . ' 已經到期（' . $until . ' 起自動解除），鎖了也沒有作用'];
  setProp_('LOCK', jenc_(['month' => $m, 'by' => actorOf_($p), 'at' => todayIso_()]));
  log_('鎖定班表', $m, '', '', actorOf_($p), '至 ' . $until . ' 自動解除');
  return ['ok' => true, 'msg' => $info['label'] . ' 班表已鎖定，' . $until . ' 自動解除', 'lock' => lockState_()];
}
function apiClearLock($p = []) {
  if ($d = requireAdmin_($p, 'lock')) return $d;
  delProp_('LOCK');
  log_('解除鎖定', '', '', '', actorOf_($p), '');
  return ['ok' => true, 'msg' => '已解除鎖定', 'lock' => lockState_()];
}

/* ---- 開放日、注意事項 ---- */
function apiSetSlotOpen($p = []) {
  if ($d = requireAdmin_($p, 'openday')) return $d;
  $m = str_($p['month'] ?? ''); $iso = str_($p['iso'] ?? ''); $sh = str_($p['shift'] ?? '');
  $open = !empty($p['open']) && $p['open'] !== 'false';
  return withLock_(function () use ($m, $iso, $sh, $open, $p) {
    $s = slot_($m, $iso, $sh);
    if (!$s) return ['ok' => false, 'msg' => '找不到這一格（' . $iso . '）'];
    $isX = (int)$s['closed'] === 1; $cur = str_($s['name']);
    if ($open) {
      if (!$isX) return ['ok' => false, 'msg' => '這一格本來就是開放的'];
      x_('UPDATE slots SET closed = 0, name = \'\' WHERE month = ? AND iso = ? AND shift = ?', [$m, $iso, $sh]);
    } else {
      if ($cur !== '' && !$isX) return ['ok' => false, 'msg' => '這一格已由「' . $cur . '」填班，請先取消那一班再關閉'];
      if ($isX) return ['ok' => false, 'msg' => '這一格本來就是不開放的'];
      x_('UPDATE slots SET closed = 1, name = \'\' WHERE month = ? AND iso = ? AND shift = ?', [$m, $iso, $sh]);
    }
    log_($open ? '窗口開放班別' : '窗口關閉班別', $m, $iso, $sh, actorOf_($p), '');
    return ['ok' => true, 'data' => monthData_($m)];
  });
}
function apiSaveNotes($p = []) {
  if ($d = requireAdmin_($p, 'notes')) return $d;
  $m = str_($p['month'] ?? '');
  if (!monthExists_($m)) return ['ok' => false, 'msg' => '找不到月份 ' . $m];
  $lines = array_slice(explode("\n", str_replace("\r", '', (string)($p['notes'] ?? ''))), 0, 10);
  $text = implode("\n", array_values(array_filter(array_map('trim', $lines), function ($l) { return $l !== ''; })));
  withLock_(function () use ($m, $text) { x_('INSERT INTO notes (month, text) VALUES (?, ?) ON CONFLICT(month) DO UPDATE SET text = excluded.text', [$m, $text]); });
  log_('修改注意事項', $m, '', '', actorOf_($p), '');
  return ['ok' => true, 'data' => monthData_($m)];
}

/* ---- 建立月份 ---- */
function apiCreateNextMonth($p = []) {
  if ($d = requireAdmin_($p, 'create')) return $d;
  $r = withLock_(function () use ($p) { return createNextMonth_(actorOf_($p)); });
  if (!$r['ok']) return $r;
  return ['ok' => true, 'month' => $r['month'], 'data' => empty($p['lean']) ? apiGetMonth(['month' => $r['month'], 'token' => $p['token'] ?? '']) : null];
}
function apiCreateMonthsThrough($p = []) {
  if ($d = requireAdmin_($p, 'create')) return $d;
  $roc = (int)($p['roc'] ?? 0); $mon = (int)($p['month'] ?? 0);
  if (!($roc > 100 && $roc < 300) || !($mon >= 1 && $mon <= 12)) return ['ok' => false, 'msg' => '請填正確的民國年與月份（例如 116 年 6 月）'];
  $targetKey = $roc * 100 + $mon; $made = [];
  for ($i = 0; $i < 24; $i++) {
    $all = listMonths_();
    if (!$all) return ['ok' => false, 'msg' => '資料庫裡沒有任何月份可以當版型'];
    if (decodeMonth_($all[0])['key'] >= $targetKey) break;
    $r = withLock_(function () use ($p) { return createNextMonth_(actorOf_($p)); });
    if (!$r['ok']) return ['ok' => false, 'made' => $made, 'msg' => '建到第 ' . count($made) . ' 個就停住了（' . (implode('、', $made) ?: '無') . '）：' . $r['msg']];
    $made[] = $r['month'];
  }
  log_('連續建立月份', implode('、', $made), '', '', actorOf_($p), '目標 ' . $roc . '/' . $mon);
  return ['ok' => true, 'made' => $made, 'msg' => $made ? '已建立 ' . count($made) . ' 個月份：' . implode('、', $made) : '目標月份已經存在，沒有需要新增的'];
}
