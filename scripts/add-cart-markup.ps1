# Script to add cart markup to product pages

$cartMarkup = @'

    <!-- Shopping Cart Button -->
    <button id="cartButton" class="cart-button" aria-label="Корзина">
        <img src="../../images/icons/shopping-cart.svg" alt="Корзина">
        <span class="cart-badge" id="cartBadge">0</span>
    </button>

    <!-- Shopping Cart Modal -->
    <div class="cart-modal" id="cartModal">
        <div class="cart-modal-content">
            <div class="cart-modal-header">
                <h2>Корзина</h2>
                <button class="cart-close" id="cartClose">&times;</button>
            </div>
            <div class="cart-modal-body">
                <div class="cart-empty" id="cartEmpty">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 7h14l-1.5 9h-11L5 7z"/>
                        <path d="M9 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
                        <path d="M15 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
                        <path d="M5 7L3 3h18l-2 4"/>
                    </svg>
                    <p>Корзина пуста</p>
                    <a href="../packaging.html" class="btn btn-primary">Перейти к товарам</a>
                </div>
                <div class="cart-items" id="cartItems" style="display: none;"></div>
            </div>
            <div class="cart-modal-footer" style="display: none;">
                <div class="cart-total">
                    <span>Всего:</span>
                    <span class="cart-total-count" id="cartTotalCount">0 товаров</span>
                </div>
                <button class="btn btn-primary cart-checkout-btn" id="cartCheckoutBtn">Оставить заявку</button>
            </div>
        </div>
    </div>

    <!-- Cart Order Form Modal -->
    <div class="cart-modal" id="cartOrderModal">
        <div class="cart-modal-content">
            <div class="cart-modal-header">
                <h2>Оформление заявки</h2>
                <button class="cart-close" id="cartOrderClose">&times;</button>
            </div>
            <div class="cart-modal-body">
                <div class="cart-order-summary" id="cartOrderSummary">
                    <h3>Ваш заказ:</h3>
                    <div class="cart-order-items" id="cartOrderItems"></div>
                </div>
                <form class="contact-form" id="cartOrderForm">
                    <div class="form-group">
                        <input type="text" id="cartOrderName" name="name" placeholder="Ваше имя *" required>
                    </div>
                    <div class="form-group">
                        <input type="tel" id="cartOrderPhone" name="phone" placeholder="Телефон *" required>
                    </div>
                    <div class="form-group">
                        <input type="email" id="cartOrderEmail" name="email" placeholder="Email">
                    </div>
                    <div class="form-group">
                        <textarea id="cartOrderMessage" name="message" rows="3" placeholder="Комментарий к заказу"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-full">Отправить заявку</button>
                    <p class="form-note">Нажимая кнопку, вы соглашаетесь с политикой конфиденциальности</p>
                </form>
            </div>
        </div>
    </div>

    <script src="../../js/script.js"></script>
</body>
</html>
'@

$files = @(
    'labels', 'stretch-film', 'shrink-pvc', 'shrink-pof', 'shrink-pe',
    'bopp-film', 'bubble-wrap', 'pe-film', 'pp-strap', 'pet-strap',
    'steel-strap', 'vacuum-bags', 'zip-bags'
)

$productsDir = "c:\Users\Пользователь\Desktop\Санс дядя Стас\pages\products"

foreach ($file in $files) {
    $filePath = Join-Path $productsDir "$file.html"
    
    if (Test-Path $filePath) {
        $content = Get-Content $filePath -Raw -Encoding UTF8
        
        # Check if cart markup already exists
        if ($content -notmatch 'cartButton') {
            Write-Host "Processing $file.html..."
            
            # Replace the ending with cart markup
            $oldEnding = "    </div>`r`n`r`n    <script src=`"../../js/script.js`"></script>`r`n</body>`r`n</html>"
            
            if ($content -match [regex]::Escape($oldEnding)) {
                $content = $content -replace [regex]::Escape($oldEnding), $cartMarkup
                Set-Content -Path $filePath -Value $content -Encoding UTF8 -NoNewline
                Write-Host "  ✓ Added cart markup to $file.html"
            } else {
                Write-Host "  ✗ Could not find ending pattern in $file.html"
            }
        } else {
            Write-Host "  - $file.html already has cart markup"
        }
    } else {
        Write-Host "  ✗ File not found: $file.html"
    }
}

Write-Host "`nDone!"
