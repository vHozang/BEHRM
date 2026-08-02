$ErrorActionPreference = 'Stop'

$installDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$config = Get-Content (Join-Path $installDir 'config.json') -Raw | ConvertFrom-Json
$token = (Get-Content (Join-Path $installDir 'device-token.txt') -Raw).Trim()
$logFile = Join-Path $installDir 'bridge.log'

$env:DEVICE_IP = $config.device_ip
$env:DEVICE_PORT = [string]$config.device_port
$env:API_BASE = $config.api_base
$env:DEVICE_TOKEN = $token
$env:DEVICE_ID = $config.device_id
$env:POLL_MS = [string]$config.poll_ms
$env:INITIAL_SYNC_MODE = 'latest'
$env:STATE_FILE = Join-Path $installDir 'state.json'

while ($true) {
    try {
        & $config.node_path (Join-Path $installDir 'bridge.js') *>> $logFile
    } catch {
        Add-Content -Path $logFile -Value "$(Get-Date -Format o) bridge stopped: $($_.Exception.Message)"
    }
    Start-Sleep -Seconds 10
}
