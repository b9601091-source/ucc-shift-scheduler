<?php
/* 快速登入（LINE Login／Google 帳號）
 * 模式＝「綁定制」：先用代號＋密碼登入，在「設定」把 LINE 或 Google 綁到自己的帳號，之後登入頁直接按一下就進來。
 *   - LINE：OAuth 2.0 授權碼流程（access.line.me 授權 → api.line.me 換 id_token → /oauth2/v2.1/verify 驗 sub）
 *   - Google：OpenID Connect 隱含流程（response_type=id_token、form_post 回 sso.php → tokeninfo 驗 aud／nonce／exp）
 *   - 兩者的回呼網址都是 <系統網址>sso.php（不帶參數），由伺服器端的 state 記錄知道是哪一家、是登入還是綁定
 * 金鑰在 lib/sso_keys.php（不進版本庫；用 php/set_sso_keys.ps1 產生）：沒有金鑰＝該登入方式不顯示。
 * 超級管理者（ADMIN_NAMES）只能用密碼登入，不能綁定。 */
if (!defined('UCC')) { http_response_code(404); exit; }

function ssoKeys_() {
  static $file = null;
  if ($file === null) { $f = __DIR__ . '/sso_keys.php'; $file = is_file($f) ? (array)(include $f) : []; }
  $k = (!empty($GLOBALS['UCC_SSO_KEYS']) ? (array)$GLOBALS['UCC_SSO_KEYS'] : []) + $file;   // 測試可用 $GLOBALS 覆蓋
  return $k + ['line_channel_id' => '', 'line_channel_secret' => '', 'google_client_id' => ''];
}
/** 已開通的登入方式 */
function ssoEnabled_() {
  $k = ssoKeys_(); $out = [];
  if ($k['line_channel_id'] !== '' && $k['line_channel_secret'] !== '') $out[] = 'line';
  if ($k['google_client_id'] !== '') $out[] = 'google';
  return $out;
}
function ssoLabel_($p) { return $p === 'line' ? 'LINE' : ($p === 'google' ? 'Google' : $p); }
/** 系統網址（含結尾斜線）；config.php 的 SSO_BASE_URL 有填就用它，否則依這次請求推算 */
function ssoBaseUrl_() {
  if (!empty(CFG()['SSO_BASE_URL'])) return rtrim(CFG()['SSO_BASE_URL'], '/') . '/';
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
  return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}
function ssoCallbackUrl_() { return ssoBaseUrl_() . 'sso.php'; }

/* ---- 一次性 state（存 _props，10 分鐘） ---- */
function ssoStateNew_($data) {
  $st = bin2hex(random_bytes(16));
  $data['exp'] = nowMs_() + 10 * 60000;
  setProp_('sso_' . $st, jenc_($data));
  ssoStateGc_();
  return $st;
}
function ssoStateTake_($st) {
  if (!preg_match('/^[0-9a-f]{32}$/', (string)$st)) return null;
  $d = jdec_(prop_('sso_' . $st));
  delProp_('sso_' . $st);
  if (!$d || (int)($d['exp'] ?? 0) < nowMs_()) return null;
  return $d;
}
function ssoStateGc_() {
  foreach (qa_("SELECT k, v FROM _props WHERE k LIKE 'sso_%'") as $r) {
    $d = jdec_($r['v']);
    if ((int)($d['exp'] ?? 0) < nowMs_()) delProp_($r['k']);
  }
}

/* ---- 對外連線（測試可用 $GLOBALS['UCC_SSO_HTTP'] 換成假的） ---- */
function ssoHttp_($url, $post = null) {
  if (!empty($GLOBALS['UCC_SSO_HTTP'])) return call_user_func($GLOBALS['UCC_SSO_HTTP'], $url, $post);
  if (!function_exists('curl_init')) throw new Exception('主機沒有 curl，無法連線到登入服務');
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'UCC-Scheduler/' . APP_VERSION]);
  if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, (string)$body];
}

