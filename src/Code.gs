/**
 * UCC 排班系統 — 後端（Google Apps Script）
 * ------------------------------------------------------------------
 * ★ 組織專屬的設定（試算表 ID、管理者、畫面文字、規則、假日）全部在 Config.gs，
 *   這個檔案不含任何組織專屬內容，換組織不必動它。
 *
 * 設計原則：直接讀寫「現有的 Google 試算表月份分頁」（115/10、115/11…），
 *          不另建資料表。窗口照樣可以打開試算表看、改、列印。
 *
 * 分頁格式假設（依現有檔案）：
 *   - 分頁名稱＝民國年/月，例：「115/10」＝ 115 年 10 月
 *     （也相容「11510」這種無斜線寫法——匯出成 xlsx 時 Excel 會把「/」拿掉）
 *   - B~H 欄為週日~週六
 *   - 每週三列一組：第 1 列＝日期、第 2 列＝早班、第 3 列＝晚班
 *   - 班別格內容：X ＝ 不可排班　空白 ＝ 可排班　其他 ＝ 已排該藥師
 *   - 右側面板：含「排序 / 姓名 / 完成註記」三欄
 *   - 「當月班表填寫注意事項：」標題下方（同欄）為注意事項文字
 * ------------------------------------------------------------------
 */

/* CFG 與 HOLIDAYS 定義在 Config.gs */

/* ============================ 進入點 ============================ */

function doGet() {
  return HtmlService.createHtmlOutputFromFile('Index')
    .setTitle(CFG.UI.orgShort + ' ' + CFG.UI.systemName)
    .addMetaTag('viewport', 'width=device-width, initial-scale=1')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

var _SS = null, _TZ = null;
function ss_() {
  if (!_SS) {
    _SS = CFG.SPREADSHEET_ID ? SpreadsheetApp.openById(CFG.SPREADSHEET_ID)
                             : SpreadsheetApp.getActive();
  }
  return _SS;
}
function tz_() {
  if (!_TZ) _TZ = ss_().getSpreadsheetTimeZone() || 'Asia/Taipei';
  return _TZ;
}
function iso_(d) { return Utilities.formatDate(d, tz_(), 'yyyy-MM-dd'); }
function todayIso_() { return iso_(new Date()); }

/* ============================ 帳號與登入 ============================ */
/*
 * 密碼「不」存在試算表裡——藥師對那份表有編輯權，放隱藏分頁等於沒藏。
 * 一律存在 Apps Script 的 ScriptProperties，而且只存加鹽雜湊，救不回原文（忘記只能重設）。
 * 登入憑證用 HMAC 簽章的無狀態 token，不必為每次登入寫一筆資料。
 */

function props_() { return PropertiesService.getScriptProperties(); }

function secret_() {
  var p = props_(), v = p.getProperty('AUTH_SECRET');
  if (!v) { v = Utilities.getUuid() + Utilities.getUuid(); p.setProperty('AUTH_SECRET', v); }
  return v;
}

function hex_(bytes) {
  return bytes.map(function (b) {
    var v = (b < 0 ? b + 256 : b).toString(16);
    return v.length === 1 ? '0' + v : v;
  }).join('');
}

function hashPw_(name, pw) {
  return hex_(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256,
    secret_() + '|' + name + '|' + pw, Utilities.Charset.UTF_8));
}

function isDefaultPw_(name) { return !props_().getProperty('pw_' + name); }

function checkPw_(name, pw) {
  var h = props_().getProperty('pw_' + name);
  if (!h) return String(pw) === CFG.DEFAULT_PASSWORD;   // 沒設過 → 用預設密碼
  return h === hashPw_(name, pw);
}

function makeToken_(name) {
  var body = name + '|' + (Date.now() + CFG.TOKEN_DAYS * 86400000);
  return body + '|' + hex_(Utilities.computeHmacSha256Signature(body, secret_()));
}

/** token 有效就回傳姓名，否則 null */
function tokenName_(token) {
  if (!token) return null;
  var t = String(token), i = t.lastIndexOf('|');
  if (i < 0) return null;
  var body = t.substring(0, i), sig = t.substring(i + 1);
  if (hex_(Utilities.computeHmacSha256Signature(body, secret_())) !== sig) return null;
  var parts = body.split('|');
  if (parts.length !== 2 || Number(parts[1]) < Date.now()) return null;
  return parts[0];
}

/** 這次操作是誰做的（一律看登入 token，沒有另外的管理密碼了） */
function actorOf_(payload) { return tokenName_(payload.token); }

/* ============================ 權限 ============================ */
/*
 * 兩層：
 *   1. CFG.ADMIN_NAMES ＝ 超級管理者：全部後臺功能，而且只有他們能「授權」。
 *   2. 其他人（含 STAFF_ACCOUNTS 的會務人員）：後臺功能由超級管理者逐項授權，
 *      存在 ScriptProperties 的 perm_<姓名>（JSON 陣列，內容是下面 PERMS 的 k）。
 */
var PERMS = [
  { k: 'lock',    label: '班表鎖定' },
  { k: 'notes',   label: '編輯注意事項' },
  { k: 'skip',    label: '標記逾期放棄' },
  { k: 'create',  label: '建立月份分頁' },
  { k: 'openday', label: '編輯開放日' },
  { k: 'resetpw', label: '使用者密碼重設' },
  { k: 'roster',  label: '增減人員' },
  { k: 'manage',  label: '代管班表（取消他人的班、代交棒、鎖定期間仍可改）' }
];

function isAdminUser_(name) { return !!name && CFG.ADMIN_NAMES.indexOf(name) >= 0; }

/** 被授權的項目（不含超級管理者的隱含權限） */
function grantedOf_(name) {
  var out = {}, raw = name ? props_().getProperty('perm_' + name) : '';
  if (!raw) return out;
  try { JSON.parse(raw).forEach(function (k) { out[k] = true; }); } catch (e) {}
  return out;
}

/** 這個人實際擁有的權限表（含 grant＝可授權，只有超級管理者有） */
function permsOf_(name) {
  var sup = isAdminUser_(name), g = grantedOf_(name), out = { grant: sup };
  PERMS.forEach(function (p) { out[p.k] = sup || !!g[p.k]; });
  return out;
}

function can_(name, perm) {
  if (!name) return false;
  if (isAdminUser_(name)) return true;
  if (perm === 'grant') return false;
  return !!grantedOf_(name)[perm];
}

function anyPerm_(name) {
  var p = permsOf_(name);
  return Object.keys(p).some(function (k) { return p[k]; });
}

function permLabel_(k) {
  if (k === 'grant') return '權限授權';
  var l = k;
  PERMS.forEach(function (p) { if (p.k === k) l = p.label; });
  return l;
}

/** 後臺功能的統一守門：必須已登入，且擁有該項權限 */
function requireAdmin_(payload, perm) {
  var who = actorOf_(payload || {});
  if (!who) return { ok: false, needLogin: true, msg: '登入已過期，請重新登入' };
  if (!can_(who, perm)) return { ok: false, msg: '你沒有「' + permLabel_(perm) + '」的權限' };
  return null;                      // null ＝ 通過
}

function requireSuper_(payload) {
  var who = actorOf_(payload || {});
  if (!who) return { ok: false, needLogin: true, msg: '登入已過期，請重新登入' };
  if (!isAdminUser_(who)) return { ok: false, msg: '只有超級管理者（Config.gs 的 ADMIN_NAMES）能授權' };
  return null;
}

