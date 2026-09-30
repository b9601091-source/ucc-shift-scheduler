# -*- coding: utf-8 -*-
"""從正式版（Apps Script）Index.html 產生 PHP 版 index.html。
   兩版畫面完全相同，差別只有：api() 改走 fetch（含尖峰排隊重試）、試用提示列、後臺多「資料備份與搬移」、班表多「匯出 Excel」、
   版次與更新紀錄改由後端 config.php 提供。
   用法（在 repo 根目錄）：python php/tools/build_index.py   → 寫出 php/ucc/index.html"""
import io, os
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SRC = os.path.join(ROOT, 'src', 'Index.html')
OUT = os.path.join(ROOT, 'php', 'ucc', 'index.html')
s = io.open(SRC, encoding='utf-8').read()

def rep(a, b):
    global s
    assert a in s, ('ANCHOR', a[:70])
    s = s.replace(a, b, 1)

import re
assert re.search(r'<!-- UCC 排班系統 — 前端（上線版）v[\d.]+', s), 'header anchor'
s = re.sub(r'<!-- UCC 排班系統 — 前端（上線版）v[\d.]+',
           '<!-- UCC 排班系統 — 前端【PHP 版】（由 php/tools/build_index.py 從 Apps Script 版 Index.html 產生，不要手改這份）', s, count=1)

# ---------- 後端橋接：fetch ＋ 尖峰排隊重試 ----------
rep("""var LIVE = (typeof google !== 'undefined' && google.script && google.script.run);
function api(fn, arg){
  return new Promise(function(res, rej){
    if(!LIVE){ rej(new Error('本頁需透過 Apps Script 部署後的網址開啟')); return; }
    var run = google.script.run.withSuccessHandler(res).withFailureHandler(function(e){ rej(e); });
    // ⚠ google.script.run 不接受 undefined 當參數（會在瀏覽器端就被擋掉、請求根本送不出去），
    //    所以沒有參數的函式（getBootstrap）必須用不帶引數的方式呼叫。
    if(arg === undefined) run[fn](); else run[fn](arg);
  });
}""",
"""var LIVE = true;
/* 呼叫後端（api.php）。共享主機同時能跑的 PHP 程序有限（常見約 30 支），尖峰時會回 508／507／503，
   這種情況自動排隊重試（指數退避＋隨機，最多約 20 秒），使用者只看到「排隊處理中」。
   回傳值＝後端函式的結果本身，形狀與 Apps Script 版完全相同。 */
var BUSY_N = 0;
function busyNote(on){
  BUSY_N += on ? 1 : -1; var el = document.getElementById('busyNote');
  if(BUSY_N > 0 && !el){ el = document.createElement('div'); el.id = 'busyNote'; el.textContent = '目前使用人數較多，排隊處理中，請稍候…';
    el.style.cssText = 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);background:#b54708;color:#fff;padding:8px 16px;border-radius:20px;z-index:3000;font-size:15px;box-shadow:0 2px 8px rgba(0,0,0,.2)'; document.body.appendChild(el); }
  if(BUSY_N <= 0){ BUSY_N = 0; if(el) el.remove(); }
}
function api(fn, arg){
  var body = JSON.stringify({fn:fn, arg:(arg === undefined ? {} : arg)}), tries = 0, noted = false;
  var done = function(){ if(noted){ noted = false; busyNote(false); } };
  var attempt = function(){
    tries++;
    var ctl = window.AbortController ? new AbortController() : null, timer = ctl ? setTimeout(function(){ ctl.abort(); }, 60000) : null;
    return fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:body, credentials:'same-origin', cache:'no-store', signal: ctl ? ctl.signal : undefined})
      .then(function(r){ if(timer) clearTimeout(timer); return r.text().then(function(t){ var j = null; try{ j = JSON.parse(t); }catch(e){} return {status:r.status, j:j}; }); },
            function(e){ if(timer) clearTimeout(timer); throw new Error(e && e.name === 'AbortError' ? '連線逾時，請檢查網路後再試' : '無法連線到伺服器，請檢查網路後再試'); })
      .then(function(x){
        var busy = (!x.j && [503, 507, 508].indexOf(x.status) >= 0) || (x.j && x.j.ok === false && x.j.msg === '系統忙碌中，請稍候幾秒再試一次');
        if(busy && tries < 10){
          if(tries >= 2 && !noted){ noted = true; busyNote(true); }
          var wait = Math.min(3000, 250 * Math.pow(1.6, tries - 1)) * (0.5 + Math.random());
          return new Promise(function(res){ setTimeout(res, wait); }).then(attempt);
        }
        if(!x.j) throw new Error(busy ? '目前使用人數過多，請稍候一分鐘再試' : '伺服器回應異常（HTTP ' + x.status + '），請稍後再試');
        return x.j;
      });
  };
  return attempt().then(function(d){ done(); return d; }, function(e){ done(); throw e; });
}
/* 把後端給的檔案（base64）交給瀏覽器下載 */
function saveFile(name, base64, mime){
  var bin = atob(base64), u8 = new Uint8Array(bin.length);
  for(var i=0;i<bin.length;i++) u8[i] = bin.charCodeAt(i);
  var blob = new Blob([u8], {type: mime || 'application/octet-stream'}), a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click();
  setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 2000);
}
function saveText(name, text, mime){
  var blob = new Blob([text], {type: mime || 'application/json;charset=utf-8'}), a = document.createElement('a');
  a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click();
  setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 2000);
}""")