function ssoAuthUrl_($p, $st, $nonce) {
  $k = ssoKeys_(); $cb = ssoCallbackUrl_();
  if ($p === 'line') {
    return 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $k['line_channel_id'],
      'redirect_uri' => $cb, 'state' => $st, 'scope' => 'profile openid', 'nonce' => $nonce]);
  }
  return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $k['google_client_id'], 'redirect_uri' => $cb,
    'response_type' => 'id_token', 'response_mode' => 'form_post', 'scope' => 'openid', 'state' => $st, 'nonce' => $nonce, 'prompt' => 'select_account']);
}
/** 向提供者驗證身分 → ['sub'=>識別碼, 'name'=>顯示名稱]；失敗 throw */
function ssoIdentity_($p, $req, $state) {
  $k = ssoKeys_();
  if ($p === 'line') {
    $code = str_($req['code'] ?? '');
    if ($code === '') throw new Exception('LINE 沒有回傳授權碼');
    [$c, $b] = ssoHttp_('https://api.line.me/oauth2/v2.1/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => ssoCallbackUrl_(),
      'client_id' => $k['line_channel_id'], 'client_secret' => $k['line_channel_secret']]);
    $j = json_decode($b, true);
    if ($c !== 200 || empty($j['id_token'])) throw new Exception('LINE 換取憑證失敗（HTTP ' . $c . '）');
    [$c2, $b2] = ssoHttp_('https://api.line.me/oauth2/v2.1/verify', ['id_token' => $j['id_token'], 'client_id' => $k['line_channel_id'], 'nonce' => (string)($state['nonce'] ?? '')]);
    $v = json_decode($b2, true);
    if ($c2 !== 200 || empty($v['sub'])) throw new Exception('LINE 身分驗證失敗（HTTP ' . $c2 . '）');
    return ['sub' => (string)$v['sub'], 'name' => (string)($v['name'] ?? '')];
  }
  if ($p === 'google') {
    $idt = str_($req['id_token'] ?? '');
    if ($idt === '') throw new Exception('Google 沒有回傳憑證');
    [$c, $b] = ssoHttp_('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idt));
    $v = json_decode($b, true);
    if ($c !== 200 || empty($v['sub'])) throw new Exception('Google 身分驗證失敗（HTTP ' . $c . '）');
    if (($v['aud'] ?? '') !== $k['google_client_id']) throw new Exception('Google 憑證不是給這個系統的');
    if (($v['nonce'] ?? '') !== (string)($state['nonce'] ?? '')) throw new Exception('Google 憑證驗證碼不符');
    if ((int)($v['exp'] ?? 0) < (int)(nowMs_() / 1000)) throw new Exception('Google 憑證已過期');
    return ['sub' => (string)$v['sub'], 'name' => (string)($v['name'] ?? ($v['email'] ?? ''))];
  }
  throw new Exception('不支援的登入方式');
}

/* ---- 綁定資料 ---- */
function ssoBindingsOf_($name) { return qa_('SELECT provider, sub, label, bound FROM sso WHERE name = ? ORDER BY provider', [$name]); }
function ssoFindUser_($p, $sub) { $r = q1_('SELECT name FROM sso WHERE provider = ? AND sub = ?', [$p, $sub]); return $r ? (string)$r['name'] : ''; }
function ssoBind_($p, $sub, $name, $label) {
  x_('DELETE FROM sso WHERE provider = ? AND (sub = ? OR name = ?)', [$p, $sub, $name]);   // 一人一家只綁一個帳號、一個帳號只能屬於一人
  x_('INSERT INTO sso (provider, sub, name, label, bound) VALUES (?,?,?,?,?)', [$p, $sub, $name, ucut_($label, 40), nowStr_()]);
}
/** 誰可以用快速登入：名單裡的人，但超級管理者除外（管理帳號一律密碼） */
function ssoAllowed_($name) { return $name !== '' && in_array($name, rosterNames_(), true) && !isAdminUser_($name); }