/** 名單與各人的授權狀態（增減人員、權限授權兩個分項共用） */
function adminUsers(payload) {
  var who = actorOf_(payload || {});
  if (!who) return { ok: false, needLogin: true, msg: '登入已過期，請重新登入' };
  var sup = isAdminUser_(who);
  if (!sup && !can_(who, 'roster')) return { ok: false, msg: '你沒有「增減人員」的權限' };
  var rows = rosterList_().map(function (r) {
    var o = { seq: r.seq, name: r.name, staff: isReadOnly_(r.name), superAdmin: isAdminUser_(r.name) };
    if (sup) o.perms = Object.keys(grantedOf_(r.name));
    return o;
  });
  return { ok: true, rows: rows, keys: sup ? PERMS : [], isSuper: sup };
}

/** 超級管理者設定某人的授權項目（整組覆蓋） */
function setPerms(payload) {
  var deny = requireSuper_(payload); if (deny) return deny;
  var name = String(payload.name || '').trim();
  if (rosterNames_().indexOf(name) < 0) return { ok: false, msg: '名單裡沒有「' + name + '」' };
  if (isAdminUser_(name)) return { ok: false, msg: name + ' 是超級管理者，本來就有全部功能' };
  var valid = {};
  PERMS.forEach(function (p) { valid[p.k] = true; });
  var list = (payload.perms || []).map(String).filter(function (k) { return valid[k]; });
  if (list.length) props_().setProperty('perm_' + name, JSON.stringify(list));
  else props_().deleteProperty('perm_' + name);
  var txt = list.map(permLabel_).join('、') || '（全部收回）';
  log_('權限授權', '', '', '', name, txt + '（操作者 ' + actorOf_(payload) + '）');
  return { ok: true, msg: name + ' 的後臺權限已更新：' + (list.length ? txt : '無') };
}

/* ============================ 班表鎖定 ============================ */
/*
 * 鎖定某個月份的班表：鎖定期間非管理者只看得到「當月」班表且不能異動。
 * 到了被鎖月份的 1 號自動失效（例：9 月鎖 115/10，10/1 起自動解除，
 * 大家就能開始填 11 月）。不需要另外跑排程。
 */
function lockState_() {
  var raw = props_().getProperty('LOCK');
  if (!raw) return { locked: false };
  var obj;
  try { obj = JSON.parse(raw); } catch (e) { return { locked: false }; }
  if (!obj || !obj.month) return { locked: false };
  var info = decodeMonth_(obj.month);
  var until = Utilities.formatDate(new Date(info.y, info.m - 1, 1), tz_(), 'yyyy-MM-dd');
  if (todayIso_() >= until) {
    props_().deleteProperty('LOCK');          // 到期自動解除
    return { locked: false, expired: obj.month };
  }
  return { locked: true, month: obj.month, label: info.label, until: until, by: obj.by || '' };
}

/** 今天所在的月份對應到哪個分頁（鎖定期間大家只看得到這個月） */
function currentMonthSheet_() {
  var t = todayIso_();
  var key = (parseInt(t.substring(0, 4), 10) - 1911) * 100 + parseInt(t.substring(5, 7), 10);
  var hit = '';
  listMonths_().forEach(function (n) { if (!hit && decodeMonth_(n).key === key) hit = n; });
  return hit;
}

function setLock(payload) {
  var deny = requireAdmin_(payload, 'lock'); if (deny) return deny;
  var m = String(payload.month || '').trim();
  if (!isMonthSheet_(m)) return { ok: false, msg: '請選擇要鎖定的月份' };
  var info = decodeMonth_(m);
  var until = Utilities.formatDate(new Date(info.y, info.m - 1, 1), tz_(), 'yyyy-MM-dd');
  if (todayIso_() >= until) {
    return { ok: false, msg: info.label + ' 已經到期（' + until + ' 起自動解除），鎖了也沒有作用' };
  }
  props_().setProperty('LOCK', JSON.stringify({ month: m, by: actorOf_(payload), at: todayIso_() }));
  log_('鎖定班表', m, '', '', actorOf_(payload), '至 ' + until + ' 自動解除');
  return { ok: true, msg: info.label + ' 班表已鎖定，' + until + ' 自動解除', lock: lockState_() };
}

function clearLock(payload) {
  var deny = requireAdmin_(payload, 'lock'); if (deny) return deny;
  props_().deleteProperty('LOCK');
  log_('解除鎖定', '', '', '', actorOf_(payload), '');
  return { ok: true, msg: '已解除鎖定', lock: lockState_() };
}

/** 寫入類動作的鎖定守門：鎖定期間只有管理者能改 */
function lockBlocks_(actor) {
  var st = lockState_();
  if (!st.locked || can_(actor, 'manage')) return null;
  return { ok: false, msg: st.label + ' 班表已由' + CFG.UI.contactShort + '鎖定，' + st.until + ' 起自動解除' };
}

/** 目前名單（取最新月份分頁的順位欄），外加不參與排班的會務人員帳號（CFG.STAFF_ACCOUNTS） */
function rosterList_() {
  var out = [], all = listMonths_();
  if (all.length) {
    try {
      parseSheet_(all[0]).order.forEach(function (o) {
        if (o.name && o.seq) out.push({ seq: o.seq, name: o.name });
      });
    } catch (e) { /* 分頁壞掉就只剩會務人員帳號 */ }
  }
  out.sort(function (a, b) { return a.seq < b.seq ? -1 : 1; });   // 登入頁固定按代號排
  (CFG.STAFF_ACCOUNTS || []).forEach(function (a) { out.push({ seq: a.seq, name: a.name }); });
  return out;
}

function rosterNames_() { return rosterList_().map(function (r) { return r.name; }); }

/** 姓名遮罩：只留頭尾，中間換成 ○（登入頁不外露完整姓名） */
function maskName_(n) {
  n = String(n || '');
  if (n.length <= 1) return n;
  if (n.length === 2) return n.charAt(0) + '○';
  return n.charAt(0) + new Array(n.length - 1).join('○') + n.charAt(n.length - 1);
}

/** 不參與排班的帳號（會務人員）：能看、能被授權後臺功能，但不能填班／交棒 */
function isReadOnly_(name) {
  return (CFG.STAFF_ACCOUNTS || []).some(function (a) { return a.name === name; });
}

/** 代號轉全形（Ａ~Ｚ），登入下拉的每一列寬度才會一致 */
function fwSeq_(seq) {
  return String(seq).replace(/[A-Za-z]/g, function (c) {
    return String.fromCharCode(c.charCodeAt(0) + 0xFEE0);
  });
}

function seqToName_(seq) {
  var hit = '';
  rosterList_().forEach(function (r) { if (!hit && r.seq === String(seq)) hit = r.name; });
  return hit;
}

/* ---- 登入失敗次數限制：擋暴力猜密碼 ----
 * 以「代號對應的姓名」為單位計數，存在 ScriptProperties。
 * 連錯 CFG.LOGIN_MAX_FAIL 次鎖 CFG.LOGIN_LOCK_MIN 分鐘；
 * 超過 CFG.LOGIN_FAIL_WINDOW_MIN 分鐘沒再錯，次數自動歸零（偶爾打錯不會累積）。
 */
function failState_(name) {
  var raw = props_().getProperty('fail_' + name);
  if (!raw) return { n: 0, until: 0, last: 0 };
  try { return JSON.parse(raw); } catch (e) { return { n: 0, until: 0, last: 0 }; }
}

/** 還在鎖定中就回傳剩餘分鐘，否則回 0 */
function lockedOutMin_(name) {
  var f = failState_(name), now = Date.now();
  return (f.until && f.until > now) ? Math.ceil((f.until - now) / 60000) : 0;
}

