param(
    [string]$WorkbookPath = 'D:\HRM\docs	est-cases\TestCase_HRM_System.xlsx',
    [string]$OutputPath = 'D:\HRM\docs	est-cases\testcases_export.json'
)

$ErrorActionPreference = 'Stop'
$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false

try {
    $workbook = $excel.Workbooks.Open($WorkbookPath, 0, $true)
    $sheet = $workbook.Worksheets.Item('HRM Test Cases')
    $cases = @()
    for ($row = 10; $row -le $sheet.UsedRange.Rows.Count; $row++) {
        $id = [string]$sheet.Cells.Item($row, 1).Value2
        if (-not $id.StartsWith('TC')) { continue }
        $cases += [ordered]@{
            row = $row
            id = $id
            module = [string]$sheet.Cells.Item($row, 2).Value2
            description = [string]$sheet.Cells.Item($row, 3).Value2
            procedure = [string]$sheet.Cells.Item($row, 4).Value2
            expected = [string]$sheet.Cells.Item($row, 5).Value2
            actual = [string]$sheet.Cells.Item($row, 6).Value2
            test_date = [string]$sheet.Cells.Item($row, 7).Text
            result = [string]$sheet.Cells.Item($row, 8).Value2
            note = [string]$sheet.Cells.Item($row, 9).Value2
            severity = [string]$sheet.Cells.Item($row, 10).Value2
        }
    }
    $json = $cases | ConvertTo-Json -Depth 5
    [IO.File]::WriteAllText($OutputPath, $json, [Text.UTF8Encoding]::new($false))
    Write-Output ("Exported {0} test cases to {1}" -f $cases.Count, $OutputPath)
}
finally {
    if ($null -ne $workbook) { $workbook.Close($false) }
    $excel.Quit()
    if ($null -ne $sheet) { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($sheet) }
    if ($null -ne $workbook) { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($workbook) }
    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($excel)
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
