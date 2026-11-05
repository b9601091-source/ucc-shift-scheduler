<#
  UCC 排班系統 — 設定 LINE／Google 快速登入的金鑰（寫到 php\ucc\lib\sso_keys.php，不進版本庫；deploy_ucc.ps1 會一起上傳）
  用法（在 PowerShell 執行，密鑰用隱藏輸入，不會留在畫面或歷史）：
    powershell -ExecutionPolicy Bypass -File "C:\Users\USER\我的雲端硬碟\claude\20260812 UCC排班網頁\php\set_sso_keys.ps1"
  要關掉某一種登入：對應欄位直接按 Enter 留空即可（登入頁就不會出現那顆按鈕）。
#>
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Out  = Join-Path $Root 'ucc\lib\sso_keys.php'
$cur = @{ line_channel_id = ''; line_channel_secret = ''; google_client_id = '' }
if (Test-Path $Out) {
  $raw = Get-Content -Raw -Encoding UTF8 $Out
  foreach ($k in @('line_channel_id', 'line_channel_secret', 'google_client_id')) {
    $m = [regex]::Match($raw, "'$k'\s*=>\s*'([^']*)'"); if ($m.Success) { $cur[$k] = $m.Groups[1].Value }
  }
  Write-Output "（已有設定檔：LINE Channel ID $(if($cur.line_channel_id){'已填'}else{'空'})、LINE secret $(if($cur.line_channel_secret){'已填'}else{'空'})、Google client ID $(if($cur.google_client_id){'已填'}else{'空'})；直接按 Enter＝保留原值，輸入 - ＝清空）"
}
function Ask($label, $old) {
  $v = Read-Host "$label"
  if ($v -eq '') { return $old }
  if ($v -eq '-') { return '' }
  return $v.Trim()
}
function AskSecret($label, $old) {
  $s = Read-Host "$label" -AsSecureString
  $p = [Runtime.InteropServices.Marshal]::PtrToStringUni([Runtime.InteropServices.Marshal]::SecureStringToGlobalAllocUnicode($s))
  if ($p -eq '') { return $old }
  if ($p -eq '-') { return '' }
  if ($p -match '[^\x21-\x7E]') { throw '密鑰含非 ASCII 字元，可能貼錯（含中文或空白）' }
  $s2 = Read-Host "再輸入一次確認" -AsSecureString
  $p2 = [Runtime.InteropServices.Marshal]::PtrToStringUni([Runtime.InteropServices.Marshal]::SecureStringToGlobalAllocUnicode($s2))
  if ($p -ne $p2) { throw '兩次輸入不一致' }
  return $p.Trim()
}
$lineId  = Ask 'LINE Login Channel ID（數字；不用就留空）' $cur.line_channel_id
$lineSec = if ($lineId -ne '') { AskSecret 'LINE Channel secret（隱藏輸入）' $cur.line_channel_secret } else { '' }
$gId     = Ask 'Google OAuth 用戶端 ID（…apps.googleusercontent.com；不用就留空）' $cur.google_client_id
foreach ($pair in @(@('LINE Channel ID', $lineId), @('Google client ID', $gId))) { if ($pair[1] -match "['\\<>]") { throw "$($pair[0]) 含不合法字元" } }
$php = "<?php`n// 快速登入金鑰（set_sso_keys.ps1 產生，勿進版本庫）`nreturn ['line_channel_id' => '$lineId', 'line_channel_secret' => '$lineSec', 'google_client_id' => '$gId'];`n"
[IO.File]::WriteAllText($Out, $php, (New-Object System.Text.UTF8Encoding($false)))
Write-Output "已寫入 $Out"
Write-Output ("開通：" + $(if ($lineId -and $lineSec) { 'LINE ' } else { '' }) + $(if ($gId) { 'Google' } else { '' }) + $(if (-not ($lineId -and $lineSec) -and -not $gId) { '（都沒有）' } else { '' }))
Write-Output '接著跑 deploy_ucc.ps1 部署即可。'