function noteFail_(name) {
  var f = failState_(name), now = Date.now();
  if (f.last && (now - f.last) > CFG.LOGIN_FAIL_WINDOW_MIN * 60000) f.n = 0;
  f.n = (f.n || 0) + 1;
  f.last = now;
  if (f.n >= CFG.LOGIN_MAX_FAIL) {
    f.until = now + CFG.LOGIN_LOCK_MIN * 60000;
    f.n = 0;                                   // 鎖完之後重新給滿次數
  }
  props_().setProperty('fail_' + name, JSON.stringify(f));
  return f;
}

function clearFail_(name) { props_().deleteProperty('fail_' + name); }

function login(payload) {
  var seq = String(payload && payload.seq != null ? payload.seq : '').trim();
  if (!seq) return { ok: false, msg: '請先選擇使用者' };
  var name = seqToName_(seq);
  if (!name) return { ok: false, msg: '名單裡沒有這個代號，請洽' + CFG.UI.contactShort };
  var waitMin = lockedOutMin_(name);
  if (waitMin > 0) {
    log_('登入遭鎖定', '', '', '', name, '剩 ' + waitMin + ' 分鐘');
    return { ok: false, msg: '密碼錯誤次數過多，已鎖定，請 ' + waitMin +
             ' 分鐘後再試，或洽' + CFG.UI.contactShort + '重設密碼' };
  }

  if (!checkPw_(name, String(payload.pw == null ? '' : payload.pw))) {
    var st = noteFail_(name);
    if (st.until > Date.now()) {
      log_('登入失敗·觸發鎖定', '', '', '', name, '連錯 ' + CFG.LOGIN_MAX_FAIL + ' 次');
      return { ok: false, msg: '密碼錯誤 ' + CFG.LOGIN_MAX_FAIL + ' 次，已鎖定 ' +
               CFG.LOGIN_LOCK_MIN + ' 分鐘。忘記密碼請洽' + CFG.UI.contactShort + '重設' };
    }
    log_('登入失敗', '', '', '', name, '第 ' + st.n + ' 次');
    return { ok: false, msg: '密碼不正確（再錯 ' + (CFG.LOGIN_MAX_FAIL - st.n) +
             ' 次會鎖定 ' + CFG.LOGIN_LOCK_MIN + ' 分鐘），忘記請洽' + CFG.UI.contactShort + '重設' };
  }

  clearFail_(name);
  log_('登入', '', '', '', name, '');
  return { ok: true, token: makeToken_(name), name: name,
           isDefault: isDefaultPw_(name), readOnly: isReadOnly_(name) };
}

function changePassword(payload) {
  var name = tokenName_(payload.token);
  if (!name) return { ok: false, needLogin: true, msg: '登入已過期，請重新登入' };
  if (!checkPw_(name, String(payload.oldPw == null ? '' : payload.oldPw))) {
    return { ok: false, msg: '目前密碼不正確' };
  }
  var np = String(payload.newPw == null ? '' : payload.newPw);
  if (!/^[0-9]{4,6}$/.test(np)) return { ok: false, msg: '新密碼請設 4~6 位數字' };
  if (np === CFG.DEFAULT_PASSWORD) return { ok: false, msg: '新密碼不能跟預設的一樣' };
  props_().setProperty('pw_' + name, hashPw_(name, np));
  log_('修改密碼', '', '', '', name, '');
  return { ok: true, msg: '密碼已更新' };
}

/** 窗口把某人的密碼打回預設值 0000 */
function adminResetPw(payload) {
  var deny = requireAdmin_(payload, 'resetpw'); if (deny) return deny;
  var name = String(payload.name || '').trim();
  if (!name) return { ok: false, msg: '請選擇要重設的藥師' };
  props_().deleteProperty('pw_' + name);
  clearFail_(name);                     // 順便解除登入鎖定，不然重設完他還是進不去
  log_('窗口重設密碼', '', '', '', name, '打回預設 ' + CFG.DEFAULT_PASSWORD + '，並解除登入鎖定');
  return { ok: true, msg: name + ' 的密碼已重設為 ' + CFG.DEFAULT_PASSWORD + '，登入鎖定也一併解除' };
}

/** 窗口檢視：誰還在用預設密碼 */
function adminPwStatus(payload) {
  var deny = requireAdmin_(payload, 'resetpw'); if (deny) return deny;
  return { ok: true, rows: rosterNames_().map(function (n) {
    return { name: n, isDefault: isDefaultPw_(n), lockedMin: lockedOutMin_(n) }; }) };
}

/* ============================ 診斷 ============================ */

/**
 * 出問題時用這個。編輯器上方函式下拉選「diagnose」→ 執行 → 看「執行記錄」。
 * 會把腳本實際看到的東西全部印出來，一次分辨是權限、ID、還是分頁名稱的問題。
 */
function diagnose() {
  var out = [];
  try { out.push('執行身分：' + Session.getEffectiveUser().getEmail()); }
  catch (e) { out.push('執行身分：(取不到，不影響)'); }
  out.push('設定的試算表 ID：' + (CFG.SPREADSHEET_ID || '(空白 → 會改用綁定的試算表)'));
  try {
    var ss = ss_();
    out.push('✓ 開得到試算表：' + ss.getName());
    out.push('  網址：' + ss.getUrl());
    out.push('  時區：' + ss.getSpreadsheetTimeZone());

    var names = ss.getSheets().map(function (s) { return s.getName(); });
    out.push('全部分頁（' + names.length + '）：');
    names.forEach(function (n) {
      out.push('  「' + n + '」長度 ' + n.length +
        (isMonthSheet_(n) ? '　→ ✓ 認得，是月份分頁' : '　→ 略過（不符 115/10 或 11510 格式）'));
    });

    var months = listMonths_();
    out.push('認得的月份分頁（' + months.length + '）：' + (months.join('、') || '（一個都沒有！）'));

    if (months.length) {
      var p = parseSheet_(months[0]);
      out.push('試解析 ' + months[0] + '：日期 ' + p.days.length + ' 天、可排班 ' +
        p.days.filter(function (d) { return d.open; }).length + ' 天、順位 ' + p.order.length + ' 人');
      out.push('  順位前三：' + p.order.slice(0, 3).map(function (o) {
        return o.seq + o.name + (o.done ? '(已完成)' : ''); }).join('、'));
    }
  } catch (e) {
    out.push('★★ 發生錯誤：' + e.message);
    out.push('   （若是權限或找不到試算表，請確認執行身分的帳號對這份表有編輯權）');
  }
  var txt = out.join('\n');
  Logger.log(txt);
  return txt;
}

/* ============================ 月份清單 ============================ */

/**
 * 月份分頁的名稱，兩種寫法都認：
 *   「115/10」「115/9」 ← 實際試算表用的格式
 *   「11510」          ← 匯出成 xlsx 時會被自動改成這樣（Excel 不允許分頁名含「/」）
 */
function isMonthSheet_(n) {
  return /^\d{3}\/\d{1,2}$/.test(n) || /^\d{5}$/.test(n);
}

/** 取出所有月份分頁名稱，新到舊排序 */
function listMonths_() {
  return ss_().getSheets()
    .map(function (s) { return s.getName(); })
    .filter(isMonthSheet_)
    .sort(function (a, b) { return decodeMonth_(b).key - decodeMonth_(a).key; });
}

