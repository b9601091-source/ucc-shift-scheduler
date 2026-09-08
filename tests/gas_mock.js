// Apps Script 服務的最小模擬：讓 Code.gs 在 Node 裡跑起來（測 parseSheet_ / rosterAdd / rosterRemove / setupSpreadsheet / 權限）
const crypto = require('crypto');
const vm = require('vm');
const fs = require('fs');

function pad2(n) { return ('0' + n).slice(-2); }

class Range {
  constructor(sheet, r, c, nr, nc) { this.s = sheet; this.r = r; this.c = c; this.nr = nr; this.nc = nc; }
  getValues() { const out = []; for (let i = 0; i < this.nr; i++) { const row = []; for (let j = 0; j < this.nc; j++) row.push(this.s.get(this.r + i, this.c + j)); out.push(row); } return out; }
  setValues(v) { for (let i = 0; i < v.length; i++) for (let j = 0; j < v[i].length; j++) this.s.set(this.r + i, this.c + j, v[i][j]); return this; }
  getValue() { return this.s.get(this.r, this.c); }
  setValue(v) { for (let i = 0; i < this.nr; i++) for (let j = 0; j < this.nc; j++) this.s.set(this.r + i, this.c + j, v); return this; }
  clearContent() { return this.setValue(''); }
  insertCheckboxes() { for (let i = 0; i < this.nr; i++) for (let j = 0; j < this.nc; j++) { this.s.cb[(this.r + i) + ',' + (this.c + j)] = true; const v = this.s.get(this.r + i, this.c + j); if (v === '' || v == null) this.s.set(this.r + i, this.c + j, false); } return this; }
  clearDataValidations() { for (let i = 0; i < this.nr; i++) for (let j = 0; j < this.nc; j++) delete this.s.cb[(this.r + i) + ',' + (this.c + j)]; return this; }
  setBackground() { return this; } setNumberFormat() { return this; } setHorizontalAlignment() { return this; }
  setFontWeight() { return this; } merge() { return this; }
}
class Sheet {
  constructor(ss, name) { this.ss = ss; this.name = name; this.grid = {}; this.cb = {}; this.maxR = 0; this.maxC = 0; this.hidden = false; }
  get(r, c) { const v = this.grid[r + ',' + c]; return v === undefined ? '' : v; }
  set(r, c, v) { this.grid[r + ',' + c] = v; if (r > this.maxR) this.maxR = r; if (c > this.maxC) this.maxC = c; }
  getName() { return this.name; } setName(n) { this.name = n; return this; }
  getRange(r, c, nr, nc) { return new Range(this, r, c, nr || 1, nc || 1); }
  getDataRange() { return new Range(this, 1, 1, Math.max(this.maxR, 1), Math.max(this.maxC, 1)); }
  getLastRow() { return this.maxR; } getMaxRows() { return Math.max(this.maxR, 1000); }
  appendRow(row) { const r = this.maxR + 1; row.forEach((v, j) => this.set(r, j + 1, v)); return this; }
  hideSheet() { this.hidden = true; return this; } setFrozenRows() { return this; } setColumnWidth() { return this; }
  insertColumnBefore(c) { const g = {}; for (const k in this.grid) { const [r, cc] = k.split(',').map(Number); g[r + ',' + (cc >= c ? cc + 1 : cc)] = this.grid[k]; } this.grid = g; this.maxC++; return this; }
  copyTo(ss) { const n = new Sheet(ss, this.name + ' 的副本'); n.grid = Object.assign({}, this.grid); n.cb = Object.assign({}, this.cb); n.maxR = this.maxR; n.maxC = this.maxC; ss.sheets.push(n); return n; }
  getIndex() { return this.ss.sheets.indexOf(this) + 1; }
}
class Spreadsheet {
  constructor(name) { this.name = name; this.sheets = []; this.id = 'MOCK_' + Math.random().toString(36).slice(2, 8); this.active = null; }
  getName() { return this.name; } getId() { return this.id; } getUrl() { return 'https://mock/' + this.id; }
  getSpreadsheetTimeZone() { return 'Asia/Taipei'; }
  getSheets() { return this.sheets.slice(); }
  getSheetByName(n) { return this.sheets.find(s => s.name === n) || null; }
  insertSheet(name, idx) { const s = new Sheet(this, name); if (idx === undefined) this.sheets.push(s); else this.sheets.splice(idx, 0, s); return s; }
  setActiveSheet(s) { this.active = s; return s; }
  moveActiveSheet(pos) { const i = this.sheets.indexOf(this.active); this.sheets.splice(i, 1); this.sheets.splice(pos - 1, 0, this.active); }
}