/* ---- sso.php 用的兩個流程，回傳要導向的網址 ---- */
function ssoHandleStart_($p, $go) {
  $base = ssoBaseUrl_();
  if ($go !== '') {                                    // 綁定：拿 apiSsoBindTicket 發的一次性票
    $d = ssoStateTake_($go);
    if (!$d || ($d['mode'] ?? '') !== 'bind') return $base . '?sso=error&m=expired';
    $p = (string)$d['p'];
    if (!in_array($p, ssoEnabled_(), true)) return $base . '?sso=error&m=off';
    $nonce = bin2hex(random_bytes(12));
    $st = ssoStateNew_(['p' => $p, 'mode' => 'bind', 'name' => (string)$d['name'], 'nonce' => $nonce]);
    return ssoAuthUrl_($p, $st, $nonce);
  }
  if (!in_array($p, ssoEnabled_(), true)) return $base . '?sso=error&m=off';
  $nonce = bin2hex(random_bytes(12));
  $st = ssoStateNew_(['p' => $p, 'mode' => 'login', 'nonce' => $nonce]);
  return ssoAuthUrl_($p, $st, $nonce);
}
function ssoHandleCallback_($req) {
  $base = ssoBaseUrl_();
  $d = ssoStateTake_(str_($req['state'] ?? ''));
  if (!$d) return $base . '?sso=error&m=expired';
  $p = (string)($d['p'] ?? '');
  if (!empty($req['error'])) return $base . '?sso=cancel&p=' . $p;
  try { $id = ssoIdentity_($p, $req, $d); }
  catch (Exception $e) { log_('快速登入失敗', '', '', '', $p, $e->getMessage()); return $base . '?sso=error&m=verify&p=' . $p; }
  if (($d['mode'] ?? '') === 'bind') {
    $name = (string)($d['name'] ?? '');
    if (!ssoAllowed_($name)) return $base . '?sso=error&m=denied&p=' . $p;
    $other = ssoFindUser_($p, $id['sub']);
    if ($other !== '' && $other !== $name) return $base . '?sso=error&m=taken&p=' . $p;
    withLock_(function () use ($p, $id, $name) { ssoBind_($p, $id['sub'], $name, $id['name']); });
    log_('綁定快速登入', '', '', '', $name, ssoLabel_($p));
    return $base . '?sso=bound&p=' . $p;
  }
  $name = ssoFindUser_($p, $id['sub']);
  if ($name === '' || !ssoAllowed_($name)) return $base . '?sso=unbound&p=' . $p;
  $wait = lockedOutMin_($name);
  if ($wait > 0) return $base . '?sso=error&m=locked&p=' . $p;
  log_('登入', '', '', '', $name, '快速登入 ' . ssoLabel_($p));
  // token 放在 # 後面：不會進伺服器紀錄，前端讀完立刻從網址拿掉
  return $base . '#sso_tok=' . rawurlencode(makeToken_($name, (int)(CFG()['SSO_TOKEN_DAYS'] ?? 7))) . '&p=' . $p;
}

/* ================= API ================= */
function apiSsoList($p = []) {
  $n = actorOf_($p);
  if (!$n) return needLogin_();
  return ['ok' => true, 'allowed' => ssoAllowed_($n), 'providers' => ssoEnabled_(),
    'bound' => array_map(function ($r) { return ['provider' => $r['provider'], 'label' => (string)$r['label'], 'bound' => (string)$r['bound']]; }, ssoBindingsOf_($n))];
}
/** 綁定前先拿一次性票，前端再導到 sso.php?go=票（登入 token 不經過網址） */
function apiSsoBindTicket($p = []) {
  $n = actorOf_($p);
  if (!$n) return needLogin_();
  $prov = str_($p['provider'] ?? '');
  if (!in_array($prov, ssoEnabled_(), true)) return ['ok' => false, 'msg' => '這個登入方式尚未開通'];
  if (!ssoAllowed_($n)) return ['ok' => false, 'msg' => '超級管理者帳號只能用密碼登入，不開放綁定'];
  $st = ssoStateNew_(['p' => $prov, 'mode' => 'bind', 'name' => $n, 'nonce' => '']);
  return ['ok' => true, 'url' => 'sso.php?go=' . $st];
}
function apiSsoUnbind($p = []) {
  $n = actorOf_($p);
  if (!$n) return needLogin_();
  $prov = str_($p['provider'] ?? '');
  if (!in_array($prov, ['line', 'google'], true)) return ['ok' => false, 'msg' => '參數不對'];
  x_('DELETE FROM sso WHERE provider = ? AND name = ?', [$prov, $n]);
  log_('解除快速登入', '', '', '', $n, ssoLabel_($prov));
  return ['ok' => true, 'msg' => '已解除 ' . ssoLabel_($prov) . ' 登入綁定'];
}
function apiAdminSsoUnbind($p = []) {
  if ($d = requireAdmin_($p, 'resetpw')) return $d;
  $name = str_($p['name'] ?? '');
  if ($name === '') return ['ok' => false, 'msg' => '請選擇使用者'];
  x_('DELETE FROM sso WHERE name = ?', [$name]);
  log_('窗口解除快速登入', '', '', '', $name, '（操作者 ' . actorOf_($p) . '）');
  return ['ok' => true, 'msg' => '已解除 ' . $name . ' 的 LINE／Google 登入綁定，本人要再用密碼登入重新綁定'];
}
