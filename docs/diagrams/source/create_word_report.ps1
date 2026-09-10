param(
    [Parameter(Mandatory = $true)]
    [string]$OutputDirectory
)

$ErrorActionPreference = "Stop"

$wdAlignParagraphLeft = 0
$wdAlignParagraphCenter = 1
$wdBreakPage = 7
$wdPaperA3 = 6
$wdOrientLandscape = 1
$wdFormatDocumentDefault = 16
$wdExportFormatPDF = 17

$output = [System.IO.Path]::GetFullPath($OutputDirectory)
$docxPath = Join-Path $output "Bo_4_So_Do_HRM_System.docx"
$pdfPath = Join-Path $output "Bo_4_So_Do_HRM_System.pdf"

$word = $null
$document = $null

function Set-SelectionFont {
    param(
        [Parameter(Mandatory = $true)]$Selection,
        [string]$Name = "Segoe UI",
        [double]$Size = 11,
        [bool]$Bold = $false,
        [int]$Color = 0
    )

    $Selection.Font.Name = $Name
    $Selection.Font.Size = $Size
    $Selection.Font.Bold = if ($Bold) { 1 } else { 0 }
    $Selection.Font.Color = $Color
}

function Add-TextParagraph {
    param(
        [Parameter(Mandatory = $true)]$Selection,
        [Parameter(Mandatory = $true)][string]$Text,
        [double]$Size = 11,
        [bool]$Bold = $false,
        [int]$Alignment = 0,
        [int]$Color = 0,
        [double]$SpaceAfter = 6
    )

    $Selection.ParagraphFormat.Alignment = $Alignment
    $Selection.ParagraphFormat.SpaceAfter = $SpaceAfter
    Set-SelectionFont -Selection $Selection -Size $Size -Bold $Bold -Color $Color
    $Selection.TypeText($Text)
    $Selection.TypeParagraph()
}

function Add-DiagramPage {
    param(
        [Parameter(Mandatory = $true)]$Selection,
        [Parameter(Mandatory = $true)][string]$Heading,
        [Parameter(Mandatory = $true)][string]$Description,
        [Parameter(Mandatory = $true)][string]$ImageName,
        [Parameter(Mandatory = $true)][double]$ImageWidth,
        [Parameter(Mandatory = $true)][string]$Caption,
        [bool]$AddPageBreak = $true
    )

    Add-TextParagraph -Selection $Selection -Text $Heading -Size 21 -Bold $true -Color 6242103 -SpaceAfter 4
    Add-TextParagraph -Selection $Selection -Text $Description -Size 10.5 -Color 7368816 -SpaceAfter 8

    $Selection.ParagraphFormat.Alignment = $wdAlignParagraphCenter
    $imagePath = Join-Path $output $ImageName
    $shape = $Selection.InlineShapes.AddPicture($imagePath, $false, $true)
    $shape.LockAspectRatio = -1
    $shape.Width = $ImageWidth
    $Selection.EndKey(6) | Out-Null
    $Selection.TypeParagraph()

    $Selection.ParagraphFormat.Alignment = $wdAlignParagraphCenter
    $Selection.Font.Name = "Segoe UI"
    $Selection.Font.Size = 10
    $Selection.Font.Italic = 1
    $Selection.Font.Color = 7368816
    $Selection.TypeText($Caption)
    $Selection.TypeParagraph()
    $Selection.Font.Italic = 0

    if ($AddPageBreak) {
        $Selection.InsertBreak($wdBreakPage)
    }
}

