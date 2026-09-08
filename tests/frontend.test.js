// v3.9 前端測試：組織文字由 ui 填入、權限分項顯示、增減人員、權限授權
const fs = require('fs');
const { JSDOM } = require('jsdom');
const path = require('path');
const P = path.join(__dirname, '..', 'src', 'Index.html');
const SRC = fs.readFileSync(P, 'utf8');
const MONTHS = [{ name: '115/10', label: '115年10月' }, { name: '115/09', label: '115年9月' }];
const UI = {
  orgShort: '測試公會', systemName: 'UCC 排班系統', creditHtml: '開源專案：<a href="https://example.test">GitHub</a>',
  titleDesktop: '社團法人測試市藥師公會　UCC 排班系統', titleMobile: '藥師 UCC 排班',
  venueLine: '某醫院　早班 08:00–16:00／晚班 16:00–24:00',
  shifts: { am: { label: '早班', hours: '0800~1600' }, pm: { label: '晚班', hours: '1600~2400' } },
  contactShort: '公會窗口', contactLine: '請洽公會窗口 (00)0000-0000 某某幹事',
  forgotLine: '忘記密碼請洽公會窗口重設。', loginScope: '本系統為公會內部使用。',
  confidentialNote: '內容請勿外傳', rulesTitle: '排班規則摘要（測試 V9）',
  rules: ['規則一', '規則二', '規則三']
};
const PERMKEYS = [['lock','班表鎖定'],['notes','編輯注意事項'],['skip','標記逾期放棄'],['create','建立月份分頁'],
  ['openday','編輯開放日'],['resetpw','使用者密碼重設'],['roster','增減人員'],['manage','代管班表'],['brand','頁首頁尾文字']].map(x=>({k:x[0],label:x[1]}));

function mkViewer(kind, granted) {
  const all = {}; PERMKEYS.forEach(p => all[p.k] = kind === 'super');
  (granted || []).forEach(k => all[k] = true);
  const perms = Object.assign({ grant: kind === 'super' }, all);
  const any = Object.keys(perms).some(k => perms[k]);
  const names = { pharm: '王小明', super: '陳小美', staff: '會務人員' };
  return { name: names[kind], readOnly: kind === 'staff', isDefaultPw: kind === 'pharm', defaultPw: '0000',
           perms, isSuper: kind === 'super', canAdmin: any };
}

