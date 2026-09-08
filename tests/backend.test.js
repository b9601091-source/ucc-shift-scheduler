// v3.9 後端測試（Node 模擬 Apps Script 服務）
const { Spreadsheet, makeEnv, buildMonth } = require('./gas_mock');
const path = require('path');
const D = path.join(__dirname, '..', 'src') + path.sep;
let fails = 0;
function ok(cond, label, extra) { console.log((cond ? '  ✓ ' : '  ✗ ') + label + (extra !== undefined ? '  ' + JSON.stringify(extra) : '')); if (!cond) fails++; }

const ROSTER = [['A', '王小明', false], ['B', '陳小美', false], ['C', '林小華', false]];
const ss = new Spreadsheet('測試班表');
const C = makeEnv({ ss, today: '2026-09-08', configPath: D + 'Config.example.gs', codePath: D + 'Code.gs',
  configPatch: "CFG.SPREADSHEET_ID='x'; CFG.ADMIN_NAMES=['陳小美']; CFG.STAFF_ACCOUNTS=[{seq:'Z',name:'會務人員'}];" });
// 日期物件要用 vm 裡的 Date 建，parseSheet_ 的 instanceof 才認得
buildMonth(ss, '115/09', 2026, 9, ROSTER, { D: C.Date });
buildMonth(ss, '115/10', 2026, 10, ROSTER, { D: C.Date, fill: [['2026-10-04', 'pm', '林小華']] });

console.log('=== 基本');
ok(C.listMonths_().join() === '115/10,115/09', 'listMonths_ 新→舊', C.listMonths_());
const p10 = C.parseSheet_('115/10');
ok(p10.days.filter(d => d.inMonth).length === 31 && p10.days.filter(d => d.open).length === 4, '115/10：31 天、週日 4 天開放');
ok(p10.order.map(o => o.seq + o.name).join() === 'A王小明,B陳小美,C林小華' && p10.notesRow === 3 && p10.notesCol === 14, '順位面板與注意事項錨點');
const b = C.getBootstrap();
ok(b.ui && b.ui.orgName && b.rules.maxFail === 10, 'bootstrap 帶 ui 與 maxFail');
ok(b.roster.map(r => r.label).join() === 'Ａ．王○明,Ｂ．陳○美,Ｃ．林○華,Ｚ．會務人員', '登入下拉遮罩、會務人員不遮', b.roster.map(r => r.label));

console.log('\n=== 登入與身分');
const tA = C.login({ seq: 'A', pw: '0000' }), tB = C.login({ seq: 'B', pw: '0000' }), tZ = C.login({ seq: 'Z', pw: '0000' });
ok(tA.ok && tB.ok && tZ.ok && tZ.readOnly === true, '三人都能用預設密碼登入，Z 為不參與排班');
const gA = C.getMonth({ month: '115/10', token: tA.token }), gB = C.getMonth({ month: '115/10', token: tB.token }), gZ = C.getMonth({ month: '115/10', token: tZ.token });
ok(gA.viewer.canAdmin === false && gA.viewer.isSuper === false && gA.viewer.defaultPw === '0000', 'A：無後臺、帶 defaultPw');
ok(gB.viewer.isSuper && gB.viewer.canAdmin && gB.viewer.perms.grant && gB.viewer.perms.roster, 'B：超級管理者全權限');
ok(gZ.viewer.canAdmin === false && gZ.viewer.readOnly === true, 'Z：未授權前無後臺');
ok(C.changePassword({ token: tZ.token, oldPw: '0000', newPw: '1234' }).ok && C.login({ seq: 'Z', pw: '1234' }).ok, 'Z 能改自己的密碼並用新密碼登入');

