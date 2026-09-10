$ErrorActionPreference = 'Stop'

$workbookPath = Join-Path $PSScriptRoot 'TestCase_HRM_System.xlsx'
$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false

try {
    $workbook = $excel.Workbooks.Open($workbookPath, 0, $true)
    foreach ($sheet in $workbook.Worksheets) {
        Write-Output ("SHEET`t{0}`t{1}`t{2}" -f $sheet.Name, $sheet.UsedRange.Rows.Count, $sheet.UsedRange.Columns.Count)
        $maxRows = [Math]::Min(12, $sheet.UsedRange.Rows.Count)
        for ($row = 1; $row -le $maxRows; $row++) {
            $values = for ($column = 1; $column -le $sheet.UsedRange.Columns.Count; $column++) {
                $value = $sheet.Cells.Item($row, $column).Text
                if ($null -eq $value) { '' } else { ($value -replace "`r|`n", ' ') }
            }
            Write-Output (("ROW`t{0}`t" -f $row) + ($values -join "`t"))
        }
    }
}
finally {
    if ($null -ne $workbook) { $workbook.Close($false) }
    $excel.Quit()
    if ($null -ne $workbook) { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($workbook) }
    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($excel)
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
