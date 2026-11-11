$css = @"
    /* สไตล์การ์ดใน App Center Modal (ใหม่) */
    .custom-app-tabs .nav-link { color: #64748b !important; background: transparent !important; border: 1px solid transparent !important; transition: 0.2s !important; margin-bottom: 4px; }
    .custom-app-tabs .nav-link:hover { background-color: #f8fafc !important; color: #4b0082 !important; }
    .custom-app-tabs .nav-link.active { background-color: #f3e5f5 !important; color: #4b0082 !important; border-color: rgba(75,0,130,0.1) !important; font-weight: bold !important; }
    .w-20px { width: 24px; }
    
    .app-square-card { 
        display: flex; flex-direction: column; align-items: center; justify-content: center; 
        padding: 1.5rem 0.5rem; border-radius: 1rem; background-color: #fff; 
        border: 1px solid transparent; box-shadow: 0 4px 6px rgba(0,0,0,0.02); transition: 0.2s; 
        height: 100%; text-decoration: none !important;
    }
    .app-square-card:hover { 
        transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); 
        border-color: rgba(75,0,130,0.1) !important; 
    }
    .app-icon-lg { 
        width: 56px; height: 56px; border-radius: 1rem; 
        display: flex; align-items: center; justify-content: center; font-size: 1.5rem; 
        transition: 0.3s; margin: 0 auto;
    }
    .app-square-card:hover .app-icon-lg { transform: scale(1.1); }
"@
$content = [IO.File]::ReadAllText('c:\xampp_new\htdocs\music-e-office\includes\sidebar.php')
$result = $content.Replace("</style>", $css + "`r`n</style>")
[IO.File]::WriteAllText('c:\xampp_new\htdocs\music-e-office\includes\sidebar.php', $result, [Text.Encoding]::UTF8)
Write-Host "CSS Appended successfully"
