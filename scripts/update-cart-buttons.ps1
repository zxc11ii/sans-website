# Скрипт для массовой замены ссылок "подробнее →" на кнопки корзины
$scriptPath = Split-Path -Parent $MyInvocation.MyCommand.Path

# Список файлов для обработки с их ID префиксом и изображением
$files = @(
    @{File="pages\products\shrink-pvc.html"; Prefix="shrink-pvc"; Image="shrink-pvc.jpg"},
    @{File="pages\products\stretch-film.html"; Prefix="stretch"; Image="stretch-film.jpg"},
    @{File="pages\products\bopp-film.html"; Prefix="bopp"; Image="bopp-film.jpg"},
    @{File="pages\products\bubble-wrap.html"; Prefix="bubble-wrap"; Image="bubble-wrap.jpg"},
    @{File="pages\products\pe-film.html"; Prefix="pe-film"; Image="pe-film.jpg"}
)

function Create-Product-Id {
    param([string]$name, [string]$prefix)
    $id = $name -replace '[^a-zA-Z0-9а-яА-ЯёЁ\s\*\.]', ''
    $id = $id -replace '\s+', '-'
    $id = $id -replace '[а-яА-ЯёЁ]', ''
    $id = $id -replace '\.', '-'
    $id = $id -replace '\*', 'x'
    $id = $id.ToLower()
    $id = $id -replace '\-+', '-'
    $id = $id -replace '^\-|\-$', ''
    return "$prefix-$id"
}

foreach ($fileInfo in $files) {
    $filePath = Join-Path $scriptPath $fileInfo.File
    if (Test-Path $filePath) {
        Write-Host "Обработка: $($fileInfo.File)"
        
        $content = Get-Content $filePath -Raw -Encoding UTF8
        
        # Найти все секции product-variants
        $pattern = '<h3>(.*?)</h3>\s*<a href="#" class="product-link">подробнее →</a>'
        $matches = [regex]::Matches($content, $pattern)
        
        Write-Host "  Найдено вхождений: $($matches.Count)"
        
        foreach ($match in $matches) {
            $productName = $match.Groups[1].Value
            $productId = Create-Product-Id $productName $fileInfo.Prefix
            
            $oldText = $match.Value
            $newButton = @"
<h3>$productName</h3>
                    <button class="btn-add-to-cart" 
                            data-product-id="$productId" 
                            data-product-name="$productName"
                            data-product-image="../../images/products/$($fileInfo.Image)">
                        <img src="../../images/icons/shopping-cart.svg" alt="" class="cart-icon">
                        В корзину
                    </button>
"@
            $content = $content.Replace($oldText, $newButton)
        }
        
        Set-Content $filePath $content -Encoding UTF8 -NoNewline
        Write-Host "  Готово!"
    } else {
        Write-Host "Файл не найден: $filePath" -ForegroundColor Red
    }
}

Write-Host "`nОбработка завершена!" -ForegroundColor Green