try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0

    $document = $word.Documents.Add()
    $document.PageSetup.PaperSize = $wdPaperA3
    $document.PageSetup.Orientation = $wdOrientLandscape
    $document.PageSetup.TopMargin = 28.35
    $document.PageSetup.BottomMargin = 28.35
    $document.PageSetup.LeftMargin = 34.02
    $document.PageSetup.RightMargin = 34.02

    $selection = $word.Selection

    $selection.ParagraphFormat.Alignment = $wdAlignParagraphCenter
    $selection.ParagraphFormat.SpaceBefore = 115
    Add-TextParagraph -Selection $selection -Text "BỘ SƠ ĐỒ PHÂN TÍCH VÀ THIẾT KẾ" -Size 29 -Bold $true -Alignment $wdAlignParagraphCenter -Color 6242103 -SpaceAfter 14
    Add-TextParagraph -Selection $selection -Text "HỆ THỐNG QUẢN LÝ NHÂN SỰ HRM" -Size 25 -Bold $true -Alignment $wdAlignParagraphCenter -Color 10122894 -SpaceAfter 24
    Add-TextParagraph -Selection $selection -Text "Xây dựng theo cấu trúc file mẫu nhóm 1 (4).docx" -Size 13 -Alignment $wdAlignParagraphCenter -Color 7368816 -SpaceAfter 8
    Add-TextParagraph -Selection $selection -Text "Nội dung đối chiếu từ source Laravel/Vue và database PostgreSQL đang chạy của dự án BEHRM" -Size 11.5 -Alignment $wdAlignParagraphCenter -Color 7368816 -SpaceAfter 40

    $selection.ParagraphFormat.Alignment = $wdAlignParagraphLeft
    $selection.ParagraphFormat.LeftIndent = 170
    Add-TextParagraph -Selection $selection -Text "1. Sơ đồ Use Case" -Size 13 -Bold $true -Color 6242103 -SpaceAfter 5
    Add-TextParagraph -Selection $selection -Text "2. Các bảng chính cho sơ đồ ERD" -Size 13 -Bold $true -Color 6242103 -SpaceAfter 5
    Add-TextParagraph -Selection $selection -Text "3. Sơ đồ quan hệ ERD" -Size 13 -Bold $true -Color 6242103 -SpaceAfter 5
    Add-TextParagraph -Selection $selection -Text "4. Sơ đồ chi tiết thực thể" -Size 13 -Bold $true -Color 6242103 -SpaceAfter 5
    $selection.ParagraphFormat.LeftIndent = 0
    $selection.InsertBreak($wdBreakPage)

    Add-DiagramPage -Selection $selection `
        -Heading "3.1. Sơ đồ Use Case" `
        -Description "Các tác nhân và nhóm nghiệp vụ chính được tổng hợp từ router frontend, API backend và các tích hợp máy chấm công / resume-backend." `
        -ImageName "01-use-case.png" `
        -ImageWidth 900 `
        -Caption "Hình 1. Sơ đồ Use Case của hệ thống HRM"

    Add-DiagramPage -Selection $selection `
        -Heading "3.2. Các bảng chính cho sơ đồ ERD" `
        -Description "Các bảng nghiệp vụ được gom theo module. Bảng framework và các partition con của attendance_logs không đưa vào phạm vi báo cáo." `
        -ImageName "02-main-tables.png" `
        -ImageWidth 950 `
        -Caption "Hình 2. Các bảng chính cho sơ đồ ERD"

    Add-DiagramPage -Selection $selection `
        -Heading "3.3. Sơ đồ quan hệ ERD" `
        -Description "Sơ đồ mức khái niệm tập trung vào lực lượng 1:N, 1:1 và các liên kết nghiệp vụ giữa Core HR, chấm công, lương, tuyển dụng và tích hợp ngoài." `
        -ImageName "03-erd-relations.png" `
        -ImageWidth 900 `
        -Caption "Hình 3. Sơ đồ quan hệ ERD của hệ thống HRM"

    Add-DiagramPage -Selection $selection `
        -Heading "3.4. Sơ đồ chi tiết thực thể" `
        -Description "Tên bảng, cột quan trọng, kiểu dữ liệu và ký hiệu PK/FK được lấy từ schema PostgreSQL hiện tại; các cột meta và timestamps được lược bớt để tăng khả năng đọc." `
        -ImageName "04-detailed-erd.png" `
        -ImageWidth 745 `
        -Caption "Hình 4. Sơ đồ chi tiết thực thể" `
        -AddPageBreak $false

    $document.SaveAs2($docxPath, $wdFormatDocumentDefault)
    $document.ExportAsFixedFormat($pdfPath, $wdExportFormatPDF)
}
finally {
    if ($document -ne $null) {
        $document.Close($false)
    }
    if ($word -ne $null) {
        $word.Quit()
    }
    [System.GC]::Collect()
    [System.GC]::WaitForPendingFinalizers()
}

Write-Output $docxPath
Write-Output $pdfPath
