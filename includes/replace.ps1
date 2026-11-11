$content = [IO.File]::ReadAllText('c:\xampp_new\htdocs\music-e-office\includes\sidebar.php')
$new = [IO.File]::ReadAllText('c:\xampp_new\htdocs\music-e-office\includes\new_modal.php')
$start = '<div class="modal fade" id="appCenterModal" tabindex="-1" aria-hidden="true">'
$end = '<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">'
$posStart = $content.IndexOf($start)
$posEnd = $content.IndexOf($end)
if ($posStart -ge 0 -and $posEnd -ge 0) {
    $before = $content.Substring(0, $posStart)
    $after = $content.Substring($posEnd)
    $result = $before + $new + "`r`n`r`n" + $after
    [IO.File]::WriteAllText('c:\xampp_new\htdocs\music-e-office\includes\sidebar.php', $result, [Text.Encoding]::UTF8)
    Write-Host "Success"
} else {
    Write-Host "Failed. Start: $posStart, End: $posEnd"
}