# ---------- 提示列：改成試用說明（由後端 PILOT_NOTE 決定） ----------
assert re.search(r'<div id="demoBar" class="demo" style="display:none">[\s\S]*?</div>', s), 'demoBar anchor'
s = re.sub(r'<div id="demoBar" class="demo" style="display:none">[\s\S]*?</div>', '<div id="demoBar" class="demo" style="display:none"></div>', s, count=1)
rep("""function boot(){
  $('lastUpd').textContent = CHANGELOG.length ? CHANGELOG[0].d : '';
  if(!LIVE){ $('demoBar').style.display=''; return; }""",
    """function boot(){
  $('lastUpd').textContent = CHANGELOG.length ? CHANGELOG[0].d : '';""")

# ---------- 版次與更新紀錄由後端提供 ----------
assert re.search(r'<span id="ftSys">排班系統</span>　<b>v[\d.]+</b><br>', s), 'footer version anchor'
s = re.sub(r'<span id="ftSys">排班系統</span>　<b>v[\d.]+</b><br>', '<span id="ftSys">排班系統</span>　<b id="ftVer">—</b><br>', s, count=1)
rep("""  $('ftCredit').innerHTML = ui.creditHtml ? (ui.contactLine ? '<br>' : '') + ui.creditHtml : '';
}""",
    """  $('ftCredit').innerHTML = ui.creditHtml ? (ui.contactLine ? '<br>' : '') + ui.creditHtml : '';
  if(ui.appVersion) $('ftVer').textContent = 'v' + ui.appVersion;
  if(ui.changelog && ui.changelog.length){
    CHANGELOG = ui.changelog.map(function(c){ return {d:String(c.date||'').replace(/-/g,'/'), v:'v'+c.ver, t:c.title, s:c.text}; });
    $('lastUpd').textContent = CHANGELOG[0].d;
  }
  var pb = $('demoBar');
  if(ui.pilotNote){ pb.innerHTML = ui.pilotNote; pb.style.display=''; } else pb.style.display='none';
}""")

# ---------- 先拿掉 Apps Script 版的「資料備份」分項與其處理程式（PHP 版換成下載檔案＋匯入），要在加入 PHP 版處理程式之前做 ----------
gas_block_start = s.index("""            <details class="sub" data-perm="grant"><summary>資料備份</summary>""")
gas_block_end = s.index("""            <details class="sub" data-perm="grant"><summary>權限授權</summary>""")
s = s[:gas_block_start] + s[gas_block_end:]
h0 = s.index("$('btnExportJson').onclick=function(){")
h1 = s.index("$('permUser').onchange=renderPermBoxes;")
assert 'expText' in s[h0:h1] and h1 - h0 < 2000, 'GAS export handler block'
s = s[:h0] + s[h1:]
assert s.count("$('btnExportJson').onclick") == 0

# ---------- 班表標題列：匯出 Excel ----------
rep("""            <button class="btn ghost" id="btnReload" style="font-size:12.5px;padding:5px 11px">重新整理</button>""",
    """            <button class="btn ghost" id="btnReload" style="font-size:12.5px;padding:5px 11px">重新整理</button>
            <button class="btn ghost" id="btnXlsx" style="font-size:12.5px;padding:5px 11px" title="下載這個月的班表（Excel）">Excel</button>""")