console.log('\n=== 權限授權');
const d1 = C.setPerms({ token: tA.token, name: '會務人員', perms: ['lock'] });
ok(!d1.ok && /超級管理者/.test(d1.msg), 'A 不能授權', d1.msg);
const d2 = C.setPerms({ token: tB.token, name: '陳小美', perms: ['lock'] });
ok(!d2.ok, '不能對超級管理者授權', d2.msg);
const g1 = C.setPerms({ token: tB.token, name: '會務人員', perms: ['lock', 'roster', 'bogus'] });
ok(g1.ok && C.__props['perm_會務人員'] === '["lock","roster"]', 'B 授權 Z：lock＋roster（無效鍵被丟掉）', C.__props['perm_會務人員']);
const gZ2 = C.getMonth({ month: '115/10', token: tZ.token });
ok(gZ2.viewer.canAdmin && gZ2.viewer.perms.lock && gZ2.viewer.perms.roster && !gZ2.viewer.perms.notes && !gZ2.viewer.isSuper, 'Z 授權後：canAdmin、只有 lock/roster');
const n1 = C.saveNotes({ token: tZ.token, month: '115/10', notes: 'x' });
ok(!n1.ok && n1.msg === '你沒有「編輯注意事項」的權限', 'Z 無 notes 權限被擋', n1.msg);
const l1 = C.setLock({ token: tZ.token, month: '115/10' });
ok(l1.ok && C.lockState_().locked, 'Z 可鎖定');
const s1 = C.submitShift({ token: tA.token, month: '115/10', iso: '2026-10-04', shift: 'am', name: '王小明' });
ok(!s1.ok && /鎖定/.test(s1.msg), '鎖定期間 A 不能填班', s1.msg);
const s2 = C.submitShift({ token: tB.token, month: '115/10', iso: '2026-10-04', shift: 'am', name: '王小明' });
ok(s2.ok, '超級管理者鎖定期間可代填');
ok(C.clearLock({ token: tZ.token }).ok && !C.lockState_().locked, 'Z 可解鎖');
const au = C.adminUsers({ token: tZ.token });
ok(au.ok && !au.isSuper && au.rows.length === 4 && au.rows[0].perms === undefined, 'Z（roster 權限）能看名單但看不到授權欄');
const auB = C.adminUsers({ token: tB.token });
ok(auB.isSuper && auB.keys.length === 8 && auB.rows.find(r => r.name === '會務人員').perms.join() === 'lock,roster', 'B 看得到授權欄與 8 個鍵');
ok(!C.adminUsers({ token: tA.token }).ok, 'A 不能看名單');

console.log('\n=== 交棒與代管');
ok(!C.setDone({ token: tZ.token, month: '115/10', name: '王小明', done: true }).ok, 'Z 不能交棒');
const sk = C.adminSkip({ token: tB.token, month: '115/10', name: '王小明' });
ok(sk.ok && C.parseSheet_('115/10').order[0].done === true, 'B 標記 A 逾期 → 完成註記 TRUE');
ok(!C.adminSkip({ token: tZ.token, month: '115/10', name: '王小明' }).ok, 'Z 無 skip 權限');
const c1 = C.cancelShift({ token: tA.token, month: '115/10', iso: '2026-10-04', shift: 'pm', name: '林小華' });
ok(!c1.ok && /只有本人或公會窗口/.test(c1.msg), 'A 不能取消別人的班', c1.msg);

console.log('\n=== 增減人員');
const r0 = C.rosterAdd({ token: tA.token, name: '黃小婷' });
ok(!r0.ok && r0.msg === '你沒有「增減人員」的權限', 'A 不能增人', r0.msg);
const r1 = C.rosterAdd({ token: tZ.token, name: '黃小婷' });
ok(r1.ok && r1.seq === 'D', 'Z 加入黃小婷 → 代號 D', r1.msg);
const o10 = C.parseSheet_('115/10').order, o09 = C.parseSheet_('115/09').order;
ok(o10.length === 4 && o10[3].seq === 'D' && o10[3].name === '黃小婷' && o10[3].done === false && o10[3].row === 7, '115/10 面板第 4 列＝D 黃小婷');
ok(o09.length === 4 && o09[3].name === '黃小婷', '115/09（本月）也加了');
ok(ss.getSheetByName('115/10').cb['7,12'] === true, '新列的完成註記有勾選框');
ok(!C.rosterAdd({ token: tZ.token, name: '黃小婷' }).ok && !C.rosterAdd({ token: tZ.token, name: '會務人員' }).ok, '重複姓名／會務人員名稱被擋');
ok(C.login({ seq: 'D', pw: '0000' }).ok, '新人可用預設密碼登入');
const rm1 = C.rosterRemove({ token: tZ.token, name: '林小華' });
ok(!rm1.ok && /115年10月 還有班/.test(rm1.msg), '林小華還有班 → 擋下並列出月份', rm1.msg);
ok(C.cancelShift({ token: tB.token, month: '115/10', iso: '2026-10-04', shift: 'pm', name: '林小華' }).ok, 'B 代為取消那一班');
C.__props['pw_林小華'] = 'hash'; C.__props['perm_林小華'] = '["lock"]';
const rm2 = C.rosterRemove({ token: tZ.token, name: '林小華' });
ok(rm2.ok, '再移除 → 成功', rm2.msg);
const o10b = C.parseSheet_('115/10').order;
ok(o10b.map(o => o.seq + o.name).join() === 'A王小明,B陳小美,D黃小婷', '面板重寫為 A,B,D', o10b.map(o => o.seq + o.name));
const sh10 = ss.getSheetByName('115/10');
ok(sh10.get(7, 10) === '' && sh10.get(7, 11) === '' && sh10.get(7, 12) === '' && !sh10.cb['7,12'], '最後一列三格清空、勾選框移除');
ok(sh10.get(7, 2) instanceof Date === false && sh10.get(6, 2) instanceof Date, '月曆列沒被動到（第 6 列仍是日期列）');
ok(!('pw_林小華' in C.__props) && !('perm_林小華' in C.__props), '密碼與授權屬性已清');
ok(!C.rosterRemove({ token: tZ.token, name: '陳小美' }).ok && !C.rosterRemove({ token: tZ.token, name: '會務人員' }).ok, '超級管理者／會務人員不能從網頁移除');
const r2 = C.rosterAdd({ token: tB.token, name: '林小華' });
ok(r2.ok && r2.seq === 'C', '再加回林小華 → 重用空出來的代號 C');