/** 「115/10」或「11510」→ {roc:115, y:2026, m:10, key:11510, label:'115年10月'} */
function decodeMonth_(name) {
  var roc, m;
  if (name.indexOf('/') >= 0) {
    var parts = name.split('/');
    roc = parseInt(parts[0], 10); m = parseInt(parts[1], 10);
  } else {
    roc = parseInt(name.substring(0, 3), 10); m = parseInt(name.substring(3), 10);
  }
  return { roc: roc, y: roc + 1911, m: m, key: roc * 100 + m, label: roc + '年' + m + '月' };
}

/** 建立次月分頁時，沿用現有分頁的命名格式（有沒有斜線、月份要不要補零） */
function encodeMonth_(roc, m, like) {
  if (!like || like.indexOf('/') < 0) return String(roc) + ('0' + m).slice(-2);
  var pad = true;
  listMonths_().forEach(function (n) { if (/\/\d$/.test(n)) pad = false; });
  return roc + '/' + (pad ? ('0' + m).slice(-2) : String(m));
}

/* ============================ 分頁解析 ============================ */

/**
 * 掃描分頁，回傳結構化資料。
 * 不寫死列號，靠「B~H 出現日期物件」判斷週起始列，因此舊分頁（標題在第 1 列）
 * 與新分頁（標題在第 2 列）都能吃。
 */
function parseSheet_(name) {
  var sh = ss_().getSheetByName(name);
  if (!sh) throw new Error('找不到分頁：' + name);
  var vals = sh.getDataRange().getValues();
  var info = decodeMonth_(name);

  var slots = {};   // 'yyyy-MM-dd#am' → {row, col, value}
  var days = [];    // 依日期序
  var seen = {};

  for (var r = 0; r < vals.length; r++) {
    var hasDate = false;
    for (var c = 1; c <= 7; c++) { if (vals[r][c] instanceof Date) { hasDate = true; break; } }
    if (!hasDate) continue;
    if (r + 2 >= vals.length) break;

    for (var c2 = 1; c2 <= 7; c2++) {
      var dv = vals[r][c2];
      if (!(dv instanceof Date)) continue;
      var key = iso_(dv);
      if (seen[key]) continue;
      seen[key] = true;

      var amRaw = String(vals[r + 1][c2] == null ? '' : vals[r + 1][c2]).trim();
      var pmRaw = String(vals[r + 2][c2] == null ? '' : vals[r + 2][c2]).trim();
      var closed = (amRaw.toUpperCase() === 'X' && pmRaw.toUpperCase() === 'X');

      slots[key + '#am'] = { row: r + 2, col: c2 + 1, value: amRaw };  // 轉 1-based
      slots[key + '#pm'] = { row: r + 3, col: c2 + 1, value: pmRaw };

      days.push({
        iso: key,
        wd: dv.getDay(),
        dayNum: dv.getDate(),
        // 只比對月份、不比對年份：11502~11504 三個舊分頁的儲存格年份是 2025（顯示格式只有
        // 月/日 所以看不出來），若連年份一起比對，那幾個月的月曆會整片變成空白。
        inMonth: (dv.getMonth() + 1 === info.m),
        open: !closed,
        am: amRaw.toUpperCase() === 'X' ? null : amRaw,
        pm: pmRaw.toUpperCase() === 'X' ? null : pmRaw,
        amClosed: amRaw.toUpperCase() === 'X',
        pmClosed: pmRaw.toUpperCase() === 'X'
      });
    }
    r += 2;
  }
  days.sort(function (a, b) { return a.iso < b.iso ? -1 : 1; });

  /* ---- 右側順位面板 ---- */
  var order = [], orderCell = null;
  for (var rr = 0; rr < vals.length && !orderCell; rr++) {
    for (var cc = 8; cc < Math.min(vals[rr].length, 20); cc++) {
      if (String(vals[rr][cc]).trim() === '排序') { orderCell = { r: rr, c: cc }; break; }
    }
  }
  if (orderCell) {
    for (var k = orderCell.r + 1; k < vals.length; k++) {
      var seq = String(vals[k][orderCell.c] == null ? '' : vals[k][orderCell.c]).trim();
      var nm = String(vals[k][orderCell.c + 1] == null ? '' : vals[k][orderCell.c + 1]).trim();
      if (!seq && !nm) break;
      var doneRaw = vals[k][orderCell.c + 2];
      order.push({
        seq: seq,
        name: nm,
        done: (doneRaw === true || String(doneRaw).toUpperCase() === 'TRUE'),
        row: k + 1,
        doneCol: orderCell.c + 3
      });
    }
  }

  /* ---- 注意事項 ---- */
  var notes = '', notesCell = null;
  for (var nr = 0; nr < Math.min(vals.length, 6) && !notesCell; nr++) {
    for (var nc = 8; nc < Math.min(vals[nr].length, 25); nc++) {
      if (String(vals[nr][nc]).indexOf('當月班表填寫注意事項') === 0) { notesCell = { r: nr, c: nc }; break; }
    }
  }
  if (notesCell) {
    var buf = [];
    for (var q = notesCell.r + 1; q < Math.min(vals.length, notesCell.r + 12); q++) {
      var t = String(vals[q][notesCell.c] == null ? '' : vals[q][notesCell.c]).trim();
      if (t) buf.push(t);
    }
    notes = buf.join('\n');
  }

  return {
    name: name, info: info, sheet: sh, days: days, slots: slots,
    order: order, notes: notes,
    notesRow: notesCell ? notesCell.r + 2 : 0,
    notesCol: notesCell ? notesCell.c + 1 : 0
  };
}

/** 找出「連續 4 個以上可排班日」的區間 → 長連假 */
function longHolidays_(days) {
  var open = days.filter(function (d) { return d.open; }).map(function (d) { return d.iso; });
  var out = {}, run = [];
  function flush() {
    if (run.length >= CFG.LONG_HOLIDAY_MIN) run.forEach(function (x) { out[x] = run.length; });
    run = [];
  }
  for (var i = 0; i < open.length; i++) {
    if (!run.length) { run.push(open[i]); continue; }
    var prev = new Date(run[run.length - 1] + 'T00:00:00');
    var cur = new Date(open[i] + 'T00:00:00');
    if ((cur - prev) === 86400000) run.push(open[i]); else { flush(); run.push(open[i]); }
  }
  flush();
  return out;
}

/* ============================ 對前端的 API ============================ */

function getBootstrap() {
  var months = listMonths_();
  var out = {
    months: months.map(function (n) { return { name: n, label: decodeMonth_(n).label }; }),
    today: todayIso_(),
    rules: {
      maxShifts: CFG.MAX_SHIFTS_PER_MONTH,
      maxConsecutive: CFG.MAX_CONSECUTIVE_DAYS,
      deadlineDay: CFG.DEADLINE_DAY,
      openDay: CFG.OPEN_DAY,
      maxFail: CFG.LOGIN_MAX_FAIL,
      lockMin: CFG.LOGIN_LOCK_MIN
    },
    // 畫面上所有組織專屬的文字（頁首、規則、頁尾、聯絡方式）都從 Config.gs 帶給前端
    ui: CFG.UI
  };
  // 預設顯示「次月」：每月 5 號公布次月班表，打開網頁十之八九就是要看次月，少一次點選
  var t = todayIso_();
  var dRoc = parseInt(t.substring(0, 4), 10) - 1911;
  var dMon = parseInt(t.substring(5, 7), 10) + 1;
  if (dMon > 12) { dMon = 1; dRoc += 1; }
  var wantKey = dRoc * 100 + dMon, pick = '';
  months.forEach(function (n) { if (!pick && decodeMonth_(n).key === wantKey) pick = n; });
  out.defaultMonth = pick || (months.length ? months[0] : '');

  out.lock = lockState_();
  out.currentMonth = currentMonthSheet_();
  // 鎖定期間一律先開當月：管理者也一樣，只是他還能自己切到別的月份
  if (out.lock.locked && out.currentMonth) out.defaultMonth = out.currentMonth;

  // 登入畫面的下拉：只送「代號＋遮罩姓名」，完整姓名不出現在未登入的頁面上
  out.roster = rosterList_().map(function (r) {
    // 會務人員帳號的名稱本來就不是姓名，不必遮
    var nm = isReadOnly_(r.name) ? r.name : maskName_(r.name);
    return { seq: r.seq, label: fwSeq_(r.seq) + '．' + nm };
  });

  // 一個月份分頁都認不出來時，把實際看到的分頁名稱一起帶回去，讓錯誤畫面自己講清楚
  if (!months.length) {
    out.ssName = ss_().getName();
    out.allSheets = ss_().getSheets().map(function (s) { return s.getName() + '（' + s.getName().length + '字）'; });
  }
  return out;
}

