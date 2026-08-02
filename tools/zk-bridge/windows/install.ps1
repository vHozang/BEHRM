#requires -version 5.1
$ErrorActionPreference = 'Stop'

$packageDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$installDir = 'C:\ProgramData\HRM-ZK-Bridge'
$taskName = 'HRM-ZK-Bridge'
$apiBase = 'https://devtapcode.io.vn/api/v1'
$devicePort = 4370

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-Administrator)) {
    Write-Host 'Dang mo lai bo cai voi quyen Administrator...'
    $arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$($MyInvocation.MyCommand.Path)`""
    Start-Process powershell.exe -Verb RunAs -ArgumentList $arguments
    exit
}

function Test-ZkPort([string]$address) {
    if (-not $address) { return $false }
    return Test-NetConnection -ComputerName $address -Port $devicePort `
        -InformationLevel Quiet -WarningAction SilentlyContinue
}

Write-Host '=== HRM attendance bridge installer ===' -ForegroundColor Cyan

$tokenFile = Join-Path $packageDir 'device-token.txt'
$deviceToken = ''
if (Test-Path $tokenFile) {
    $deviceToken = (Get-Content $tokenFile -Raw).Trim()
} else {
    Write-Warning 'Khong co device-token.txt vi token production khong duoc luu tren GitHub.'
    $secureToken = Read-Host 'Nhap device token cua may (bat dau bang dev_)' -AsSecureString
    $tokenPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
    try {
        $deviceToken = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($tokenPointer).Trim()
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($tokenPointer)
    }
}
if (-not $deviceToken.StartsWith('dev_')) {
    throw 'Device token khong hop le. Hay dung bo cai production hoac lay token tai man hinh May cham cong.'
}

$localAddresses = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike '127.*' } |
    Select-Object -ExpandProperty IPAddress -Unique)

Write-Host 'IPv4 tren laptop:'
$localAddresses | ForEach-Object { Write-Host "  $_" }

$configuredIp = ''
$configuredIpFile = Join-Path $packageDir 'device-ip.txt'
if (Test-Path $configuredIpFile) {
    $configuredIp = (Get-Content $configuredIpFile -Raw).Trim()
}

$candidateAddresses = New-Object System.Collections.Generic.List[string]
if ($configuredIp -and $localAddresses -notcontains $configuredIp) {
    $candidateAddresses.Add($configuredIp)
} elseif ($configuredIp) {
    Write-Warning "$configuredIp la IP cua laptop, khong phai IP may cham cong."
}

$ethernetInterfaces = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object {
        $_.IPAddress -notlike '127.*' -and
        $_.InterfaceAlias -notmatch 'Tailscale|Wi-Fi|Wireless|Loopback'
    } | Select-Object -ExpandProperty InterfaceIndex -Unique)

if ($ethernetInterfaces.Count -gt 0) {
    Get-NetNeighbor -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object {
            $ethernetInterfaces -contains $_.InterfaceIndex -and
            $_.State -notin @('Unreachable', 'Incomplete') -and
            $_.IPAddress -notlike '224.*' -and
            $_.IPAddress -notlike '255.*' -and
            $localAddresses -notcontains $_.IPAddress
        } | Select-Object -ExpandProperty IPAddress -Unique |
        ForEach-Object { if (-not $candidateAddresses.Contains($_)) { $candidateAddresses.Add($_) } }
}

$reachable = @()
foreach ($address in $candidateAddresses) {
    Write-Host "Kiem tra $address`:$devicePort..."
    if (Test-ZkPort $address) { $reachable += $address }
}

