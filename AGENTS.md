# AGENTS.md — 給 AI 工具看的專案說明

這份文件寫給 Claude Code、Codex、Cursor 等 AI 開發工具。人類讀 README.md 就夠了。

## 這是什麼

Google Apps Script 網頁應用程式：讓一群人依順位輪流填班，資料直接讀寫使用者現有的 Google 試算表。
沒有資料庫、沒有建置步驟、沒有框架。前端是一個 HTML 檔，後端是兩個 .gs 檔。

## 檔案地圖

| 路徑 | 角色 | 可以改嗎 |
|---|---|---|
| `src/Config.gs`（由 `Config.example.gs` 複製） | **全部組織專屬設定**：試算表 ID、管理者、會務帳號、規則數字、畫面文字、假日 | **這是主要修改點** |
| `src/Code.gs` | 後端：登入／權限／解析分頁／填班／後臺／建表 | 加功能可以；不要動「帳號與登入」「權限」兩節的驗證邏輯 |
| `src/Index.html` | 前端：CSS＋HTML＋JS 全在一檔 | 版面、顏色、文案可以；`api()`、`applyMonth()`、權限顯示邏輯要小心 |
| `src/appsscript.json` | 部署設定（V8、Asia/Taipei、網頁應用程式存取權） | 時區可改 |
| `demo/index.html` | 離線示範版：內建假後端，開檔即可玩 | 由正式版產生，不要手改 |
| `tests/` | Node 測試：`gas_mock.js` 模擬 Apps Script 服務；`backend.test.js`、`frontend.test.js`（jsdom） | 加功能請加測試 |
| `docs/` | 部署、架構、客製化說明 | — |

## 不變式（改任何東西前先讀）

1. **試算表是唯一資料來源。** 不要引入其他儲存；ScriptProperties 只放密碼雜湊、token 祕密、鎖定狀態、授權表、登入失敗計數。
2. **試算表版面契約**（`parseSheet_` 依賴，詳見 `docs/architecture.md`）：
   - 月份分頁名稱：`115/10`（民國年/月）或 `11510`
   - B～H 欄＝週日～週六；每週三列：日期列（B～H 是 Date 物件）、早班列、晚班列
   - 格子內容：`X`＝不開放、空白＝可填、其他＝已填的姓名
   - 順位面板：在 I～T 欄任一列找到值為「排序」的儲存格，右邊依序是「姓名」「完成註記」，往下讀到排序與姓名都空為止
   - 注意事項：第 1～6 列、I～Y 欄，找開頭為「當月班表填寫注意事項」的儲存格，其下方同欄最多 11 列
   - **順位面板與月曆在同一列，絕不能 `deleteRow`／`insertRow`**
3. **規則檢核是軟提示。** 前端 `checkRules()` 只產生警示文字，使用者確認後仍可送出。後端唯一硬擋的是「格子已被填走」「該格不開放」（資料完整性）與「鎖定期間非授權者不能改」。
4. **組織專屬字串不進 `Code.gs`／`Index.html`。** 任何顯示給使用者的組織名、地點、聯絡方式、規則文字都走 `CFG.UI`，經 `getBootstrap()` 的 `ui` 欄位送到前端由 `applyUi()` 填入。
   其中 `UI_KEYS` 列的八項可由後臺「頁首頁尾文字」覆蓋（ScriptProperties `UI_OVERRIDE`，`effectiveUi_()` 合併）；新增可覆蓋欄位就加進 `UI_KEYS`。
5. **`google.script.run` 不接受 `undefined` 參數**。沒有參數的函式用 `run[fn]()` 呼叫（見 `api()`）。
6. 完整姓名不出現在未登入的頁面：`getBootstrap()` 只送代號＋遮罩姓名。
7. 每次寫入試算表的動作都要 `log_()`；多人可能同時寫的動作要包 `LockService`。

## 權限模型（v3.9）

- `CFG.ADMIN_NAMES`：超級管理者。全部功能＋唯一能「授權」的人。範本預設是會務人員帳號（管理權跟職務不跟人）。
- `CFG.STAFF_ACCOUNTS`：不參與排班的帳號。能看、能被授權、不能填班／交棒（`isReadOnly_`）。
- 其他人的後臺功能由 `perm_<姓名>`（ScriptProperties，JSON 陣列）決定；鍵在 `PERMS` 常數：
  `lock`、`notes`、`skip`、`create`、`openday`、`resetpw`、`roster`、`manage`、`brand`。
- 後端每個後臺函式用 `requireAdmin_(payload, '<鍵>')` 守門；`manage` 另外控制取消他人的班、代交棒、鎖定期間仍可改。
- 前端 `#admCard details.sub[data-perm]` 依 `viewer.perms` 顯示；`grant` 分項只給 `viewer.isSuper`。
- **加新的後臺功能時**：在 `PERMS` 加一鍵、後端函式帶該鍵、前端分項加 `data-perm`。三處缺一不可。

## 常見客製需求怎麼做

| 需求 | 做法 |
|---|---|
| 換組織、換醫院、換聯絡人 | 只改 `Config.gs` 的 `UI` |
| 改規則數字（每月幾班、連續幾天、期限） | `Config.gs` 的 `MAX_SHIFTS_PER_MONTH` 等；規則清單文字在 `UI.rules` |
| 加一條規則檢核 | `Index.html` 的 `checkRules(iso, shift)` 加一段 `w.push(...)`；需要的資料若後端沒給，在 `getMonthData_()` 補 |
| 多個會務帳號 | `STAFF_ACCOUNTS` 加物件，代號用順位用不到的字母 |
| 換班別名稱／時段 | `UI.shifts`；**班別數量固定兩班**，改成三班要動 `parseSheet_`、`createNextMonth`、`setupSpreadsheet` 與前端月曆 |
| 非民國紀年 | `decodeMonth_`／`encodeMonth_`／`currentMonthSheet_`／`affectedMonths_` 的 `+1911` |
| 改顏色、字級 | `Index.html` 頂部 `:root` 變數與 CSS |

## 測試

```
npm install        # 只需要 jsdom
npm test           # backend.test.js（Apps Script 服務模擬）＋ frontend.test.js（jsdom）
```

後端測試用 `tests/gas_mock.js` 模擬 SpreadsheetApp／PropertiesService／Utilities 等，
直接載入 `src/Config.example.gs` 與 `src/Code.gs` 執行。加後端功能請在 `backend.test.js` 加案例；
前端改動請在 `frontend.test.js` 用假的 `google.script.run` 驗證畫面。

## 部署

- 正式部署走 clasp：`clasp push` → `clasp create-version` → **`clasp redeploy <既有部署 ID> -V <版本>`**。
  用 `create-deployment` 會產生新網址，大家的書籤會失效。
- Apps Script 對已部署頁面有快取，驗證時網址加 `?cb=<隨機字串>`。
- 改完 `Config.gs` 也要重新建版本部署才生效。

## 語言與風格

- 回應與程式註解用繁體中文（臺灣用語）。
- 程式風格：ES5 風格的 Apps Script（`var`、`function`），前端不用框架、不用建置工具。
- 使用者看得到的文字：全形標點、阿拉伯數字。