function getMonth(payload) {
  var who = actorOf_(payload || {});
  if (!who) return { needLogin: true };
  // 鎖定＝不能改，但每個月份都還是可以查閱（可能有人要看下個月或核對）
  var out = getMonthData_(payload.month);
  out.lock = lockState_();
  out.forcedMonth = false;
  out.viewer = { name: who, readOnly: isReadOnly_(who), isDefaultPw: isDefaultPw_(who),
                 defaultPw: CFG.DEFAULT_PASSWORD,
                 perms: permsOf_(who), isSuper: isAdminUser_(who), canAdmin: anyPerm_(who) };
  return out;
}

/** 內部用：不檢查身分，給已驗證過的呼叫端使用 */
function getMonthData_(name) {
  var p = parseSheet_(name);
  var lh = longHolidays_(p.days);

  // 前三個月的排班次數（供「4 天以上連假優先給前三個月排最多者」判斷）
  // 這段要多讀三個分頁，是 getMonth 最慢的地方，快取 5 分鐘。
  var all = listMonths_().slice().reverse();   // 舊 → 新
  var idx = all.indexOf(name);
  var prevs = idx > 0 ? all.slice(Math.max(0, idx - 3), idx) : [];
  var prevCounts = null, ck = 'pc_' + name, cache = null;
  try { cache = CacheService.getScriptCache(); } catch (e) { cache = null; }
  if (cache) {
    var hit = cache.get(ck);
    if (hit) { try { prevCounts = JSON.parse(hit); } catch (e) { prevCounts = null; } }
  }
  if (!prevCounts) {
    prevCounts = {};
    prevs.forEach(function (mn) {
      try {
        var pp = parseSheet_(mn);
        pp.days.forEach(function (d) {
          [d.am, d.pm].forEach(function (nm) {
            if (nm) prevCounts[nm] = (prevCounts[nm] || 0) + 1;
          });
        });
      } catch (e) { /* 分頁格式異常就略過 */ }
    });
    if (cache) { try { cache.put(ck, JSON.stringify(prevCounts), 300); } catch (e) {} }
  }

  return {
    name: name,
    label: p.info.label,
    year: p.info.y,
    month: p.info.m,
    days: p.days.map(function (d) {
      d.longHoliday = !!lh[d.iso];
      return d;
    }),
    order: p.order.map(function (o) { return { seq: o.seq, name: o.name, done: o.done }; }),
    notes: p.notes,
    prevMonths: prevs.map(function (mn) { return decodeMonth_(mn).m + '月'; }),
    prevCounts: prevCounts,
    today: todayIso_()
  };
}

/**
 * 填班。唯一「硬擋」的情況是格子已被別人佔走或該日不開放（資料完整性），
 * 其餘規則一律由前端提示、使用者確認後仍可送出（軟提示模式）。
 */
function submitShift(payload) {
  var actor = actorOf_(payload);
  if (!actor) return { ok: false, needLogin: true, msg: '登入已過期，請重新登入' };
  if (isReadOnly_(actor)) return { ok: false, msg: '此帳號不參與排班，不能填寫或修改班表' };
  var lk = lockBlocks_(actor); if (lk) return lk;
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var p = parseSheet_(payload.month);
    var key = payload.iso + '#' + payload.shift;
    var slot = p.slots[key];
    if (!slot) return { ok: false, msg: '找不到這一格（' + payload.iso + ' ' + payload.shift + '）' };
    if (String(slot.value).toUpperCase() === 'X') return { ok: false, msg: '這一天不開放排班' };
    if (slot.value) return { ok: false, msg: '慢了一步：這一格已由「' + slot.value + '」填走，請重新整理' };

    p.sheet.getRange(slot.row, slot.col).setValue(payload.name);
    // 允許代壓班（排班規則本來就有），但把實際操作者記下來
    var note = (payload.warnings || '');
    if (actor !== payload.name) note = ('代填（操作者 ' + actor + '）　' + note).trim();
    log_('填班', payload.month, payload.iso, payload.shift, payload.name, note);
    return { ok: true, data: getMonthData_(payload.month) };
  } finally {
    lock.releaseLock();
  }
}

/** 取消填班：只能取消自己的（窗口管理密碼可取消任何人的） */
function cancelShift(payload) {
  var actor = actorOf_(payload);
  if (!actor) return { ok: false, needLogin: true, msg: '登入已過期，請重新登入' };
  if (isReadOnly_(actor)) return { ok: false, msg: '此帳號不參與排班，不能填寫或修改班表' };
  var lk = lockBlocks_(actor); if (lk) return lk;
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var p = parseSheet_(payload.month);
    var slot = p.slots[payload.iso + '#' + payload.shift];
    if (!slot || !slot.value) return { ok: false, msg: '這一格本來就是空的' };
    var isAdmin = can_(actor, 'manage');
    if (slot.value !== actor && !isAdmin) {
      return { ok: false, msg: '這是「' + slot.value + '」的班，只有本人或' + CFG.UI.contactShort + '能取消' };
    }
    p.sheet.getRange(slot.row, slot.col).setValue('');
    log_(isAdmin ? '窗口取消' : '取消', payload.month, payload.iso, payload.shift, slot.value, '');
    return { ok: true, data: getMonthData_(payload.month) };
  } finally {
    lock.releaseLock();
  }
}

/** 勾／取消「完成註記」＝ 交棒給下一順位 */
function setDone(payload) {
  var actor = actorOf_(payload);
  if (!actor) return { ok: false, needLogin: true, msg: '登入已過期，請重新登入' };
  if (isReadOnly_(actor)) return { ok: false, msg: '此帳號不參與排班，不能填寫或修改班表' };
  var lk = lockBlocks_(actor); if (lk) return lk;
  if (!can_(actor, 'manage') && actor !== payload.name) {
    return { ok: false, msg: '只能替自己交棒；要代人交棒請用後臺管理' };
  }
  return setDone_(payload.month, payload.name, payload.done === true,
                  payload.done ? '完成填表' : '取消完成');
}

/** 內部用：實際改「完成註記」那一格（呼叫端已驗過身分） */
function setDone_(month, name, done, action) {
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var p = parseSheet_(month);
    var hit = null;
    p.order.forEach(function (o) { if (o.name === name) hit = o; });
    if (!hit) return { ok: false, msg: '順位表裡找不到「' + name + '」' };
    p.sheet.getRange(hit.row, hit.doneCol).setValue(done);
    log_(action, month, '', '', name, '');
    return { ok: true, data: getMonthData_(month) };
  } finally {
    lock.releaseLock();
  }
}

