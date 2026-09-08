# 改成你們的

## 一句話

**只改 `src/Config.gs`。** `Code.gs` 與 `Index.html` 不含任何組織專屬字串，改版時整檔覆蓋即可。

## Config.gs 逐欄說明

| 欄位 | 填什麼 | 注意 |
|---|---|---|
| `SPREADSHEET_ID` | 試算表網址 `/d/` 與 `/edit` 之間那串 | 第一次可留空，`setupSpreadsheet()` 會建一份並印出 ID |
| `LOG_SHEET` | 操作紀錄分頁名稱 | 通常不用改 |
| `DEFAULT_PASSWORD` | 所有人的初始密碼 | 公告時要求立刻改掉 |
| `TOKEN_DAYS` | 登入記住幾天 | |
| `LOGIN_MAX_FAIL`／`LOGIN_LOCK_MIN`／`LOGIN_FAIL_WINDOW_MIN` | 登入鎖定策略 | |
| `ADMIN_NAMES` | 超級管理者姓名陣列 | 可以是順位裡的人，也可以是 `STAFF_ACCOUNTS` 的名稱 |
| `STAFF_ACCOUNTS` | 不參與排班的帳號 `[{seq, name}]` | 代號用順位用不到的字母（順位由 A 往後） |
| `DEADLINE_DAY`／`OPEN_DAY` | 每月幾號前完成、幾號公布 | 前端「公布」「完成期限」與逾期警示用 |
| `MAX_SHIFTS_PER_MONTH` | 每人每月上限 | 超過時警示 |
| `MAX_CONSECUTIVE_DAYS` | 最多連續幾天 | |
| `LONG_HOLIDAY_MIN` | 連續幾個可排班日算長連假 | 觸發「前三個月排最多者優先」 |
| `UI.orgName`／`orgShort`／`systemName` | 組織全名、短名、系統名 | 頁尾、瀏覽器標題 |
| `UI.titleDesktop`／`titleMobile` | 頁首標題 | 手機版字要少 |
| `UI.venueLine` | 頁首第二行 | 醫院與班別時間 |
| `UI.shifts` | 兩班的名稱與時段 | 數量固定兩班 |
| `UI.contactShort` | 訊息裡「請洽○○」 | 例：公會窗口、管理者 |
| `UI.contactLine`／`forgotLine` | 頁尾與登入頁的聯絡方式 | |
| `UI.loginScope`／`confidentialNote` | 登入頁一句話、頁尾紅字 | |
| `UI.rulesTitle`／`rules` | 規則區塊標題與清單 | 純顯示，程式不解析 |
| `SETUP_ROSTER` | 第一次建表的順位名單 | 已有月份分頁時不用 |
| `HOLIDAYS` | 週日以外的假日候選 | 全部預設不開放，由管理者在 `_可排班日` 改 |

改完 `Config.gs` 要**重新建版本部署**才會生效（clasp：`push` → `create-version` → `redeploy`）。

## 可以放心改的地方

- `Index.html` 頂部 `:root` 的顏色變數、字級、間距。
- `Index.html` 裡固定的中文文案（按鈕、提示）——但組織相關的請走 `Config.gs`。
- `checkRules(iso, shift)`：要加一條規則檢核，在函式尾端加 `w.push('<b>標題</b>：說明')`。
  需要後端資料的話在 `getMonthData_()` 補欄位。
- 後臺新增功能：`PERMS` 加鍵 → 後端函式用 `requireAdmin_(payload, '鍵')` → 前端 `details.sub` 加 `data-perm="鍵"`。

## 不要碰（除非你知道在做什麼）

- `Code.gs` 的「帳號與登入」「權限」兩節：雜湊、token、失敗鎖定、`requireAdmin_`。
- `parseSheet_()`：它是唯一知道試算表版面的地方；改它等於改契約，`createNextMonth`、`setupSpreadsheet`、`rosterAdd`、`rosterRemove` 都依賴同一套假設。
- 任何對月份分頁 `deleteRow`／`insertRow` 的想法。順位面板與月曆共用列。
- `api()` 裡「`undefined` 不能當參數」那一行。

## 常見客製

**多個會務帳號**：`STAFF_ACCOUNTS` 加物件；每個都有自己的密碼與授權。

**會務人員當超級管理者**：把 `STAFF_ACCOUNTS` 裡的名稱也放進 `ADMIN_NAMES`。

**沒有「長連假優先權」這條規則**：`LONG_HOLIDAY_MIN` 設 99（永遠不觸發），並把 `UI.rules` 裡那條刪掉。

**改班別名稱**：`UI.shifts.am.label`／`pm.label`。試算表裡的 A 欄標籤只是給人看的，程式不讀。

**三班制**：不支援直接設定。要改 `parseSheet_`（每週三列 → 四列）、`createNextMonth`、`setupSpreadsheet` 的寫入、前端 `render()` 的月曆與日期卡片。

**非民國紀年**：`decodeMonth_`／`encodeMonth_`／`currentMonthSheet_`／`affectedMonths_` 都假設分頁名是民國年，改這四處的 `+1911`／`-1911`。

**每年的國定假日**：`HOLIDAYS` 補當年度日期即可，舊的留著無妨。`_可排班日` 分頁已存在時不會重建，直接在分頁上加列。

## 已有試算表的組織

不必重建。把現有分頁對照 [版面契約](architecture.md#試算表版面契約)：分頁名稱、B～H 欄、每週三列、
「排序／姓名／完成註記」面板、「當月班表填寫注意事項」錨點。對得上就直接填 `SPREADSHEET_ID` 部署；
在編輯器執行 `diagnose()` 會印出程式實際看到的分頁與解析結果，對不上會講清楚哪裡不對。
