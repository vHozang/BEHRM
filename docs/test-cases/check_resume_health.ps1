$ProgressPreference = 'SilentlyContinue'
foreach ($ip in @('100.95.129.101', '100.105.84.89')) {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri ("http://{0}:8000/health" -f $ip) -TimeoutSec 5
        Write-Output ("{0} HTTP {1}" -f $ip, [int]$response.StatusCode)
    }
    catch {
        Write-Output ("{0} ERROR {1}" -f $ip, $_.Exception.Message)
    }
}