/* ============================ 窗口（管理者）功能 ============================ */

/**
 * 窗口在網頁上直接開關某一格班別（把 X 清掉＝開放、填 X＝不開放）。
 * 已經有人填班的格子不准關——要關必須先取消那一班，避免把人排好的班默默弄不見。
 */
function setSlotOpen(payload) {
  var deny = requireAdmin_(payload, 'openday'); if (deny) return deny;
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var p = parseSheet_(payload.month);
    var slot = p.slots[payload.iso + '#' + payload.shift];
    if (!slot) return { ok: false, msg: '找不到這一格（' + payload.iso + '）' };
    var cur = String(slot.value == null ? '' : slot.value).trim();
    var isX = cur.toUpperCase() === 'X';

    if (payload.open) {
      if (!isX) return { ok: false, msg: '這一格本來就是開放的' };
      p.sheet.getRange(slot.row, slot.col).setValue('');
    } else {
      if (cur && !isX) return { ok: false, msg: '這一格已由「' + cur + '」填班，請先取消那一班再關閉' };
      if (isX) return { ok: false, msg: '這一格本來就是不開放的' };
      p.sheet.getRange(slot.row, slot.col).setValue('X');
    }
    log_(payload.open ? '窗口開放班別' : '窗口關閉班別',
         payload.month, payload.iso, payload.shift, '窗口', '');
    return { ok: true, data: getMonthData_(payload.month) };
  } finally {
    lock.releaseLock();
  }
}

function saveNotes(payload) {
  var deny = requireAdmin_(payload, 'notes'); if (deny) return deny;
  var p = parseSheet_(payload.month);
  if (!p.notesRow) return { ok: false, msg: '這個分頁找不到「當月班表填寫注意事項：」標題' };
  var lines = String(payload.notes || '').split('\n');
  var rng = p.sheet.getRange(p.notesRow, p.notesCol, 10, 1);
  rng.clearContent();
  for (var i = 0; i < Math.min(lines.length, 10); i++) {
    p.sheet.getRange(p.notesRow + i, p.notesCol).setValue(lines[i]);
  }
  log_('修改注意事項', payload.month, '', '', '窗口', '');
  return { ok: true, data: getMonthData_(payload.month) };
}

/** 窗口代為切換某人的完成註記（處理逾期視同放棄） */
function adminSkip(payload) {
  var deny = requireAdmin_(payload, 'skip'); if (deny) return deny;
  return setDone_(payload.month, payload.name, true, '標記逾期放棄（' + actorOf_(payload) + '）');
}

/**
 * 建立次月分頁：
 *   1. 複製上一個月的分頁當版型
 *   2. 重新寫入日期（依「週日起」的整週格線）
 *   3. 依 _可排班日 設定寫 X／留空
 *   4. 順位整體左移一位（第一位移到最後）
 */
function createNextMonth(payload) {
  var deny = requireAdmin_(payload, 'create'); if (deny) return deny;
  var all = listMonths_();                    // 新 → 舊
  var src = payload.from || all[0];
  var si = decodeMonth_(src);
  var m = si.m + 1, roc = si.roc;
  if (m > 12) { m = 1; roc += 1; }
  var target = encodeMonth_(roc, m, src);
  if (ss_().getSheetByName(target)) return { ok: false, msg: '分頁 ' + target + ' 已經存在' };

  var sp = parseSheet_(src);
  var nsh = sp.sheet.copyTo(ss_()).setName(target);
  ss_().setActiveSheet(nsh);
  ss_().moveActiveSheet(sp.sheet.getIndex() + 1);   // 排在來源分頁後面，維持既有分頁順序

  var np = parseSheet_(target);
  var openSet = openDaySet_();

  // 找出目標月第一天所在週的週日
  var first = new Date(roc + 1911, m - 1, 1);
  var start = new Date(first.getTime() - first.getDay() * 86400000);

  // 依原版型的「週起始列」順序重寫；版型週數不足時依 3 列間距往下補
  var weekRows = [];
  Object.keys(np.slots).forEach(function (k) {
    if (k.indexOf('#am') < 0) return;
    weekRows.push(np.slots[k].row);
  });
  weekRows = weekRows.filter(function (v, i, a) { return a.indexOf(v) === i; })
    .sort(function (a, b) { return a - b; });
  if (!weekRows.length) return { ok: false, msg: '來源分頁 ' + src + ' 讀不到日期列，無法當版型' };

  var lastDay = new Date(roc + 1911, m, 0);
  var needWeeks = Math.ceil((first.getDay() + lastDay.getDate()) / 7);
  while (weekRows.length < needWeeks) weekRows.push(weekRows[weekRows.length - 1] + 3);

  // 日期／早班／晚班剛好是連續三列，整週一次寫入（逐格 setValue 會慢到爆）
  var cur = new Date(start.getTime());
  for (var w = 0; w < weekRows.length; w++) {
    var amRow = weekRows[w], dateRow = amRow - 1;
    var weekTouches = weekTouchesMonth_(start, w, m, roc + 1911);
    var rowD = [], rowA = [], rowP = [];
    for (var c = 0; c < 7; c++) {
      if (!weekTouches) {
        rowD.push(''); rowA.push(''); rowP.push('');
      } else {
        var inRange = (cur.getMonth() + 1 === m && cur.getFullYear() === roc + 1911);
        // 只有週日自動開放；其餘（含週六與國定假日）一律 X，要開放請在 _可排班日 標「是」
        var open = inRange && (cur.getDay() === 0 || openSet[iso_(cur)]);
        rowD.push(new Date(cur.getTime()));
        rowA.push(open ? '' : 'X');
        rowP.push(open ? '' : 'X');
      }
      cur = new Date(cur.getTime() + 86400000);
    }
    np.sheet.getRange(dateRow, 2, 3, 7).setValues([rowD, rowA, rowP]);
  }

  // 順位輪替：整體左移一位，完成註記全部歸零；同樣一次寫入
  var rotated = sp.order.slice(1).concat(sp.order.slice(0, 1));
  if (np.order.length) {
    var oRows = [];
    for (var i = 0; i < np.order.length; i++) {
      oRows.push(i < rotated.length ? [rotated[i].seq, rotated[i].name, false]
                                    : [np.order[i].seq, np.order[i].name, false]);
    }
    np.sheet.getRange(np.order[0].row, np.order[0].doneCol - 2, oRows.length, 3).setValues(oRows);
  }

  // 清空注意事項
  if (np.notesRow) np.sheet.getRange(np.notesRow, np.notesCol, 10, 1).clearContent();

  log_('建立次月分頁', target, '', '', '窗口', '來源 ' + src);
  return { ok: true, month: target, data: payload.lean ? null : getMonth(target) };
}

/**
 * 一路建到指定月份（民國年 roc、月 month）。
 * 從目前最新的月份開始逐月建立，每建一個月順位就往前遞補一位。
 * 目標月份已存在就什麼都不做。上限 24 個月，避免打錯數字時失控。
 */