function makeEnv(opts) {
  const ss = opts.ss;
  const props = {};
  const cache = {};
  const logs = [];
  const env = {
    SpreadsheetApp: { openById: () => ss, getActive: () => ss, create: (n) => new Spreadsheet(n) },
    PropertiesService: { getScriptProperties: () => ({
      getProperty: k => (k in props ? props[k] : null), setProperty: (k, v) => { props[k] = String(v); }, deleteProperty: k => { delete props[k]; } }) },
    CacheService: { getScriptCache: () => ({ get: k => cache[k] || null, put: (k, v) => { cache[k] = v; } }) },
    LockService: { getScriptLock: () => ({ waitLock() {}, releaseLock() {} }) },
    Logger: { log: (t) => logs.push(String(t)) },
    Session: { getEffectiveUser: () => ({ getEmail: () => 'mock@example.com' }) },
    HtmlService: { createHtmlOutputFromFile: () => ({ setTitle() { return this; }, addMetaTag() { return this; }, setXFrameOptionsMode() { return this; } }), XFrameOptionsMode: { ALLOWALL: 1 } },
    Utilities: {
      formatDate: (d, tz, fmt) => { if (fmt === 'yyyy-MM-dd') return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); throw new Error('fmt ' + fmt); },
      getUuid: () => crypto.randomUUID(),
      DigestAlgorithm: { SHA_256: 'sha256' }, Charset: { UTF_8: 'utf8' },
      computeDigest: (alg, s) => [...crypto.createHash('sha256').update(String(s), 'utf8').digest()].map(b => b > 127 ? b - 256 : b),
      computeHmacSha256Signature: (s, k) => [...crypto.createHmac('sha256', String(k)).update(String(s), 'utf8').digest()].map(b => b > 127 ? b - 256 : b)
    },
    Date, JSON, Math, Object, String, Number, Array, Error, parseInt, console,
    __props: props, __logs: logs
  };
  if (opts.today) { const T = opts.today; env.Date = class extends Date { constructor(...a) { if (a.length) super(...a); else super(T + 'T09:00:00'); } static now() { return new Date(T + 'T09:00:00').getTime(); } }; }
  const ctx = vm.createContext(env);
  vm.runInContext(fs.readFileSync(opts.configPath, 'utf8'), ctx, { filename: 'Config.gs' });
  if (opts.configPatch) vm.runInContext(opts.configPatch, ctx);
  vm.runInContext(fs.readFileSync(opts.codePath, 'utf8'), ctx, { filename: 'Code.gs' });
  return ctx;
}

/** 依正式表版面建一個月份分頁（第 2 列標題、第 3 列起每週三列、J~L 順位面板、N2 注意事項） */
function buildMonth(ss, name, y, m, roster, opts) {
  opts = opts || {};
  const DT = opts.D || Date;
  const sh = ss.insertSheet(name);
  ['周日', '周一', '週二', '週三', '週四', '週五', '週六'].forEach((t, i) => sh.set(2, 2 + i, t));
  sh.set(2, 10, '本月排班順位'); sh.set(3, 10, '排序'); sh.set(3, 11, '姓名'); sh.set(3, 12, '完成註記');
  sh.set(2, 14, '當月班表填寫注意事項：');
  const first = new DT(y, m - 1, 1); const start = new DT(first.getTime() - first.getDay() * 864e5);
  const last = new DT(y, m, 0); const weeks = Math.ceil((first.getDay() + last.getDate()) / 7);
  let cur = new DT(start.getTime());
  for (let w = 0; w < weeks; w++) {
    const dr = 3 + w * 3;
    sh.set(dr + 1, 1, '早班\n0800~1600'); sh.set(dr + 2, 1, '晚班\n1600~2400');
    for (let c = 0; c < 7; c++) {
      const inM = cur.getMonth() + 1 === m; const open = inM && cur.getDay() === 0;
      sh.set(dr, 2 + c, new DT(cur.getTime())); sh.set(dr + 1, 2 + c, open ? '' : 'X'); sh.set(dr + 2, 2 + c, open ? '' : 'X');
      cur = new DT(cur.getTime() + 864e5);
    }
  }
  roster.forEach((r, i) => { sh.set(4 + i, 10, r[0]); sh.set(4 + i, 11, r[1]); sh.set(4 + i, 12, !!r[2]); sh.cb[(4 + i) + ',12'] = true; });
  if (opts.fill) opts.fill.forEach(f => { // [iso, 'am'|'pm', name]
    for (const k in sh.grid) { const v = sh.grid[k]; if (v instanceof Date && v.getFullYear() + '-' + pad2(v.getMonth() + 1) + '-' + pad2(v.getDate()) === f[0]) { const [r, c] = k.split(',').map(Number); sh.set(r + (f[1] === 'am' ? 1 : 2), c, f[2]); } }
  });
  return sh;
}

module.exports = { Spreadsheet, Sheet, makeEnv, buildMonth };
