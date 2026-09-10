$ErrorActionPreference = 'Stop'

$edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$outputDir = Join-Path $PSScriptRoot 'production_ui_evidence'
$profile = Join-Path $outputDir 'interactive-profile'
$outputPath = Join-Path $outputDir 'interactive-summary.json'
$debugPort = 9231
New-Item -ItemType Directory -Path $profile -Force | Out-Null

$edgeArgs = @(
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--disable-background-networking',
    "--remote-debugging-port=$debugPort",
    "--user-data-dir=$profile",
    '--window-size=1440,1000',
    'about:blank'
)

$edgeProcess = Start-Process -FilePath $edge -ArgumentList $edgeArgs -PassThru
$socket = $null
$nextId = 0

function Send-Cdp {
    param(
        [string]$Method,
        [hashtable]$Params = @{}
    )

    $script:nextId++
    $requestId = $script:nextId
    $payload = @{ id = $requestId; method = $Method; params = $Params } | ConvertTo-Json -Depth 20 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($payload)
    $segment = [ArraySegment[byte]]::new($bytes)
    $socket.SendAsync($segment, [Net.WebSockets.WebSocketMessageType]::Text, $true, [Threading.CancellationToken]::None).GetAwaiter().GetResult()

    while ($true) {
        $buffer = New-Object byte[] 1048576
        $stream = New-Object IO.MemoryStream
        do {
            $receiveSegment = [ArraySegment[byte]]::new($buffer)
            $received = $socket.ReceiveAsync($receiveSegment, [Threading.CancellationToken]::None).GetAwaiter().GetResult()
            if ($received.MessageType -eq [Net.WebSockets.WebSocketMessageType]::Close) {
                throw 'CDP WebSocket closed unexpectedly'
            }
            $stream.Write($buffer, 0, $received.Count)
        } while (-not $received.EndOfMessage)

        $message = [Text.Encoding]::UTF8.GetString($stream.ToArray()) | ConvertFrom-Json
        if ($message.id -eq $requestId) {
            if ($message.error) { throw ("CDP {0}: {1}" -f $Method, $message.error.message) }
            return $message.result
        }
    }
}

function Invoke-JavaScript {
    param([string]$Expression)

    $result = Send-Cdp -Method 'Runtime.evaluate' -Params @{
        expression = $Expression
        awaitPromise = $true
        returnByValue = $true
    }
    return $result.result.value
}

function Navigate-And-Wait {
    param([string]$Url)

    [void](Send-Cdp -Method 'Page.navigate' -Params @{ url = $Url })
    Start-Sleep -Seconds 5
}

try {
    $version = $null
    for ($attempt = 0; $attempt -lt 40 -and $null -eq $version; $attempt++) {
        try {
            $version = Invoke-RestMethod -Uri "http://127.0.0.1:$debugPort/json/version" -TimeoutSec 1
        }
        catch {
            Start-Sleep -Milliseconds 250
        }
    }
    if ($null -eq $version) { throw 'Microsoft Edge remote debugging did not start' }

    $targets = Invoke-RestMethod -Uri "http://127.0.0.1:$debugPort/json/list" -TimeoutSec 3
    $target = $targets | Where-Object { $_.type -eq 'page' } | Select-Object -First 1
    if ($null -eq $target) { throw 'No Edge page target was found' }

    $socket = New-Object Net.WebSockets.ClientWebSocket
    $socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl, [Threading.CancellationToken]::None).GetAwaiter().GetResult()
    [void](Send-Cdp -Method 'Page.enable')
    [void](Send-Cdp -Method 'Runtime.enable')

    Navigate-And-Wait 'https://devtapcode.io.vn/careers'
    $careersDesktop = Invoke-JavaScript @'
({
  href: location.href,
  width: innerWidth,
  scrollWidth: document.documentElement.scrollWidth,
  title: document.title,
  jobs: document.querySelectorAll('.job-card').length,
  detailButtons: document.querySelectorAll('.job-detail').length,
  applyButtons: document.querySelectorAll('.job-apply').length
})
'@

    $detailOpened = Invoke-JavaScript @'
(async () => {
  document.querySelector('.job-detail')?.click();
  await new Promise(resolve => setTimeout(resolve, 700));
  const dialog = document.querySelector('[role="dialog"]');
  return {
    opened: !!dialog,
    text: dialog?.innerText || '',
    scrollable: !!dialog && dialog.scrollHeight >= dialog.clientHeight,
    closeButton: !!dialog?.querySelector('.modal-close')
  };
})()
'@

    [void](Invoke-JavaScript "document.querySelector('[role=dialog] .modal-close')?.click(); true")
    $applicationOpened = Invoke-JavaScript @'
