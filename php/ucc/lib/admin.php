<?php
/* 增減人員、整批搬移（匯入 Apps Script 版匯出的 JSON）、備份匯出（JSON）、Excel 匯出 */
if (!defined('UCC')) { http_response_code(404); exit; }

/* ================= 增減人員 ================= */
function apiRosterAdd($p = []) {
  if ($d = requireAdmin_($p, 'roster')) return $d;
  $name = str_($p['name'] ?? '');
  if ($name === '') return ['ok' => false, 'msg' => '請輸入姓名'];
  if (ulen_($name) > 12) return ['ok' => false, 'msg' => '姓名太長了'];
  if (in_array($name, rosterNames_(), true)) return ['ok' => false, 'msg' => '名單裡已經有「' . $name . '」'];
  $months = affectedMonths_();
  if (!$months) return ['ok' => false, 'msg' => '沒有本月或之後的月份，請先建立月份'];
  return withLock_(function () use ($name, $months, $p) {
    $used = []; foreach (rosterList_() as $r) $used[strtoupper($r['seq'])] = true;
    $seq = '';
    for ($c = 65; $c <= 90 && !$seq; $c++) if (empty($used[chr($c)])) $seq = chr($c);
    if (!$seq) return ['ok' => false, 'msg' => '代號 A~Z 已經用完，請先移除不再排班的人'];
    $done = [];
    foreach ($months as $mn) {
      if (q1_('SELECT 1 FROM roster WHERE month = ? AND name = ?', [$mn, $name])) continue;
      $max = (int)(q1_('SELECT COALESCE(MAX(pos), 0) AS m FROM roster WHERE month = ?', [$mn])['m']);
      x_('INSERT INTO roster (month, pos, seq, name, done) VALUES (?,?,?,?,0)', [$mn, $max + 1, $seq, $name]);
      $done[] = $mn;
    }
    log_('新增人員', implode('、', $done), '', '', $name, '代號 ' . $seq . '（操作者 ' . actorOf_($p) . '）');
    return ['ok' => true, 'seq' => $seq, 'msg' => '已將 ' . $name . ' 加入順位最後（代號 ' . $seq . '）：' . implode('、', $done)];
  });
}
function apiRosterRemove($p = []) {
  if ($d = requireAdmin_($p, 'roster')) return $d;
  $name = str_($p['name'] ?? '');
  if ($name === '') return ['ok' => false, 'msg' => '請選擇要移除的人'];
  if (isAdminUser_($name)) return ['ok' => false, 'msg' => $name . ' 是超級管理者，請先在 config.php 的 ADMIN_NAMES 拿掉'];
  if (isReadOnly_($name)) return ['ok' => false, 'msg' => $name . ' 是會務人員帳號，要拿掉請改 config.php 的 STAFF_ACCOUNTS'];
  $months = affectedMonths_();
  if (!$months) return ['ok' => false, 'msg' => '沒有本月或之後的月份'];
  return withLock_(function () use ($name, $months, $p) {
    $busy = [];
    foreach ($months as $mn) if (q1_('SELECT 1 FROM slots WHERE month = ? AND name = ? AND closed = 0', [$mn, $name])) $busy[] = decodeMonth_($mn)['label'];
    if ($busy) return ['ok' => false, 'msg' => $name . ' 在 ' . implode('、', $busy) . ' 還有班，請先取消那些班再移除'];
    $done = [];
    foreach ($months as $mn) {
      if (!q1_('SELECT 1 FROM roster WHERE month = ? AND name = ?', [$mn, $name])) continue;
      $keep = array_values(array_filter(order_($mn), function ($o) use ($name) { return $o['name'] !== $name; }));
      x_('DELETE FROM roster WHERE month = ?', [$mn]);
      foreach ($keep as $i => $o) x_('INSERT INTO roster (month, pos, seq, name, done) VALUES (?,?,?,?,?)', [$mn, $i + 1, $o['seq'], $o['name'], $o['done'] ? 1 : 0]);
      $done[] = $mn;
    }
    x_('DELETE FROM users WHERE name = ?', [$name]);
    clearFail_($name);
    log_('移除人員', implode('、', $done), '', '', $name, '（操作者 ' . actorOf_($p) . '）');
    return ['ok' => true, 'msg' => '已將 ' . $name . ' 移出順位：' . (implode('、', $done) ?: '（沒有月份需要改）') . '。歷史月份的紀錄不受影響'];
  });
}