console.log('\n=== createNextMonth 仍正常');
const cn = C.createNextMonth({ token: tB.token });
ok(cn.ok && cn.month === '115/11', '建立 115/11', cn.msg);
const o11 = C.parseSheet_('115/11').order;
ok(o11.map(o => o.seq + o.name).join() === 'B陳小美,D黃小婷,C林小華,A王小明' && o11.every(o => !o.done), '順位左移一位、完成註記歸零', o11.map(o => o.seq + o.name));

console.log('\n=== setupSpreadsheet');
const ss2 = new Spreadsheet('空白');
const E = makeEnv({ ss: ss2, today: '2026-09-08', configPath: D + 'Config.example.gs', codePath: D + 'Code.gs',
  configPatch: "CFG.SPREADSHEET_ID=''; CFG.SETUP_ROSTER=['甲藥師','乙藥師','丙藥師']; CFG.ADMIN_NAMES=['甲藥師'];" });
E.setupSpreadsheet();
ok(/已建立新試算表[\s\S]*ID：MOCK_/.test(E.__logs.join(' ')) && ss2.getSheets().length === 0, 'ID 空白 → 建新表、印 ID、不建分頁', E.__logs[0].slice(0, 40));
E.CFG.SPREADSHEET_ID = 'y'; E._SS = null;   // 每次執行都是新環境，模擬時要清快取
E.setupSpreadsheet();
ok(E.listMonths_().join() === '115/10', '建立次月分頁 115/10', ss2.getSheets().map(s => s.getName()));
const sp = E.parseSheet_('115/10');
ok(sp.days.filter(d => d.inMonth).length === 31 && sp.days.filter(d => d.open).length === 4, '31 天、週日 4 天開放');
ok(sp.order.map(o => o.seq + o.name).join() === 'A甲藥師,B乙藥師,C丙藥師' && sp.order[0].row === 4 && sp.order[0].doneCol === 12, '順位 A~C 在 J4:L6');
ok(sp.notesRow === 3 && sp.notesCol === 14, '注意事項錨點 N2');
ok(ss2.getSheetByName('_可排班日') && ss2.getSheetByName('_可排班日').hidden && ss2.getSheetByName('_操作紀錄'), '_可排班日、_操作紀錄 已建');
const sh = ss2.getSheetByName('115/10');
ok(sh.get(4, 1) === '早班\n0800~1600' && sh.get(5, 1) === '晚班\n1600~2400' && sh.get(2, 2) === '周日' && sh.get(2, 10) === '本月排班順位', 'A 欄班別標籤、標題列');
E.setupSpreadsheet();
ok(E.listMonths_().length === 1 && /不重複建立/.test(E.__logs[E.__logs.length - 1]), '再跑一次不重複建');
const tG = E.login({ seq: 'A', pw: '0000' });
const cn2 = E.createNextMonth({ token: tG.token });
ok(cn2.ok && cn2.month === '115/11' && E.parseSheet_('115/11').order.map(o => o.name).join() === '乙藥師,丙藥師,甲藥師', '新表也能建下月並輪替');
const sub = E.submitShift({ token: tG.token, month: '115/10', iso: '2026-10-11', shift: 'am', name: '甲藥師' });
ok(sub.ok && E.parseSheet_('115/10').slots['2026-10-11#am'].value === '甲藥師', '新表能填班');

console.log('\n結果：' + (fails ? fails + ' 項失敗 ✗' : '全部通過 ✓'));
process.exit(fails ? 1 : 0);