function run(kind, granted, cb) {
  const calls = [];
  let rosterRows = [{ seq: 'A', name: '王小明', staff: false, superAdmin: false, perms: [] },
                    { seq: 'B', name: '陳小美', staff: false, superAdmin: true },
                    { seq: 'Z', name: '會務人員', staff: true, superAdmin: false, perms: ['lock'] }];
  const S = {
    getBootstrap: () => ({ months: MONTHS, today: '2026-09-08', defaultMonth: '115/10', currentMonth: '115/09',
      lock: { locked: false }, ui: UI,
      roster: [{ seq: 'A', label: 'Ａ．王○明' }, { seq: 'B', label: 'Ｂ．陳○美' }, { seq: 'Z', label: 'Ｚ．會務人員' }],
      rules: { maxShifts: 1, maxConsecutive: 3, deadlineDay: 15, openDay: 5, maxFail: 7, lockMin: 9 } }),
    getMonth: (p) => ({ name: p.month, label: (MONTHS.find(x => x.name === p.month) || {}).label || p.month,
      year: 2026, month: +p.month.slice(4),
      days: [{ iso: '2026-10-04', wd: 0, dayNum: 4, inMonth: true, open: true, am: '', pm: '陳小美', amClosed: false, pmClosed: false, longHoliday: false }],
      order: [{ seq: 'A', name: '王小明', done: false }, { seq: 'B', name: '陳小美', done: false }],
      notes: '', prevMonths: ['7月'], prevCounts: {}, today: '2026-09-08', lock: { locked: false }, forcedMonth: false,
      viewer: mkViewer(kind, granted) }),
    adminUsers: (p) => { calls.push(['adminUsers']); const sup = kind === 'super';
      return { ok: true, isSuper: sup, keys: sup ? PERMKEYS : [],
               rows: rosterRows.map(r => sup ? r : { seq: r.seq, name: r.name, staff: r.staff, superAdmin: r.superAdmin }) }; },
    setPerms: (p) => { calls.push(['setPerms', p.name, p.perms.slice()]); rosterRows.find(r => r.name === p.name).perms = p.perms; return { ok: true, msg: '已更新' }; },
    rosterAdd: (p) => { calls.push(['rosterAdd', p.name]); rosterRows.push({ seq: 'C', name: p.name, staff: false, superAdmin: false, perms: [] }); return { ok: true, msg: '已加入 ' + p.name }; },
    rosterRemove: (p) => { calls.push(['rosterRemove', p.name]); rosterRows = rosterRows.filter(r => r.name !== p.name); return { ok: true, msg: '已移除 ' + p.name }; },
    submitShift: (p) => { calls.push(['submitShift', p.name]); return { ok: true, data: S.getMonth(p) }; },
    getUiSettings: (p) => ({ ok: true, keys: [{ k: 'titleDesktop', label: '電腦版頁首標題' }, { k: 'titleMobile', label: '手機版頁首標題' }, { k: 'contactLine', label: '頁尾聯絡' }],
      values: { titleDesktop: UI.titleDesktop, titleMobile: UI.titleMobile, contactLine: UI.contactLine }, overridden: [] }),
    saveUiSettings: (p) => { calls.push(['saveUiSettings', p.values]); const ui = Object.assign({}, UI, p.values); return { ok: true, msg: '已儲存', ui }; }
  };
  const h = {}; h.withSuccessHandler = f => { h._ok = f; return h; }; h.withFailureHandler = f => { h._f = f; return h; };
  for (const k of Object.keys(S)) h[k] = function () { const a = arguments; setTimeout(() => h._ok(S[k].apply(null, a)), 5); return h; };
  const dom = new JSDOM(SRC, { runScripts: 'dangerously', pretendToBeVisual: true, url: 'https://x.test/',
    beforeParse(w) { w.google = { script: { run: h } };
      w.matchMedia = q => ({ media: q, matches: false, addEventListener() {}, addListener() {}, removeEventListener() {}, removeListener() {} });
      w.addEventListener('error', e => (w.__errs = w.__errs || []).push(e.message));
      try { w.localStorage.setItem('ucc_tok', 'tok'); } catch (e) {} } });
  setTimeout(() => cb(dom.window, dom.window.document, calls), 700);
}
const T = (D, id) => D.getElementById(id).textContent.replace(/\s+/g, ' ').trim();
const vis = (el) => el.style.display !== 'none';
const click = (w, el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
let fails = 0;
function ok(cond, label, extra) { console.log((cond ? '  ✓ ' : '  ✗ ') + label + (extra !== undefined ? '  ' + JSON.stringify(extra) : '')); if (!cond) fails++; }

run('pharm', [], (w, D) => {
  console.log('=== 組織文字（來自 bootstrap.ui）');
  ok(D.title === '測試公會 UCC 排班系統', 'document.title', D.title);
  ok(D.querySelector('#h1 .donly').textContent === UI.titleDesktop && D.querySelector('#h1 .mobonly').textContent === UI.titleMobile, 'h1 電腦版／手機版');
  ok(D.getElementById('venueLine').textContent === UI.venueLine, '場地行');
  ok(T(D, 'loginScope') === UI.loginScope, '登入頁範圍說明');
  ok(T(D, 'loginHelp').indexOf('連續輸錯 7 次會鎖定 9 分鐘') >= 0 && T(D, 'loginHelp').indexOf(UI.forgotLine) >= 0, '登入頁說明（次數與忘記密碼）', T(D, 'loginHelp'));
  ok(T(D, 'rulesTitle') === UI.rulesTitle && D.querySelectorAll('#rulesList li').length === 3, '規則標題與 3 條規則');
  const ft = T(D, 'ftSys') + '|' + T(D, 'ftConf') + '|' + T(D, 'ftContact') + '|' + T(D, 'ftCredit');
  ok(ft === [UI.systemName, UI.confidentialNote, UI.contactLine, '開源專案：GitHub'].join('|'), '頁尾四段（無組織名、有開源連結）', ft);
  ok(!D.getElementById('ftOrg') && D.querySelector('.pfoot').textContent.indexOf('社團法人') < 0, '頁尾不含組織全名');
  ok(SRC.indexOf('v3.9') > 0 && D.querySelector('.pfoot b').textContent === 'v3.9', '頁尾版本 v3.9');
  ok(SRC.indexOf('社團法人') < 0 && !/\(\d{2,3}\)\s?\d{3,4}-\d{4}/.test(SRC), '原始碼不含組織名稱或電話（都應來自 Config.gs）');
  ok(D.querySelector('#calBody .shift .lab').innerHTML === '早班<small>0800~1600</small>', '月曆班別標籤由 ui 帶入', D.querySelector('#calBody .shift .lab').innerHTML);
  console.log('\n=== 一般藥師');
  ok(!vis(D.getElementById('admCard')), '看不到後臺');
  ok(T(D, 'pwDefault') === '0000' && vis(D.getElementById('pwTip')), '預設密碼提示由 viewer.defaultPw 帶入');
  ok((w.__errs || []).length === 0, '無 JS 錯誤', w.__errs);

  run('super', [], (w2, D2, calls2) => {
    console.log('\n=== 超級管理者');
    ok(vis(D2.getElementById('admCard')), '看得到後臺');
    const subs = [...D2.querySelectorAll('#admCard details.sub[data-perm]')];
    ok(subs.length === 9 && subs.every(vis), '9 個分項全部顯示', subs.map(s => s.getAttribute('data-perm') + (vis(s) ? '' : '✗')));
    // 展開權限授權 → 抓名單
    const grant = subs.find(s => s.getAttribute('data-perm') === 'grant');
    grant.open = true; grant.dispatchEvent(new w2.Event('toggle'));
    setTimeout(() => {
      ok(calls2.some(c => c[0] === 'adminUsers'), '展開分項時抓了 adminUsers');
      const opts = [...D2.querySelectorAll('#permUser option')].map(o => o.textContent);
      ok(opts.length === 2 && opts.join('|').indexOf('陳') < 0, '授權下拉不含超級管理者', opts);
      D2.getElementById('permUser').value = '會務人員'; D2.getElementById('permUser').dispatchEvent(new w2.Event('change'));
      const boxes = [...D2.querySelectorAll('#permBoxes input')];
      ok(boxes.length === 9 && boxes.filter(b => b.checked).map(b => b.value).join() === 'lock', '會務人員目前只勾 lock', boxes.filter(b => b.checked).map(b => b.value));
      boxes.find(b => b.value === 'roster').checked = true;
      click(w2, D2.getElementById('btnPermSave'));
      setTimeout(() => {
        const sp = calls2.find(c => c[0] === 'setPerms');
        ok(sp && sp[1] === '會務人員' && sp[2].join() === 'lock,roster', 'setPerms 送出 lock+roster', sp);
        // 增減人員
        const rl = [...D2.querySelectorAll('#rosterList .rrow')];
        ok(rl.length === 3, '名單 3 列', rl.map(r => r.textContent.replace(/\s+/g, ' ')));
        ok(rl.filter(r => r.querySelector('.rrm')).length === 1, '只有一般藥師有「移除」鈕');
        D2.getElementById('rosterNew').value = '林小華';
        click(w2, D2.getElementById('btnRosterAdd'));
        ok(D2.getElementById('mask').classList.contains('on') && T(D2, 'mTitle') === '加入順位', '加入前跳確認');
        click(w2, D2.getElementById('mOk'));
        setTimeout(() => {
          ok(calls2.some(c => c[0] === 'rosterAdd' && c[1] === '林小華'), 'rosterAdd 已送出');
          // 頁首頁尾文字
          const brand = subs.find(s => s.getAttribute('data-perm') === 'brand');
          brand.open = true; brand.dispatchEvent(new w2.Event('toggle'));
          setTimeout(() => {
            const ins = [...D2.querySelectorAll('#brandForm input[data-k]')];
            ok(ins.length === 3 && ins[0].value === UI.titleDesktop, '頁首頁尾表單載入 3 欄', ins.map(i => i.getAttribute('data-k')));
            ins[1].value = '藥師 新標題';
            click(w2, D2.getElementById('btnBrandSave'));
            setTimeout(() => {
              const sv = calls2.find(c => c[0] === 'saveUiSettings');
              ok(sv && sv[1].titleMobile === '藥師 新標題', 'saveUiSettings 送出新手機標題', sv && sv[1]);
              ok(D2.querySelector('#h1 .mobonly').textContent === '藥師 新標題', '儲存後頁首立即更新');
            }, 120);
          }, 120);
          ok((w2.__errs || []).length === 0, '無 JS 錯誤', w2.__errs);

          run('staff', ['lock', 'roster'], (w3, D3) => {
            console.log('\n=== 會務人員（被授權 lock＋roster）');
            ok(vis(D3.getElementById('admCard')), '看得到後臺');
            const s3 = [...D3.querySelectorAll('#admCard details.sub[data-perm]')].filter(vis).map(s => s.getAttribute('data-perm'));
            ok(s3.join() === 'lock,roster', '只顯示 lock、roster', s3);
            ok(T(D3, 'banner').indexOf('不參與排班') >= 0 && T(D3, 'banner').indexOf('後臺功能') >= 0, '橫幅提示', T(D3, 'banner'));
            const sl = [...D3.querySelectorAll('#calBody .slot')];
            ok(sl.length > 0 && sl.every(b => b.disabled), '格子仍不可點');
            ok(!vis(D3.getElementById('pwTip')), '會務人員不是預設密碼 → 無提示');

            run('staff', [], (w4, D4) => {
              console.log('\n=== 會務人員（無授權）');
              ok(!vis(D4.getElementById('admCard')), '看不到後臺');
              ok(T(D4, 'banner').indexOf('不參與排班') >= 0 && T(D4, 'banner').indexOf('後臺功能') < 0, '橫幅無後臺字樣', T(D4, 'banner'));
              ok((w3.__errs || []).concat(w4.__errs || []).length === 0, '無 JS 錯誤');
              console.log('\n結果：' + (fails ? fails + ' 項失敗 ✗' : '全部通過 ✓'));
              process.exit(fails ? 1 : 0);
            });
          });
        }, 120);
      }, 120);
    }, 150);
  });
});
