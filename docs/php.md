# PHP 自架版

畫面與操作和 Apps Script 版完全相同，後端改成 PHP＋SQLite，放在組織自己的網站主機。

## 需求

- PHP 8.0 以上（開發與上線環境為 8.2），需要 `sqlite3` 擴充（**不需要** `pdo_sqlite`、`mbstring`、`zip`、`gd`）
- Apache 或 LiteSpeed（靠 `.htaccess` 擋住 `lib/`、`data/`）；用 Nginx 要自己把這兩個資料夾設成禁止存取
- 不需要資料庫伺服器、不需要 Composer、不需要排程

## 資料夾

| 路徑 | 說明 |
|---|---|
| `php/ucc/api.php` | 唯一 PHP 入口。`POST {"fn":"getMonth","arg":{…}}` → 直接回該函式的結果（形狀同 Apps Script 版 `google.script.run`）；`GET ?diag=1` 診斷、`?smoke=1` 煙霧測試 |
| `php/ucc/lib/config.example.php` | **組織專屬設定範本**。複製成 `config.php` 再改；沒有 `config.php` 時程式直接用範本 |
| `php/ucc/lib/core.php` | SQLite 資料層、月份格線、建立月份、診斷 |
| `php/ucc/lib/auth.php` | 登入、token、密碼、失敗鎖定、權限、班表鎖定、頁首頁尾文字覆蓋 |
| `php/ucc/lib/sched.php` | 班表 API（讀月份、填班、取消、交棒、鎖定、開放日、注意事項、建立月份） |
| `php/ucc/lib/admin.php` | 增減人員、備份匯出與整批搬移（JSON）、Excel 匯出（自製 xlsx，不需 ZipArchive） |
| `php/ucc/index.html` | 前端。**由 `php/tools/build_index.py` 從 `src/Index.html` 產生，不要手改** |
| `php/ucc/data/` | 上線後才會出現資料庫 `ucc.sqlite` 與錯誤紀錄；整個資料夾禁止外部存取 |
| `php/tests/run.php` | 後端測試（80 項），每個測試用一個全新的暫存 SQLite |
| `php/tests/dev_router.php` | 本機開發伺服器路由（模擬 `.htaccess` 的擋法） |

## 本機試用

```
php -S 127.0.0.1:8766 -t php/ucc php/tests/dev_router.php
```

打開 <http://127.0.0.1:8766/>。資料放在系統暫存資料夾（可用環境變數 `UCC_DEV_DATA` 指定），不會寫進專案。
全新資料庫只有會務人員帳號（`Ｚ．會務人員`，密碼 `0000`）；要先有排班名單，可在 `config.php` 的 `SETUP_ROSTER` 填姓名，或從 Apps Script 版搬移。

## 上線

1. `cp php/ucc/lib/config.example.php php/ucc/lib/config.php`，改組織名稱、醫院、聯絡窗口、規則數字與文字、國定假日（`HOLIDAYS()`）。
2. 前端有改過 `src/Index.html` 的話，先跑 `python php/tools/build_index.py`。
3. 把 `php/ucc/` 整個資料夾上傳到網站的子資料夾（例如 `/web/ucc/`），**包含三個 `.htaccess`**。
4. 主機預設 PHP 版本太舊時，依主機商說明切到 8.x（見 `php/ucc/.htaccess` 的註解）。
5. 上線檢查（每一項都要看）：
   - `api.php?diag=1` 顯示 `php = 8.x`，`?smoke=1` 全部通過
   - `lib/config.php`、`lib/core.php`、`data/`、`data/ucc.sqlite` 都必須回 **403**（或 404），**不能看到 PHP 原始碼**
   - 用 `Ｚ．會務人員`／`0000` 登入後**立刻改密碼**：它是超級管理者
6. 把網址發給大家，請每個人第一次登入就改密碼。

`data/` 資料夾需要可寫入；第一次有請求時會自動建立資料庫與資料表。

## 從 Apps Script 版搬過來

1. Apps Script 版用超級管理者登入 → 後臺管理 → 資料備份 → 下載全部資料（JSON）。
2. PHP 版用 `Ｚ．會務人員`／`0000` 登入（全新資料庫的預設）→ 後臺管理 → 資料備份與搬移 → 選檔 → 勾「取代現有資料」→ 開始匯入。
3. 匯入後頁面會要求重新登入：改用 Apps Script 版的密碼（密碼雜湊與簽章金鑰一起搬過來了）。
4. 核對月份、順位、完成註記、注意事項、可排班日，並請一兩位成員試登入。
5. 確認無誤後，在 Apps Script 版的 `Config.gs` 填 `RETIRED_URL: '<新網址>'` 重新部署：舊網址會整站唯讀並提示新網址。

對應關係：ScriptProperties → `_props` 表；`_可排班日` → `open_days`；`_操作紀錄` → `log`；每個月份分頁 → `months`／`slots`／`roster`／`notes`。

## 尖峰與主機限制

共享主機通常限制同時執行的 PHP 程序數（常見約 30），超過會回 508／507／503。
前端 `api()` 遇到這些狀態或「系統忙碌中」會自動指數退避重試（最多 10 次、約 20 秒），畫面顯示「排隊處理中」。
每次請求約 0.05–0.1 秒，一般公會規模（數十人）不會碰到上限。

## 改版流程

1. 畫面改在 `src/Index.html`（兩版共用）→ `python php/tools/build_index.py` 重產 `php/ucc/index.html`。
2. 後端改 `php/ucc/lib/*.php`；`config.php` 的 `APP_VERSION` 遞增、`CHANGELOG()` 加一則。
3. `php php/tests/run.php` 全過再上傳。上傳順序建議：`.htaccess` 先、`lib/` 次之、`api.php`、最後 `index.html`；**絕不上傳本機的 `data/`**。

## 安全性

- 與 Apps Script 版相同：密碼只存加鹽 SHA-256、token 用 HMAC 簽章，簽章金鑰第一次執行時隨機產生存在資料庫；原始碼公開不影響部署安全。
- 資料庫在 `data/`，靠 `.htaccess` 擋外部存取；**換主機或換網頁伺服器時務必重新確認 403**。
- 錯誤訊息不外洩堆疊，詳細錯誤寫在 `data/php-error.log`。
