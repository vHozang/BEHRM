$ErrorActionPreference = 'Stop'

$sourcePath = 'D:\HRM\docs	est-cases\TestCase_HRM_System.xlsx'
$outputPath = 'D:\HRM\docs	est-cases\TestCase_HRM_System_Production.xlsx'
$resultsPath = 'D:\HRM\docs	est-cases\production_results.json'
$runUrl = 'https://github.com/vHozang/BEHRM/actions/runs/30552435520'
$commitSha = '77a8833b7e00922a75cde2caf8963cb794741f59'

Copy-Item -LiteralPath $sourcePath -Destination $outputPath -Force
$results = Get-Content -LiteralPath $resultsPath -Raw -Encoding UTF8 | ConvertFrom-Json
$resultById = @{}
foreach ($result in $results) { $resultById[$result.id] = $result }

$passCount = @($results | Where-Object { $_.result -eq 'Pass' }).Count
$failCount = @($results | Where-Object { $_.result -eq 'Fail' }).Count
$pendingCount = @($results | Where-Object { $_.result -eq 'Pending' }).Count

$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false

try {
    $workbook = $excel.Workbooks.Open($outputPath)
    $casesSheet = $workbook.Worksheets.Item('HRM Test Cases')

    for ($row = 10; $row -le $casesSheet.UsedRange.Rows.Count; $row++) {
        $id = [string]$casesSheet.Cells.Item($row, 1).Value2
        if (-not $resultById.ContainsKey($id)) { continue }
        $result = $resultById[$id]
        $casesSheet.Cells.Item($row, 6).Value2 = [string]$result.actual
        $casesSheet.Cells.Item($row, 7).Value2 = [DateTime]::ParseExact($result.test_date, 'yyyy-MM-dd', $null)
        $casesSheet.Cells.Item($row, 7).NumberFormat = 'dd/mm/yyyy'
        $casesSheet.Cells.Item($row, 8).Value2 = [string]$result.result
        $casesSheet.Cells.Item($row, 9).Value2 = [string]$result.note
        $casesSheet.Range("F$row:I$row").WrapText = $true

        $resultCell = $casesSheet.Cells.Item($row, 8)
        switch ($result.result) {
            'Pass' {
                $resultCell.Interior.Color = 13561798
                $resultCell.Font.Color = 32768
            }
            'Fail' {
                $resultCell.Interior.Color = 13551615
                $resultCell.Font.Color = 192
            }
            default {
                $resultCell.Interior.Color = 13434879
                $resultCell.Font.Color = 10053120
            }
        }
    }

    # Keep the visible counters correct even if the user's Excel opens in manual calculation mode.
    $casesSheet.Cells.Item(6, 2).Value2 = [double]$passCount
    $casesSheet.Cells.Item(6, 4).Value2 = [double]$pendingCount
    $casesSheet.Cells.Item(7, 2).Value2 = [double]$failCount
    $casesSheet.Cells.Item(7, 4).Value2 = [double]$results.Count

    $reportSheet = $workbook.Worksheets.Item('Test Report')
    # Locate the module summary row by its total test-case value/label rather than relying on formatting.
    for ($row = 1; $row -le $reportSheet.UsedRange.Rows.Count; $row++) {
        $rowValues = 1..$reportSheet.UsedRange.Columns.Count | ForEach-Object { [string]$reportSheet.Cells.Item($row, $_).Text }
        if (($rowValues -join ' ') -match 'HRM - Functional') {
            $reportSheet.Cells.Item($row, 4).Value2 = [double]$passCount
            $reportSheet.Cells.Item($row, 5).Value2 = [double]$failCount
            $reportSheet.Cells.Item($row, 6).Value2 = [double]$pendingCount
            $reportSheet.Cells.Item($row, 7).Value2 = [double]$results.Count
        }
    }

    foreach ($sheet in @($workbook.Worksheets)) {
        if ($sheet.Name -eq 'Production Summary') {
            $sheet.Delete()
            break
        }
    }

    $summary = $workbook.Worksheets.Add()
    $summary.Name = 'Production Summary'
    $summary.Tab.Color = 5287936

    $summary.Range('A1:F1').Merge()
    $summary.Range('A1').Value2 = 'PRODUCTION TEST EXECUTION SUMMARY'
    $summary.Range('A1').Font.Bold = $true
    $summary.Range('A1').Font.Size = 18
    $summary.Range('A1').Interior.Color = 26367
    $summary.Range('A1').Font.Color = 16777215
    $summary.Range('A1').HorizontalAlignment = -4108

    $summary.Range('A3').Value2 = 'Website'
    $summary.Range('B3').Value2 = 'https://devtapcode.io.vn/'
    $summary.Range('A4').Value2 = 'Deploy workflow'
    $summary.Range('B4').NumberFormat = '@'
    $summary.Range('B4').Value2 = [string]$runUrl
    $summary.Range('A5').Value2 = 'Production commit'
    $summary.Range('B5').NumberFormat = '@'
    $summary.Range('B5').Value2 = [string]$commitSha
    $summary.Range('A6').Value2 = 'Execution date'
    $summary.Range('B6').Value2 = [DateTime]'2026-07-30'
    $summary.Range('B6').NumberFormat = 'dd/mm/yyyy'
    $summary.Range('A3:A6').Font.Bold = $true

    $summary.Range('D3').Value2 = 'Pass'
    $summary.Range('E3').Value2 = [double]$passCount
    $summary.Range('D4').Value2 = 'Fail'
    $summary.Range('E4').Value2 = [double]$failCount
    $summary.Range('D5').Value2 = 'Pending'
    $summary.Range('E5').Value2 = [double]$pendingCount
    $summary.Range('D6').Value2 = 'Total'
    $summary.Range('E6').Value2 = [double]$results.Count
    $summary.Range('D3:D6').Font.Bold = $true
    $summary.Range('D3:E3').Interior.Color = 13561798
    $summary.Range('D4:E4').Interior.Color = 13551615
    $summary.Range('D5:E5').Interior.Color = 13434879

    $summary.Range('A8:F8').Merge()
    $summary.Range('A8').Value2 = 'Scope: production API, RBAC with 4 demo accounts, Edge desktop/mobile, public careers/JD/form. Destructive, load, concurrency, email/OTP, multi-tenant, and physical-device cases remain Pending when unsafe.'
    $summary.Range('A8').WrapText = $true
    $summary.Range('A8').Interior.Color = 15987699

    $headers = @('ID', 'Module', 'Test case', 'Actual output', 'Severity', 'Action')
    for ($column = 1; $column -le $headers.Count; $column++) {
        $headerIndex = $column - 1
        $summary.Cells.Item(10, $column).Value2 = [string]$headers[$headerIndex]
    }
    $summary.Range('A10:F10').Font.Bold = $true
    $summary.Range('A10:F10').Interior.Color = 12611584
    $summary.Range('A10:F10').Font.Color = 16777215

    $summaryRow = 11
    for ($row = 10; $row -le $casesSheet.UsedRange.Rows.Count; $row++) {
        $id = [string]$casesSheet.Cells.Item($row, 1).Value2
        if (-not $resultById.ContainsKey($id) -or $resultById[$id].result -ne 'Fail') { continue }
        $summary.Cells.Item($summaryRow, 1).Value2 = [string]$id
        $summary.Cells.Item($summaryRow, 2).Value2 = [string]$casesSheet.Cells.Item($row, 2).Value2
        $summary.Cells.Item($summaryRow, 3).Value2 = [string]$casesSheet.Cells.Item($row, 3).Value2
        $summary.Cells.Item($summaryRow, 4).Value2 = [string]$resultById[$id].actual
        $summary.Cells.Item($summaryRow, 5).Value2 = [string]$casesSheet.Cells.Item($row, 10).Value2
        $action = if ($id -in @('TC026', 'TC027', 'TC030')) { 'Fix DELETE role + remove DB ids 6,7,8' } else { 'Fix and retest on staging/production' }
        $summary.Cells.Item($summaryRow, 6).Value2 = [string]$action
        $summary.Range("A$summaryRow:F$summaryRow").WrapText = $true
        $summaryRow++
    }

    $summary.Range("A10:F$($summaryRow - 1)").Borders.LineStyle = 1
    $summary.Columns.Item(1).ColumnWidth = 11
    $summary.Columns.Item(2).ColumnWidth = 24
    $summary.Columns.Item(3).ColumnWidth = 38
    $summary.Columns.Item(4).ColumnWidth = 76
    $summary.Columns.Item(5).ColumnWidth = 12
    $summary.Columns.Item(6).ColumnWidth = 38
    $summary.Rows.Item(8).RowHeight = 42
    $summary.Range("A11:F$($summaryRow - 1)").VerticalAlignment = -4160
    $summary.Activate()
    $excel.ActiveWindow.SplitRow = 10
    $excel.ActiveWindow.FreezePanes = $true

    $excel.CalculateFull()
    $workbook.SaveAs($outputPath, 51)
    $workbook.Close($true)
    Write-Output ("Saved {0}: Pass={1}, Fail={2}, Pending={3}, Total={4}" -f $outputPath, $passCount, $failCount, $pendingCount, $results.Count)
}
finally {
    if ($null -ne $workbook) { try { $workbook.Close($false) } catch {} }
    $excel.Quit()
    if ($null -ne $summary) { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($summary) }
    if ($null -ne $reportSheet) { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($reportSheet) }
    if ($null -ne $casesSheet) { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($casesSheet) }
    if ($null -ne $workbook) { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($workbook) }
    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($excel)
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