/* ================= 備份匯出 ================= */
/** 全部資料（含密碼雜湊與 secret；只有超級管理者能拿）。同一格式可再匯入本系統，也是 Apps Script 版 exportAll 的格式。 */
function exportAll_() {
  $months = [];
  foreach (array_reverse(listMonths_()) as $mn) {
    $days = [];
    foreach (qa_('SELECT iso, shift, closed, name FROM slots WHERE month = ? ORDER BY iso, shift', [$mn]) as $r) {
      if (!isset($days[$r['iso']])) $days[$r['iso']] = ['iso' => $r['iso'], 'amClosed' => true, 'pmClosed' => true, 'am' => '', 'pm' => ''];
      $days[$r['iso']][$r['shift'] . 'Closed'] = (int)$r['closed'] === 1;
      $days[$r['iso']][$r['shift']] = (string)$r['name'];
    }
    $months[] = ['name' => $mn, 'days' => array_values($days), 'order' => array_map(function ($o) { return ['seq' => $o['seq'], 'name' => $o['name'], 'done' => $o['done']]; }, order_($mn)), 'notes' => notes_($mn)];
  }
  $props = [];
  foreach (qa_('SELECT k, v FROM _props') as $r) if ($r['k'] === 'AUTH_SECRET' || $r['k'] === 'LOCK' || $r['k'] === 'UI_OVERRIDE') $props[$r['k']] = $r['v'];
  foreach (qa_('SELECT name, pw_hash, perms FROM users') as $u) {
    if (str_($u['pw_hash']) !== '') $props['pw_' . $u['name']] = $u['pw_hash'];
    if (str_($u['perms']) !== '') $props['perm_' . $u['name']] = $u['perms'];
  }
  return ['format' => 'ucc-export-1', 'exportedAt' => nowStr_(), 'source' => 'php ' . APP_VERSION,
    'months' => $months, 'openDays' => qa_('SELECT iso, open, note FROM open_days ORDER BY iso'), 'props' => (object)$props,
    'log' => qa_('SELECT ts, action, month, iso, shift, name, note FROM log ORDER BY id')];
}
function apiExportAll($p = []) {
  if ($d = requireSuper_($p)) return $d;
  log_('匯出全部資料', '', '', '', actorOf_($p), '');
  return ['ok' => true, 'data' => exportAll_()];
}
/** 整批搬移：載入 Apps Script 版（或本系統）匯出的 JSON。資料庫已有月份時要帶 replace=true 才會整個換掉。 */
function importAll_($j, $replace) {
  if (!is_array($j) || empty($j['months']) || !is_array($j['months'])) throw new Exception('匯入資料格式不對：沒有 months');
  if (listMonths_() && !$replace) throw new Exception('資料庫已有月份資料；要整個覆蓋請勾「取代現有資料」');
  foreach (['slots', 'roster', 'notes', 'months', 'users', 'open_days'] as $t) x_("DELETE FROM $t");
  foreach (['LOCK', 'UI_OVERRIDE', 'AUTH_SECRET'] as $k) delProp_($k);
  x_("DELETE FROM _props WHERE k LIKE 'fail_%'");
  $n = ['months' => 0, 'slots' => 0, 'roster' => 0, 'users' => 0, 'openDays' => 0, 'log' => 0];
  foreach ($j['months'] as $mo) {
    $name = str_($mo['name'] ?? '');
    if (!isMonthName_($name)) continue;
    $info = decodeMonth_($name);
    if (strpos($name, '/') === false) $name = encodeMonth_($info['roc'], $info['m']);
    x_('INSERT OR REPLACE INTO months (name, roc, m, created) VALUES (?,?,?,?)', [$name, $info['roc'], $info['m'], nowStr_()]);
    $n['months']++;
    foreach ((array)($mo['days'] ?? []) as $d) {
      $iso = str_($d['iso'] ?? '');
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) continue;
      if ((int)substr($iso, 5, 2) !== $info['m']) continue;   // 只收當月的日子（Apps Script 版的格線含前後月補位）
      foreach (['am', 'pm'] as $sh) {
        $closed = !empty($d[$sh . 'Closed']);
        $nm = $closed ? '' : str_($d[$sh] ?? '');
        x_('INSERT OR REPLACE INTO slots (month, iso, shift, closed, name) VALUES (?,?,?,?,?)', [$name, $iso, $sh, $closed ? 1 : 0, $nm]);
        $n['slots']++;
      }
    }
    foreach (array_values((array)($mo['order'] ?? [])) as $i => $o) {
      $nm = str_($o['name'] ?? ''); $seq = str_($o['seq'] ?? '');
      if ($nm === '' && $seq === '') continue;
      x_('INSERT OR REPLACE INTO roster (month, pos, seq, name, done) VALUES (?,?,?,?,?)', [$name, $i + 1, $seq, $nm, !empty($o['done']) ? 1 : 0]);
      $n['roster']++;
    }
    x_('INSERT OR REPLACE INTO notes (month, text) VALUES (?, ?)', [$name, str_replace("\r", '', (string)($mo['notes'] ?? ''))]);
  }
  foreach ((array)($j['openDays'] ?? []) as $od) {
    $iso = str_($od['iso'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) continue;
    $flag = $od['open'] ?? 0;
    $open = ($flag === true || $flag === 1 || $flag === '1' || $flag === '是' || strtoupper((string)$flag) === 'TRUE' || $flag === 'Y') ? 1 : 0;
    x_('INSERT OR REPLACE INTO open_days (iso, open, note) VALUES (?,?,?)', [$iso, $open, str_($od['note'] ?? '')]);
    $n['openDays']++;
  }
  $props = (array)($j['props'] ?? []);
  if (!empty($props['AUTH_SECRET'])) setProp_('AUTH_SECRET', (string)$props['AUTH_SECRET']);
  if (!empty($props['LOCK'])) setProp_('LOCK', (string)$props['LOCK']);
  if (!empty($props['UI_OVERRIDE'])) setProp_('UI_OVERRIDE', (string)$props['UI_OVERRIDE']);
  $users = [];
  foreach ($props as $k => $v) {
    if (strpos($k, 'pw_') === 0) $users[substr($k, 3)]['pw_hash'] = (string)$v;
    if (strpos($k, 'perm_') === 0) $users[substr($k, 5)]['perms'] = (string)$v;
  }
  foreach ($users as $nm => $f) { setUser_($nm, $f + ['pw_hash' => '', 'perms' => '']); $n['users']++; }
  foreach ((array)($j['log'] ?? []) as $l) {
    if (!is_array($l)) continue;
    x_('INSERT INTO log (ts, action, month, iso, shift, name, note) VALUES (?,?,?,?,?,?,?)', [str_($l['ts'] ?? ($l['時間'] ?? '')), str_($l['action'] ?? ''), str_($l['month'] ?? ''), str_($l['iso'] ?? ''), str_($l['shift'] ?? ''), str_($l['name'] ?? ''), str_($l['note'] ?? '')]);
    $n['log']++;
  }
  return $n;
}
function apiImportAll($p = []) {
  if ($d = requireSuper_($p)) return $d;
  $j = $p['data'] ?? null;
  if (is_string($j)) { $j = json_decode($j, true); if (!is_array($j)) return ['ok' => false, 'msg' => 'JSON 解析失敗，請確認貼上的是完整內容']; }
  if (is_array($j) && isset($j['data']) && isset($j['data']['months'])) $j = $j['data'];   // 直接貼整個匯出回應也可以
  $replace = !empty($p['replace']) && $p['replace'] !== 'false';
  try {
    $n = withLock_(function () use ($j, $replace) { return importAll_($j, $replace); });
  } catch (Exception $e) { return ['ok' => false, 'msg' => $e->getMessage()]; }
  log_('整批搬移', '', '', '', actorOf_($p), jenc_($n));
  return ['ok' => true, 'msg' => '已匯入：月份 ' . $n['months'] . '、班別格 ' . $n['slots'] . '、順位列 ' . $n['roster'] . '、帳號 ' . $n['users'] . '、可排班日 ' . $n['openDays'] . '、紀錄 ' . $n['log'] . '。密碼與授權一併搬過來，原密碼可直接登入', 'counts' => $n];
}