if ($reachable.Count -eq 1) {
    $deviceIp = $reachable[0]
    Write-Host "Da tim thay may cham cong tai $deviceIp`:$devicePort" -ForegroundColor Green
} else {
    if ($reachable.Count -gt 1) {
        Write-Host "Co nhieu thiet bi mo cong $devicePort`: $($reachable -join ', ')"
    } else {
        Write-Warning "Chua tim thay thiet bi mo TCP $devicePort tren cac dia chi ARP hien co."
        Write-Host 'Hay xem IP tren may: Menu -> Comm/Network -> TCP/IP.'
    }
    $deviceIp = (Read-Host 'Nhap IP that cua may cham cong').Trim()
    if (-not $deviceIp) { throw 'Chua co IP may cham cong.' }
    if ($localAddresses -contains $deviceIp) {
        throw "$deviceIp la IP cua laptop. Can nhap IP hien tren man hinh may cham cong."
    }
    if (-not (Test-ZkPort $deviceIp)) {
        throw "Khong ket noi duoc $deviceIp`:$devicePort. Kiem tra cap mang, subnet va cong TCP tren may."
    }
}

Write-Host 'Kiem tra HRM API va device token...'
$headers = @{ 'x-device-token' = $deviceToken }
$body = @{ punches = @() } | ConvertTo-Json -Compress
Invoke-RestMethod -Method Post -Uri "$apiBase/internal/attendance/device-punch" `
    -Headers $headers -ContentType 'application/json' -Body $body | Out-Null
Write-Host 'VPS da chap nhan device token.' -ForegroundColor Green

$nodeCommand = Get-Command node.exe -ErrorAction SilentlyContinue
if (-not $nodeCommand) {
    $winget = Get-Command winget.exe -ErrorAction SilentlyContinue
    if (-not $winget) {
        throw 'May chua co Node.js 18+ va khong tim thay winget de cai tu dong.'
    }
    Write-Host 'Dang cai Node.js LTS...'
    & $winget.Source install OpenJS.NodeJS.LTS --accept-package-agreements --accept-source-agreements --silent
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' +
        [Environment]::GetEnvironmentVariable('Path', 'User')
    $nodeCommand = Get-Command node.exe -ErrorAction SilentlyContinue
}
if (-not $nodeCommand) { throw 'Khong tim thay node.exe sau khi cai dat.' }

$nodeMajor = [int]((& $nodeCommand.Source --version).TrimStart('v').Split('.')[0])
if ($nodeMajor -lt 18) { throw 'Bridge can Node.js 18 tro len.' }

New-Item -ItemType Directory -Path $installDir -Force | Out-Null
Copy-Item (Join-Path $packageDir '..\bridge.js') $installDir -Force
Copy-Item (Join-Path $packageDir '..\package.json') $installDir -Force
Copy-Item (Join-Path $packageDir '..\package-lock.json') $installDir -Force
Copy-Item (Join-Path $packageDir 'run-bridge.ps1') $installDir -Force
Set-Content (Join-Path $installDir 'device-token.txt') -Value $deviceToken -Encoding ASCII

$config = @{
    device_ip = $deviceIp
    device_port = $devicePort
    api_base = $apiBase
    device_id = 'bill-huynh-attendance'
    poll_ms = 30000
    node_path = $nodeCommand.Source
}
$config | ConvertTo-Json | Set-Content (Join-Path $installDir 'config.json') -Encoding UTF8

Write-Host 'Dang cai thu vien bridge...'
$npmCommand = Get-Command npm.cmd -ErrorAction Stop
Push-Location $installDir
try {
    & $npmCommand.Source ci --omit=dev --no-audit --no-fund
    if ($LASTEXITCODE -ne 0) { throw 'npm ci that bai.' }
} finally {
    Pop-Location
}

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument `
    "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$installDir\run-bridge.ps1`""
$trigger = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 10 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger `
    -Principal $principal -Settings $settings -Force | Out-Null
Start-ScheduledTask -TaskName $taskName

Write-Host 'Da cai bridge. Dang cho lan ket noi dau tien...'
Start-Sleep -Seconds 15
$logFile = Join-Path $installDir 'bridge.log'
if (Test-Path $logFile) {
    Get-Content $logFile -Tail 12
}
Write-Host "Hoan tat. Task: $taskName; log: $logFile" -ForegroundColor Green
