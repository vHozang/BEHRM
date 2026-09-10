param(
    [string]$BaseUrl = 'https://devtapcode.io.vn'
)

$ErrorActionPreference = 'Stop'
$edge = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
$outputDir = 'D:\HRM\docs	est-cases\production_ui_evidence'
New-Item -ItemType Directory -Path $outputDir -Force | Out-Null

function Invoke-EdgeProbe {
    param(
        [string]$Name,
        [string]$Url,
        [int]$Width,
        [int]$Height
    )

    $profile = Join-Path $outputDir ("profile-{0}" -f $Name)
    $dom = Join-Path $outputDir ("{0}.html" -f $Name)
    $stderr = Join-Path $outputDir ("{0}.stderr.txt" -f $Name)
    $screenshot = Join-Path $outputDir ("{0}.png" -f $Name)
    New-Item -ItemType Directory -Path $profile -Force | Out-Null

    $arguments = @(
        '--headless=new',
        '--disable-gpu',
        '--no-first-run',
        '--disable-background-networking',
        "--user-data-dir=$profile",
        "--window-size=$Width,$Height",
        '--virtual-time-budget=7000',
        "--screenshot=$screenshot",
        '--dump-dom',
        $Url
    )

    $process = Start-Process -FilePath $edge -ArgumentList $arguments `
        -RedirectStandardOutput $dom -RedirectStandardError $stderr -PassThru
    if (-not $process.WaitForExit(30000)) {
        $process.Kill()
        throw "Edge probe timed out: $Name"
    }

    [ordered]@{
        name = $Name
        url = $Url
        exit_code = $process.ExitCode
        dom_bytes = (Get-Item $dom).Length
        screenshot_bytes = if (Test-Path $screenshot) { (Get-Item $screenshot).Length } else { 0 }
        stderr_bytes = (Get-Item $stderr).Length
    }
}

$results = @(
    Invoke-EdgeProbe -Name 'home-desktop' -Url "$BaseUrl/" -Width 1440 -Height 1000
    Invoke-EdgeProbe -Name 'login-desktop' -Url "$BaseUrl/login" -Width 1440 -Height 1000
    Invoke-EdgeProbe -Name 'careers-desktop' -Url "$BaseUrl/careers" -Width 1440 -Height 1200
    Invoke-EdgeProbe -Name 'careers-mobile-320' -Url "$BaseUrl/careers" -Width 320 -Height 900
    Invoke-EdgeProbe -Name 'mobile-unauthenticated' -Url "$BaseUrl/m" -Width 390 -Height 844
)

$jsonPath = Join-Path $outputDir 'summary.json'
[IO.File]::WriteAllText($jsonPath, ($results | ConvertTo-Json -Depth 4), [Text.UTF8Encoding]::new($false))
$results | Format-Table -AutoSize