rep("""$('btnReload').onclick=function(){ loadMonth(S.month); };""",
    """$('btnReload').onclick=function(){ loadMonth(S.month); };
$('btnXlsx').onclick=function(){
  busy(true);
  api('exportXlsx', {token:S.token, month:S.month}).then(function(r){
    busy(false);
    if(!r || !r.ok){ if(r&&r.needLogin){ showLogin(r.msg); return; } toast(r&&r.msg?r.msg:'匯出失敗'); return; }
    saveFile(r.filename, r.base64, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); toast('已下載 ' + r.filename);
  }).catch(function(e){ busy(false); toast('連線失敗：'+(e&&e.message?e.message:e)); });
};
$('btnExportJson').onclick=function(){
  busy(true);
  api('exportAll', {token:S.token}).then(function(r){
    busy(false);
    if(!r || !r.ok){ toast(r&&r.msg?r.msg:'匯出失敗'); return; }
    var name = 'ucc-export-' + (S.boot && S.boot.today ? S.boot.today : 'now') + '.json';
    saveText(name, JSON.stringify(r.data, null, 1)); toast('已下載 ' + name);
  }).catch(function(e){ busy(false); toast('連線失敗：'+(e&&e.message?e.message:e)); });
};
$('impFile').onchange=function(){
  var f=this.files && this.files[0]; if(!f) return;
  var rd=new FileReader(); rd.onload=function(){ $('impText').value = String(rd.result||''); }; rd.readAsText(f, 'utf-8');
};
$('btnImport').onclick=function(){
  var txt=$('impText').value.trim(); if(!txt){ toast('請先貼上或選擇匯出的 JSON'); return; }
  var obj=null; try{ obj=JSON.parse(txt); }catch(e){ toast('JSON 解析失敗：'+e.message); return; }
  var rep=$('impReplace').checked;
  modal('整批搬移', '<p>把匯出的資料整批載入這個系統' + (rep ? '，<b>並取代現有的全部資料</b>' : '') + '。</p>'+
    '<p style="font-size:13px;color:var(--sub)">月份、班表、順位、注意事項、可排班日、密碼與授權都會一起搬過來；原密碼可直接登入。</p>',
    '確定匯入', function(){
      busy(true);
      api('importAll', {token:S.token, data:obj, replace:rep}).then(function(r){
        busy(false); toast(r&&r.msg?r.msg:'完成');
        if(r&&r.ok){ $('impText').value=''; S.cache={}; boot(); }
      }).catch(function(e){ busy(false); toast('連線失敗：'+(e&&e.message?e.message:e)); });
    });
};""")

# ---------- 後臺：Apps Script 版的「資料備份」換成「資料備份與搬移」（多了匯入）；HTML 與處理程式都已在前面移除 ----------
rep("""            <details class="sub" data-perm="grant"><summary>權限授權</summary>""",
    """            <details class="sub" data-perm="grant"><summary>資料備份與搬移</summary><div class="subbody">
              <div class="row">
                <button class="btn ghost" id="btnExportJson">匯出全部資料（JSON 備份）</button>
              </div>
              <div class="hint">含月份、班表、順位、注意事項、可排班日、密碼雜湊與授權。請妥善保管，這份檔案可以完整還原系統。</div>
              <label class="lb" style="margin-top:12px">整批搬移：貼上或選擇 Apps Script 版／本系統匯出的 JSON</label>
              <input type="file" id="impFile" accept=".json,application/json" style="font-size:13px">
              <textarea id="impText" rows="3" style="width:100%;margin-top:6px;font-size:12px" placeholder='{"format":"ucc-export-1", ...}'></textarea>
              <div class="row" style="margin-top:8px">
                <label style="font-size:13px"><input type="checkbox" id="impReplace"> 取代現有資料（已有月份時必須勾）</label>
                <button class="btn" id="btnImport">開始匯入</button>
              </div>
            </div></details>

            <details class="sub" data-perm="grant"><summary>權限授權</summary>""")

# ---------- 還沒有任何月份時（剛裝好、尚未搬移）：照常進登入頁，超級管理者登入後可在後臺匯入 ----------
rep("""    if(!b.months || !b.months.length){
      var extra = '';""",
    """    if(!b.months || !b.months.length){
      b.months = [];
      $('banner').className='banner err';
      $('banner').innerHTML='<div><b>尚未有任何月份資料</b><br><span style="font-size:13.5px">超級管理者登入後，到最下方「後臺管理 → 資料備份與搬移」把 Apps Script 版匯出的 JSON 貼上匯入。</span></div>';
      $('banner').style.display='';
    }
    if(false){
      var extra = '';""")

rep("""    if(!want || !b.months.some(function(m){ return m.name===want; })) want = b.months[0].name;""",
    """    if(!want || !b.months.some(function(m){ return m.name===want; })) want = b.months.length ? b.months[0].name : '';""")

# 尚無月份時統計列沒意義，收起來
rep("""  var hideMeta = !!(S.lock && S.lock.locked);""", """  var hideMeta = !!(S.lock && S.lock.locked) || !!d.empty;""")

io.open(OUT, 'w', encoding='utf-8').write(s)
print('已產生', OUT, len(s.encode('utf-8')), 'bytes')
for bad in ['google.script', 'Apps Script 部署後的網址']:
    assert bad not in s, ('LEFTOVER', bad)
print('檢查通過：無 Apps Script 殘留')