function createMonthsThrough(payload) {
  var deny = requireAdmin_(payload, 'create'); if (deny) return deny;
  var roc = Number(payload.roc), mon = Number(payload.month);
  if (!(roc > 100 && roc < 300) || !(mon >= 1 && mon <= 12)) {
    return { ok: false, msg: '請填正確的民國年與月份（例如 116 年 6 月）' };
  }
  var targetKey = roc * 100 + mon, made = [];
  for (var i = 0; i < 24; i++) {
    var all = listMonths_();
    if (!all.length) return { ok: false, msg: '試算表裡沒有任何月份分頁可以當版型' };
    if (decodeMonth_(all[0]).key >= targetKey) break;
    var r = createNextMonth({ token: payload.token, lean: true });
    if (!r.ok) {
      return { ok: false, made: made,
               msg: '建到第 ' + made.length + ' 個就停住了（' + (made.join('、') || '無') + '）：' + r.msg };
    }
    made.push(r.month);
  }
  log_('連續建立分頁', made.join('、'), '', '', '窗口', '目標 ' + roc + '/' + mon);
  return { ok: true, made: made,
           msg: made.length ? '已建立 ' + made.length + ' 個分頁：' + made.join('、')
                            : '目標月份已經存在，沒有需要新增的' };
}

function weekTouchesMonth_(start, weekIdx, m, y) {
  for (var i = 0; i < 7; i++) {
    var d = new Date(start.getTime() + (weekIdx * 7 + i) * 86400000);
    if (d.getMonth() + 1 === m && d.getFullYear() === y) return true;
  }
  return false;
}

/* ============================ 可排班日設定 ============================ */

/**
 * `_可排班日` 分頁：A 欄日期、B 欄「開放」(是/否)、C 欄說明。
 *
 * ⚠️ 設計原則：**國定假日一律預設「否」**。
 *    UCC 哪一天開診不是由行事曆推得出來的（實際班表裡 9/26 週六開、10/24 週六不開、
 *    9/25 中秋節不開），決定權在醫院與公會窗口。程式不替任何人猜——
 *    週日自動視為可排班，其餘一律要窗口在這張表把「開放」改成「是」才會開。
 *
 * 首次執行會自動建立此分頁並帶入 Config.gs 的 HOLIDAYS 清單當候選，全部預設「否」。
 */
function openDaySet_() {
  var sh = ss_().getSheetByName('_可排班日');
  if (!sh) sh = initOpenDaySheet_();
  else upgradeOpenDaySheet_(sh);
  var vals = sh.getDataRange().getValues();
  var out = {};
  for (var i = 1; i < vals.length; i++) {
    var v = vals[i][0];
    if (!v) continue;
    var flag = String(vals[i][1] == null ? '' : vals[i][1]).trim();
    if (flag !== '是' && flag.toUpperCase() !== 'TRUE' && flag !== 'Y') continue;
    out[(v instanceof Date) ? iso_(v) : String(v).trim()] = true;
  }
  return out;
}

/**
 * 舊版的 `_可排班日` 只有「日期／註記」兩欄，沒有「開放」欄。
 * 遇到舊格式就插一欄並全部填「否」——維持「程式不自動開放任何國定假日」的原則，
 * 要開哪天由窗口自己改成「是」。已是新格式就什麼都不做。
 */
function upgradeOpenDaySheet_(sh) {
  var hdr = String(sh.getRange(1, 2).getValue() == null ? '' : sh.getRange(1, 2).getValue()).trim();
  if (hdr.indexOf('開放') === 0) return;          // 已經是新格式
  sh.insertColumnBefore(2);
  sh.getRange(1, 2).setValue('開放(是/否)');
  var n = sh.getLastRow() - 1;
  if (n > 0) sh.getRange(2, 2, n, 1).setValue('否');
  sh.getRange(1, 3).setValue('說明　※週日不必列，系統自動視為可排班；此表只管「週日以外」要不要開');
  sh.setColumnWidth(2, 100);
  sh.getRange(1, 1, 1, 3).setFontWeight('bold');
  log_('升級_可排班日欄位', '', '', '', '系統', '舊兩欄格式 → 三欄，開放欄全部預設否');
}

function initOpenDaySheet_() {
  var sh = ss_().insertSheet('_可排班日');
  var rows = [['日期(yyyy-MM-dd)', '開放(是/否)',
    '說明　※週日不必列，系統自動視為可排班；此表只管「週日以外」要不要開']];
  (HOLIDAYS || []).forEach(function (r) { rows.push([r[0], '否', r[1]]); });
  sh.getRange(1, 1, rows.length, 3).setValues(rows);
  sh.setColumnWidth(1, 150); sh.setColumnWidth(2, 100); sh.setColumnWidth(3, 300);
  sh.getRange(1, 1, 1, 3).setFontWeight('bold');
  sh.setFrozenRows(1);
  sh.hideSheet();
  return sh;
}



/* ============================ 增減人員 ============================ */
/*
 * 在網頁上把人加進／移出順位，不必再開試算表。
 * 作用範圍＝「今天所在月份」起、所有已建立的月份分頁；歷史月份不動。
 * ⚠️ 順位面板跟月曆在同一列，絕不能 deleteRow——移除一律「重寫面板區塊＋清掉最後一列」。
 */
function affectedMonths_() {
  var t = todayIso_();
  var key = (parseInt(t.substring(0, 4), 10) - 1911) * 100 + parseInt(t.substring(5, 7), 10);
  return listMonths_().filter(function (n) { return decodeMonth_(n).key >= key; });
}

function rosterAdd(payload) {
  var deny = requireAdmin_(payload, 'roster'); if (deny) return deny;
  var name = String(payload.name || '').trim();
  if (!name) return { ok: false, msg: '請輸入姓名' };
  if (name.length > 12) return { ok: false, msg: '姓名太長了' };
  if (rosterNames_().indexOf(name) >= 0) return { ok: false, msg: '名單裡已經有「' + name + '」' };
  var months = affectedMonths_();
  if (!months.length) return { ok: false, msg: '沒有本月或之後的月份分頁，請先建立分頁' };

  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    // 代號：從 A 往後找第一個沒人用的（順位表與會務人員帳號都算用掉）
    var used = {};
    rosterList_().forEach(function (r) { used[String(r.seq).toUpperCase()] = true; });
    var seq = '';
    for (var c = 65; c <= 90 && !seq; c++) {
      var L = String.fromCharCode(c);
      if (!used[L]) seq = L;
    }
    if (!seq) return { ok: false, msg: '代號 A~Z 已經用完，請先移除不再排班的人' };

    var done = [], skipped = [];
    months.forEach(function (mn) {
      var p = parseSheet_(mn);
      if (p.order.some(function (o) { return o.name === name; })) return;
      if (!p.order.length) { skipped.push(mn); return; }
      var last = p.order[p.order.length - 1];
      var row = last.row + 1, col = last.doneCol - 2;
      p.sheet.getRange(row, col, 1, 3).setValues([[seq, name, false]]);
      try { p.sheet.getRange(row, col + 2).insertCheckboxes(); } catch (e) {}
      done.push(mn);
    });
    log_('新增人員', done.join('、'), '', '', name, '代號 ' + seq + '（操作者 ' + actorOf_(payload) + '）');
    return { ok: true, seq: seq,
             msg: '已將 ' + name + ' 加入順位最後（代號 ' + seq + '）：' + done.join('、') +
                  (skipped.length ? '；' + skipped.join('、') + ' 讀不到順位面板，略過' : '') };
  } finally {
    lock.releaseLock();
  }
}