/* ================= Excel 匯出 ================= */
/** 一個月一張工作表，版面比照原試算表：第 2 列星期、第 3 列起每週三列（日期／早班／晚班）、J~L 順位、N 注意事項 */
function apiExportXlsx($p = []) {
  $who = actorOf_($p);
  if (!$who) return needLogin_();   // 任何登入者都可匯出（本來就看得到的班表）
  $want = str_($p['month'] ?? '');
  $months = $want === '' || $want === 'all' ? array_reverse(listMonths_()) : [$want];
  $sheets = [];
  $ui = effectiveUi_();
  foreach ($months as $mn) {
    if (!monthExists_($mn)) continue;
    $rows = [];   // rows[r][c] = text（0-based）
    $set = function ($r, $c, $v) use (&$rows) { $rows[$r][$c] = (string)$v; };
    foreach (['周日', '周一', '週二', '週三', '週四', '週五', '週六'] as $i => $t) $set(1, 1 + $i, $t);
    $set(1, 9, '本月排班順位'); $set(2, 9, '排序'); $set(2, 10, '姓名'); $set(2, 11, '完成註記'); $set(1, 13, '當月班表填寫注意事項：');
    $days = days_($mn); $w = 0;
    foreach (array_chunk($days, 7) as $wk) {
      $dr = 2 + $w * 3;
      $set($dr + 1, 0, $ui['shifts']['am']['label'] . "\n" . $ui['shifts']['am']['hours']);
      $set($dr + 2, 0, $ui['shifts']['pm']['label'] . "\n" . $ui['shifts']['pm']['hours']);
      foreach ($wk as $c => $d) {
        if (!$d['inMonth']) continue;
        $set($dr, 1 + $c, (int)substr($d['iso'], 5, 2) . '/' . $d['dayNum']);
        $set($dr + 1, 1 + $c, $d['amClosed'] ? 'X' : (string)$d['am']);
        $set($dr + 2, 1 + $c, $d['pmClosed'] ? 'X' : (string)$d['pm']);
      }
      $w++;
    }
    foreach (order_($mn) as $i => $o) { $set(3 + $i, 9, $o['seq']); $set(3 + $i, 10, $o['name']); $set(3 + $i, 11, $o['done'] ? 'TRUE' : 'FALSE'); }
    foreach (explode("\n", notes_($mn)) as $i => $line) if ($line !== '') $set(2 + $i, 13, $line);
    $sheets[] = ['name' => str_replace('/', '-', $mn), 'rows' => $rows];
  }
  if (!$sheets) return ['ok' => false, 'msg' => '沒有可匯出的月份'];
  $bin = xlsx_($sheets);
  log_('匯出 Excel', $want, '', '', $who, count($sheets) . ' 張');
  return ['ok' => true, 'filename' => ($ui['systemName'] ?? '排班') . '_' . ($want === '' || $want === 'all' ? '全部' : str_replace('/', '-', $want)) . '_' . todayIso_() . '.xlsx', 'base64' => base64_encode($bin)];
}
/** 最小 xlsx 產生器（不依賴 ZipArchive：手寫 zip「儲存」法） */
function xlsx_($sheets) {
  $x = function ($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8'); };
  $col = function ($c) { $s = ''; $c++; while ($c > 0) { $m = ($c - 1) % 26; $s = chr(65 + $m) . $s; $c = intdiv($c - 1, 26); } return $s; };
  $files = [];
  $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . implode('', array_map(function ($i) { return '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'; }, array_keys($sheets))) . '</Types>';
  $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
  $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
    . implode('', array_map(function ($i) use ($sheets, $x) { return '<sheet name="' . $x(ucut_($sheets[$i]['name'], 31)) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>'; }, array_keys($sheets))) . '</sheets></workbook>';
  $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . implode('', array_map(function ($i) { return '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>'; }, array_keys($sheets)))
    . '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
  $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf></cellXfs></styleSheet>';
  foreach ($sheets as $i => $sh) {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="11" customWidth="1"/><col min="2" max="8" width="10" customWidth="1"/><col min="10" max="10" width="6" customWidth="1"/><col min="11" max="11" width="10" customWidth="1"/><col min="12" max="12" width="10" customWidth="1"/><col min="14" max="14" width="40" customWidth="1"/></cols><sheetData>';
    $rows = $sh['rows']; ksort($rows);
    foreach ($rows as $r => $cells) {
      ksort($cells);
      $xml .= '<row r="' . ($r + 1) . '">';
      foreach ($cells as $c => $v) $xml .= '<c r="' . $col($c) . ($r + 1) . '" t="inlineStr" s="0"><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
      $xml .= '</row>';
    }
    $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $xml . '</sheetData></worksheet>';
  }
  return zipStore_($files);
}
function zipStore_($files) {
  $out = ''; $cd = ''; $off = 0; $n = 0;
  $dt = now_(); $time = ((int)$dt->format('G') << 11) | ((int)$dt->format('i') << 5) | ((int)$dt->format('s') >> 1);
  $date = (((int)$dt->format('Y') - 1980) << 9) | ((int)$dt->format('n') << 5) | (int)$dt->format('j');
  foreach ($files as $name => $data) {
    $crc = crc32($data); $len = strlen($data);
    $hdr = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0) . $name;
    $out .= $hdr . $data;
    $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
    $off += strlen($hdr) + $len; $n++;
  }
  return $out . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), $off, 0);
}
