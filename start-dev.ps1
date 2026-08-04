$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Error "未找到 PHP，请先安装 PHP 8.1+ 并加入 PATH，或修改本脚本中的 PHP 路径。"
    exit 1
}
Write-Host "OpsDeck 开发服务器：http://127.0.0.1:43210"
& php -S 127.0.0.1:43210 -t public