function rosterRemove(payload) {
  var deny = requireAdmin_(payload, 'roster'); if (deny) return deny;
  var name = String(payload.name || '').trim();
  if (!name) return { ok: false, msg: '請選擇要移除的人' };
  if (isAdminUser_(name)) return { ok: false, msg: name + ' 是超級管理者，請先在 Config.gs 的 ADMIN_NAMES 拿掉' };
  if (isReadOnly_(name)) return { ok: false, msg: name + ' 是會務人員帳號，要拿掉請改 Config.gs 的 STAFF_ACCOUNTS' };
  var months = affectedMonths_();
  if (!months.length) return { ok: false, msg: '沒有本月或之後的月份分頁' };

  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    // 還有班的月份先擋下來——把人拿掉但班還掛著，班表會對不起來
    var busy = [];
    months.forEach(function (mn) {
      var p = parseSheet_(mn);
      if (p.days.some(function (d) { return d.am === name || d.pm === name; })) busy.push(p.info.label);
    });
    if (busy.length) return { ok: false, msg: name + ' 在 ' + busy.join('、') + ' 還有班，請先取消那些班再移除' };

    var done = [];
    months.forEach(function (mn) {
      var p = parseSheet_(mn);
      if (!p.order.some(function (o) { return o.name === name; })) return;
      var keep = p.order.filter(function (o) { return o.name !== name; });
      var first = p.order[0], col = first.doneCol - 2;
      var rows = keep.map(function (o) { return [o.seq, o.name, o.done]; });
      if (rows.length) p.sheet.getRange(first.row, col, rows.length, 3).setValues(rows);
      var tail = p.sheet.getRange(first.row + rows.length, col, 1, 3);
      tail.clearContent();
      try { tail.clearDataValidations(); } catch (e) {}
      done.push(mn);
    });
    props_().deleteProperty('pw_' + name);
    props_().deleteProperty('fail_' + name);
    props_().deleteProperty('perm_' + name);
    log_('移除人員', done.join('、'), '', '', name, '（操作者 ' + actorOf_(payload) + '）');
    return { ok: true, msg: '已將 ' + name + ' 移出順位：' + (done.join('、') || '（沒有分頁需要改）') +
                            '。歷史月份的紀錄不受影響' };
  } finally {
    lock.releaseLock();
  }
}

/* ============================ 第一次建置 ============================ */
/**
 * 從零建立試算表結構（給新導入的組織用；已有月份分頁時什麼都不做）。
 * 在 Apps Script 編輯器選這個函式 → 執行 → 看「執行記錄」。
 *
 *   - CFG.SPREADSHEET_ID 空白 → 自動建一份新試算表，把 ID 印在執行記錄，
 *     請填回 Config.gs 再執行一次。
 *   - 建「次月」的月份分頁，版面與 parseSheet_ 的假設一致：
 *       B~H 欄週日~週六、每週三列（日期／早班／晚班）、週日開放其餘 X、
 *       J~L 欄「排序／姓名／完成註記」順位面板（名單取 CFG.SETUP_ROSTER）、
 *       N2「當月班表填寫注意事項：」。
 *   - 一併建立隱藏的 _可排班日 與 _操作紀錄。
 */
function setupSpreadsheet() {
  var ss;
  if (!CFG.SPREADSHEET_ID) {
    ss = SpreadsheetApp.create(CFG.UI.orgShort + ' ' + CFG.UI.systemName);
    _SS = ss;
    Logger.log('已建立新試算表：' + ss.getUrl() + '\nID：' + ss.getId() +
               '\n→ 請把這個 ID 填進 Config.gs 的 SPREADSHEET_ID，儲存後再執行一次 setupSpreadsheet。');
    return;
  }
  ss = ss_();
  if (listMonths_().length) {
    Logger.log('試算表已有月份分頁（' + listMonths_().join('、') + '），不重複建立。');
    return;
  }

  var t = todayIso_();
  var roc = parseInt(t.substring(0, 4), 10) - 1911, m = parseInt(t.substring(5, 7), 10) + 1;
  if (m > 12) { m = 1; roc += 1; }
  var name = roc + '/' + ('0' + m).slice(-2);
  var sh = ss.insertSheet(name, 0);
  var UI = CFG.UI;

  // 第 2 列：星期標題、順位面板標題、注意事項標題
  sh.getRange(2, 2, 1, 7).setValues([['周日', '周一', '週二', '週三', '週四', '週五', '週六']])
    .setBackground('#ffd966');
  sh.getRange(2, 10, 1, 3).merge();
  sh.getRange(2, 10).setValue('本月排班順位').setBackground('#efefef');
  sh.getRange(3, 10, 1, 3).setValues([['排序', '姓名', '完成註記']]).setBackground('#efefef');
  sh.getRange(2, 14).setValue('當月班表填寫注意事項：');

  // 月曆：整週格線，週日開放、其餘依 _可排班日
  var openSet = openDaySet_();
  var first = new Date(roc + 1911, m - 1, 1);
  var start = new Date(first.getTime() - first.getDay() * 86400000);
  var lastDay = new Date(roc + 1911, m, 0);
  var weeks = Math.ceil((first.getDay() + lastDay.getDate()) / 7);
  var cur = new Date(start.getTime());
  for (var w = 0; w < weeks; w++) {
    var dateRow = 3 + w * 3, rowD = [], rowA = [], rowP = [];
    for (var c = 0; c < 7; c++) {
      var inRange = (cur.getMonth() + 1 === m);
      var open = inRange && (cur.getDay() === 0 || openSet[iso_(cur)]);
      rowD.push(new Date(cur.getTime()));
      rowA.push(open ? '' : 'X');
      rowP.push(open ? '' : 'X');
      cur = new Date(cur.getTime() + 86400000);
    }
    sh.getRange(dateRow, 2, 3, 7).setValues([rowD, rowA, rowP]);
    sh.getRange(dateRow, 2, 1, 7).setNumberFormat('m/d').setBackground('#cfe2f3');
    sh.getRange(dateRow + 1, 1).setValue(UI.shifts.am.label + '\n' + UI.shifts.am.hours);
    sh.getRange(dateRow + 2, 1).setValue(UI.shifts.pm.label + '\n' + UI.shifts.pm.hours);
  }

  // 順位面板
  var roster = CFG.SETUP_ROSTER || [];
  if (roster.length) {
    var rows = roster.map(function (n, i) { return [String.fromCharCode(65 + i), String(n), false]; });
    sh.getRange(4, 10, rows.length, 3).setValues(rows);
    try { sh.getRange(4, 12, rows.length, 1).insertCheckboxes(); } catch (e) {}
  }

  sh.getRange(1, 1, 3 + weeks * 3, 12).setHorizontalAlignment('center');
  [[1, 70], [9, 25], [10, 55], [11, 75], [12, 80], [13, 30], [14, 220]].forEach(function (cw) {
    sh.setColumnWidth(cw[0], cw[1]);
  });
  log_('建立初始分頁', name, '', '', '系統', 'setupSpreadsheet，順位 ' + roster.length + ' 人');
  Logger.log('已建立月份分頁 ' + name + '（順位 ' + roster.length + ' 人）。' +
             (roster.length ? '' : '\n順位面板是空的：請在 Config.gs 的 SETUP_ROSTER 填名單後刪掉這個分頁重跑，或直接在試算表 J4 起填「代號／姓名」。') +
             '\n接著部署成網頁應用程式即可。');
}

/* ============================ 紀錄 ============================ */

function log_(action, month, iso, shift, name, note) {
  try {
    var sh = ss_().getSheetByName(CFG.LOG_SHEET);
    if (!sh) {
      sh = ss_().insertSheet(CFG.LOG_SHEET);
      sh.appendRow(['時間', '動作', '月份', '日期', '班別', '姓名', '備註']);
      sh.hideSheet();
    }
    sh.appendRow([new Date(), action, month, iso,
      shift === 'am' ? CFG.UI.shifts.am.label : (shift === 'pm' ? CFG.UI.shifts.pm.label : ''), name, note]);
  } catch (e) { /* 紀錄失敗不影響主流程 */ }
}
