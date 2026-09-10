$ErrorActionPreference = 'Stop'

$edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$outputDir = Join-Path $PSScriptRoot 'production_ui_evidence\recruitment-e2e-20260731'
$profile = Join-Path $outputDir 'profile'
$outputPath = Join-Path $outputDir 'summary.json'
$debugPort = 9238
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
    param(
        [string]$Url,
        [int]$Seconds = 5
    )

    [void](Send-Cdp -Method 'Page.navigate' -Params @{ url = $Url })
    Start-Sleep -Seconds $Seconds
}

function Save-Screenshot {
    param([string]$Name)

    $capture = Send-Cdp -Method 'Page.captureScreenshot' -Params @{ format = 'png'; captureBeyondViewport = $true }
    [IO.File]::WriteAllBytes((Join-Path $outputDir $Name), [Convert]::FromBase64String($capture.data))
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

    Navigate-And-Wait 'https://devtapcode.io.vn/'
    [void](Invoke-JavaScript "localStorage.clear(); true")
    Navigate-And-Wait 'https://devtapcode.io.vn/login'
    $loginResult = Invoke-JavaScript @'
(async () => {
  const button = [...document.querySelectorAll('button')].find(el => el.innerText.trim() === 'HR');
  if (!button) return { clicked: false, href: location.href };
  button.click();
  await new Promise(resolve => setTimeout(resolve, 6000));
  return { clicked: true, href: location.href, hasToken: !!localStorage.getItem('auth_token') };
})()
'@

    Navigate-And-Wait 'https://devtapcode.io.vn/recruitment' 7
    $recruitmentBoard = Invoke-JavaScript @'
(() => {
  const bodyText = document.body.innerText;
  const email = 'vhozang2808+hrm-e2e-20260731@gmail.com';
  const card = [...document.querySelectorAll('.cursor-pointer')].find(el => el.innerText.includes(email));
  return {
    href: location.href,
    candidateVisible: !!card,
    scoreVisible: bodyText.includes('AI: 59.9000%'),
    positionVisible: bodyText.includes('Backend Developer (PHP/Laravel)'),
    cardMissingCvIndicator: !!card?.querySelector('span.text-amber-500'),
    cardText: card?.innerText || '',
    bodyPreview: bodyText.slice(0, 1600)
  };
})()
'@

    $candidateDetail = Invoke-JavaScript @'
(async () => {
  const email = 'vhozang2808+hrm-e2e-20260731@gmail.com';
  const card = [...document.querySelectorAll('.cursor-pointer')].find(el => el.innerText.includes(email));
  card?.click();
  await new Promise(resolve => setTimeout(resolve, 1000));
  const dialog = document.querySelector('[role="dialog"]');
  const text = dialog?.innerText || '';
  return {
    opened: !!dialog,
    hasCandidate: text.includes(email),
    phoneVisible: text.includes('0386260802'),
    missingCvIndicator: !!dialog?.querySelector('span.text-amber-500.font-medium'),
    scoreVisible: text.includes('59.9000%'),
    text
  };
})()
'@
    Save-Screenshot 'recruitment-candidate-detail.png'

    Navigate-And-Wait 'https://devtapcode.io.vn/interviews' 7
    $interviews = Invoke-JavaScript @'
(() => {
  const text = document.body.innerText;
  const meetLinks = [...document.querySelectorAll('a[href*="meet.google.com"]')].map(a => a.href);
  const row = [...document.querySelectorAll('tr')].find(el => el.innerText.includes('Backend Developer (PHP/Laravel)'));
  return {
    href: location.href,
    candidateVisible: !!row,
    intendedDateVisible: text.includes('03/08/2026'),
    shiftedDateVisible: text.includes('02/08/2026'),
    timeVisible: text.includes('09:30'),
    meetLinks,
    rowText: row?.innerText || '',
    bodyPreview: text.slice(0, 1800)
  };
})()
'@
    Save-Screenshot 'interview-list.png'

    Navigate-And-Wait 'https://devtapcode.io.vn/recruitment-positions' 7
    $recruitmentPosts = Invoke-JavaScript @'
(() => ({
  href: location.href,
  backendPostVisible: document.body.innerText.includes('Backend Developer (PHP/Laravel)'),
  text: document.body.innerText.slice(0, 1800)
}))()
'@
    Save-Screenshot 'recruitment-posts-hr.png'

    $summary = [ordered]@{
        login = $loginResult
        recruitment_board = $recruitmentBoard
        candidate_detail = $candidateDetail
        interviews = $interviews
        recruitment_posts = $recruitmentPosts
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