(async () => {
  document.querySelector('.job-apply')?.click();
  await new Promise(resolve => setTimeout(resolve, 700));
  const dialog = document.querySelector('[role="dialog"]');
  const form = dialog?.querySelector('form');
  return {
    opened: !!dialog,
    hasForm: !!form,
    hasName: !!form?.querySelector('input[name="full_name"], input[type="text"]'),
    hasEmail: !!form?.querySelector('input[type="email"]'),
    hasFile: !!form?.querySelector('input[type="file"]'),
    acceptedTypes: form?.querySelector('input[type="file"]')?.accept || '',
    submitText: form?.querySelector('button[type="submit"]')?.innerText || ''
  };
})()
'@

    $emptyApplicationValidation = Invoke-JavaScript @'
(() => {
  const form = document.querySelector('[role="dialog"] form');
  if (!form) return { form: false };
  const valid = form.checkValidity();
  const invalid = [...form.querySelectorAll(':invalid')].map(el => el.name || el.type || el.tagName);
  return { form: true, valid, invalid };
})()
'@

    [void](Send-Cdp -Method 'Emulation.setDeviceMetricsOverride' -Params @{
        width = 320
        height = 900
        deviceScaleFactor = 1
        mobile = $true
    })
    Navigate-And-Wait 'https://devtapcode.io.vn/careers'
    $careersMobile = Invoke-JavaScript @'
({
  href: location.href,
  width: innerWidth,
  scrollWidth: document.documentElement.scrollWidth,
  bodyScrollWidth: document.body.scrollWidth,
  jobs: document.querySelectorAll('.job-card').length,
  navVisible: getComputedStyle(document.querySelector('.careers-nav')).display !== 'none'
})
'@

    [void](Send-Cdp -Method 'Emulation.clearDeviceMetricsOverride')
    Navigate-And-Wait 'https://devtapcode.io.vn/login'
    $login = Invoke-JavaScript @'
({
  href: location.href,
  title: document.title,
  demoButtons: [...document.querySelectorAll('button')]
    .map(button => button.innerText.trim())
    .filter(text => ['Admin', 'Trưởng phòng', 'HR', 'Nhân viên', 'Kế toán', 'Payroll'].includes(text)),
  emailRequired: !!document.querySelector('input[type="email"]:required'),
  passwordRequired: !!document.querySelector('input[type="password"]:required')
})
'@

    Navigate-And-Wait 'https://devtapcode.io.vn/m'
    $mobileAnonymous = Invoke-JavaScript "({ href: location.href, hasLoginForm: !!document.querySelector('input[type=email]') })"

    $employeeQuickLogin = Invoke-JavaScript @'
(async () => {
  const buttons = [...document.querySelectorAll('button[type="button"]')];
  const employeeButton = buttons[buttons.length - 1];
  if (!employeeButton) return { clicked: false, href: location.href };
  employeeButton.click();
  await new Promise(resolve => setTimeout(resolve, 6000));
  return {
    clicked: true,
    href: location.href,
    hasEmployeePortal: document.body.innerText.length > 100,
    hasAdminNav: !!document.querySelector('a[href="/employees"]'),
    bodyText: document.body.innerText.slice(0, 1200)
  };
})()
'@

    [void](Send-Cdp -Method 'Emulation.setDeviceMetricsOverride' -Params @{
        width = 390
        height = 844
        deviceScaleFactor = 1
        mobile = $true
    })
    Navigate-And-Wait 'https://devtapcode.io.vn/employee-portal'
    $employeeMobileRedirect = Invoke-JavaScript "({ href: location.href, width: innerWidth, scrollWidth: document.documentElement.scrollWidth, textLength: document.body.innerText.length })"
    [void](Invoke-JavaScript "localStorage.setItem('prefer_desktop','1'); true")
    Navigate-And-Wait 'https://devtapcode.io.vn/employee-portal'
    $employeePreferDesktop = Invoke-JavaScript "({ href: location.href, width: innerWidth, scrollWidth: document.documentElement.scrollWidth })"
    [void](Invoke-JavaScript "localStorage.removeItem('prefer_desktop'); true")
    [void](Send-Cdp -Method 'Emulation.clearDeviceMetricsOverride')

    $summary = [ordered]@{
        careers_desktop = $careersDesktop
        detail_modal = $detailOpened
        application_modal = $applicationOpened
        application_empty_validation = $emptyApplicationValidation
        careers_mobile_320 = $careersMobile
        login = $login
        mobile_anonymous = $mobileAnonymous
        employee_quick_login = $employeeQuickLogin
        employee_mobile_redirect = $employeeMobileRedirect
        employee_prefer_desktop = $employeePreferDesktop
    }
    [IO.File]::WriteAllText($outputPath, ($summary | ConvertTo-Json -Depth 10), [Text.UTF8Encoding]::new($false))
    $summary | ConvertTo-Json -Depth 10
}
finally {
    if ($null -ne $socket -and $socket.State -eq [Net.WebSockets.WebSocketState]::Open) {
        try { [void](Send-Cdp -Method 'Browser.close') } catch {}
        $socket.Dispose()
    }
    if ($null -ne $edgeProcess -and -not $edgeProcess.HasExited) {
        Stop-Process -Id $edgeProcess.Id -Force -ErrorAction SilentlyContinue
    }
}
