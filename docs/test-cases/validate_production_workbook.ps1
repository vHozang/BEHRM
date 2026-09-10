$ErrorActionPreference = 'Stop'

$path = 'D:\HRM\docs	est-cases\TestCase_HRM_System_Production.xlsx'
$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false

try {
    $workbook = $excel.Workbooks.Open($path, 0, $true)
    $sheetNames = @($workbook.Worksheets | ForEach-Object { $_.Name })
    $cases = $workbook.Worksheets.Item('HRM Test Cases')
    $seen = 0
    $missingActual = 0
    $missingDate = 0
    $missingNote = 0
    $counts = @{ Pass = 0; Fail = 0; Pending = 0 }

    for ($row = 10; $row -le $cases.UsedRange.Rows.Count; $row++) {
        $id = [string]$cases.Cells.Item($row, 1).Value2
        if (-not $id.StartsWith('TC')) { continue }
        $seen++
        $actual = [string]$cases.Cells.Item($row, 6).Value2
        $testDate = $cases.Cells.Item($row, 7).Value2
        $result = [string]$cases.Cells.Item($row, 8).Value2
        $note = [string]$cases.Cells.Item($row, 9).Value2
        if (-not $actual) { $missingActual++ }
        if (-not $testDate) { $missingDate++ }
        if (-not $note) { $missingNote++ }
        if ($counts.ContainsKey($result)) { $counts[$result]++ }
    }

    $links = $workbook.LinkSources(1)
    $linkCount = if ($null -eq $links) { 0 } else { @($links).Count }
    Write-Output ("Sheets: {0}" -f ($sheetNames -join ', '))
    Write-Output ("Cases={0}; Pass={1}; Fail={2}; Pending={3}" -f $seen, $counts.Pass, $counts.Fail, $counts.Pending)
    Write-Output ("Missing actual/date/note={0}/{1}/{2}; external links={3}" -f $missingActual, $missingDate, $missingNote, $linkCount)

    if ($seen -ne 455 -or $counts.Pass -ne 175 -or $counts.Fail -ne 22 -or $counts.Pending -ne 258 -or $missingActual -ne 0 -or $missingDate -ne 0 -or $missingNote -ne 0 -or $linkCount -ne 0) {
        throw 'Workbook validation failed'
    }
}
finally {
    if ($null -ne $workbook) { $workbook.Close($false) }
    $excel.Quit()
    if ($null -ne $cases) { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($cases) }
    if ($null -ne $workbook) { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($workbook) }
    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($excel)
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
