<?php
// 本機開發伺服器路由：php -S 127.0.0.1:8766 -t php/ucc php/tests/dev_router.php
// 資料放系統暫存資料夾（不寫進專案），並模擬 .htaccess 的擋法。
$GLOBALS['UCC_DATA_DIR'] = getenv('UCC_DEV_DATA') ?: (sys_get_temp_dir() . '/ucc_dev');
// UCC_DEV_SSO=1：用假金鑰讓登入頁出現 LINE／Google 按鈕（看版面用，按下去會到真的 LINE／Google 然後失敗）
if (getenv('UCC_DEV_SSO')) $GLOBALS['UCC_SSO_KEYS'] = ['line_channel_id' => '1234567890', 'line_channel_secret' => 'devsecret', 'google_client_id' => 'dev.apps.googleusercontent.com'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(lib|data)(/|$)#', $uri) || preg_match('#/\.#', $uri) || (preg_match('#\.php$#', $uri) && $uri !== '/api.php' && $uri !== '/sso.php')) { http_response_code(403); echo 'Forbidden'; return true; }
if ($uri === '/api.php' || $uri === '/sso.php') { chdir(__DIR__ . '/../ucc'); require __DIR__ . '/../ucc' . $uri; return true; }
return false;
