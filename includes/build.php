<?php
/**
 * Сборка сайта: собирает все HTML-страницы из данных и шаблонов.
 *
 *   data/products.json  - товары и варианты
 *   data/site.json      - телефон, адрес, почта, меню
 *   content/*.html      - текст уникальных страниц (главная, о компании, контакты)
 *
 * Сборщик написан на PHP, а не на Node: его вызывает админка прямо на хостинге
 * после каждого сохранения, а Node на обычном хостинге нет. С компьютера
 * запускается так же:  php scripts/build.php
 *
 * root - префикс пути к корню сайта для текущей страницы:
 *   главная -> '', pages/*.html -> '../', pages/products/*.html -> '../../'
 */

// Открытый напрямую по адресу файл ничего не должен делать
if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

define('SANS_ROOT', dirname(__DIR__));
define('SANS_PRODUCTS_FILE', SANS_ROOT . '/data/products.json');
define('SANS_SITE_FILE', SANS_ROOT . '/data/site.json');

// ------------------------------------------------------------------
// Данные
// ------------------------------------------------------------------

/** Читает JSON-файл данных. null, если файла нет или он битый. */
function sans_read_json($file)
{
    if (!is_readable($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function sans_load_products()
{
    $data = sans_read_json(SANS_PRODUCTS_FILE);
    return $data === null ? [] : $data;
}

function sans_load_site()
{
    $data = sans_read_json(SANS_SITE_FILE);
    return $data === null ? [] : $data;
}

/** JSON в том же виде, что пишет Node: отступ 2 пробела, перевод строки в конце. */
function sans_json_file($data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    if ($json === false) {
        return false;
    }
    // PHP ставит 4 пробела на уровень, в проекте принято 2
    $json = preg_replace_callback('/^( +)/m', function ($m) {
        return str_repeat(' ', (int)(strlen($m[1]) / 2));
    }, $json);
    return $json . "\n";
}

/**
 * Записывает файл данных. Пишем во временный файл и переименовываем —
 * иначе при обрыве посреди записи файл останется битым и сайт не соберётся.
 * Заодно держим последние 20 резервных копий в data/backups.
 */
function sans_save_json($file, $data)
{
    $json = sans_json_file($data);
    if ($json === false) {
        return false;
    }

    if (is_file($file)) {
        $backupDir = dirname($file) . '/backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }
        $name = pathinfo($file, PATHINFO_FILENAME);
        @copy($file, $backupDir . '/' . $name . '-' . date('Ymd-His') . '.json');
        $old = glob($backupDir . '/' . $name . '-*.json');
        if ($old && count($old) > 20) {
            sort($old);
            foreach (array_slice($old, 0, count($old) - 20) as $f) {
                @unlink($f);
            }
        }
    }

    return sans_write_file($file, $json);
}

/** Атомарная запись: временный файл + переименование. */
function sans_write_file($file, $content)
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    // на Windows rename не перезаписывает существующий файл
    if (DIRECTORY_SEPARATOR === '\\' && is_file($file)) {
        @unlink($file);
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

// ------------------------------------------------------------------
// Мелкие помощники
// ------------------------------------------------------------------

function sans_esc($s)
{
    return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], (string)$s);
}

/** Истинность значения как в JavaScript: 0, '', null, false — ложь. */
function sans_truthy($v)
{
    return !($v === null || $v === false || $v === '' || $v === 0 || $v === 0.0);
}

/**
 * Ссылка от текущей страницы к цели, заданной путём от корня сайта.
 * dir - папка страницы ('.', 'pages', 'pages/products').
 */
function sans_link($dir, $target)
{
    $from = ($dir === '' || $dir === '.') ? [] : explode('/', $dir);
    $to = explode('/', $target);
    $common = 0;
    while ($common < count($from) && $common < count($to) - 1 && $from[$common] === $to[$common]) {
        $common++;
    }
    $parts = array_merge(array_fill(0, count($from) - $common, '..'), array_slice($to, $common));
    $rel = implode('/', $parts);
    return $rel === '' ? '.' : $rel;
}

/** Абсолютный адрес файла на сайте; null, пока домен не задан в site.json */
function sans_absolute($site, $filePath)
{
    if (empty($site['url'])) {
        return null;
    }
    $base = rtrim($site['url'], '/');
    $clean = $filePath === 'index.html' ? '' : $filePath;
    return $base . '/' . $clean;
}

function sans_product_word($count)
{
    $last = $count % 10;
    $lastTwo = $count % 100;
    if ($lastTwo >= 11 && $lastTwo <= 19) return 'товаров';
    if ($last === 1) return 'товар';
    if ($last >= 2 && $last <= 4) return 'товара';
    return 'товаров';
}

// Текст про аналитику в политике конфиденциальности зависит от того,
// подключены ли счётчики (список в data/site.json -> analytics)
function sans_analytics_sentence($site)
{
    $list = isset($site['analytics']) ? $site['analytics'] : [];
    if (!$list) {
        return 'Счётчики веб-аналитики на сайте не установлены.';
    }
    return 'Сайт использует сервисы веб-аналитики (' . implode(', ', $list) . '), которые собирают обезличенные сведения о посещениях: адрес страницы, источник перехода, тип устройства и браузера. Эти сведения не позволяют идентифицировать вас лично.';
}

/** Подстановки в текстовых страницах: {{root}}, {{productCount}}, {{phone}} и т.д. */
function sans_fill($text, $root, $site, $products)
{
    // доступны все строковые поля site.json плюс служебные
    $tokens = [];
    foreach ($site as $k => $v) {
        if (is_string($v)) {
            $tokens[$k] = sans_esc($v);
        }
    }
    $tokens['root'] = $root;
    $tokens['productCount'] = (string)count($products);
    $tokens['productCountWord'] = sans_product_word(count($products));
    $tokens['analyticsSentence'] = sans_esc(sans_analytics_sentence($site));

    return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($tokens) {
        if (!array_key_exists($m[1], $tokens)) {
            throw new RuntimeException('Неизвестная подстановка {{' . $m[1] . '}}');
        }
        return $tokens[$m[1]];
    }, $text);
}

// ------------------------------------------------------------------
// Шаблоны. Всё, что раньше было скопировано в 25 HTML-файлов
// (шапка, подвал, корзина, поп-ап), живёт здесь в одном экземпляре.
// ------------------------------------------------------------------

/** canonical, Open Graph и Twitter Card */
function sans_social_tags($site, $page)
{
    $pageUrl = sans_absolute($site, $page['out']);
    $imageUrl = sans_absolute($site, isset($site['ogImage']) ? $site['ogImage'] : '');
    $lines = [];

    if ($pageUrl) $lines[] = '    <link rel="canonical" href="' . sans_esc($pageUrl) . '">';

    $lines[] = '    <meta property="og:type" content="' . (!empty($page['product']) ? 'product' : 'website') . '">';
    $lines[] = '    <meta property="og:site_name" content="' . sans_esc($site['name']) . '">';
    $lines[] = '    <meta property="og:locale" content="ru_RU">';
    $lines[] = '    <meta property="og:title" content="' . sans_esc($page['title']) . '">';
    $lines[] = '    <meta property="og:description" content="' . sans_esc($page['description']) . '">';

    if ($pageUrl) $lines[] = '    <meta property="og:url" content="' . sans_esc($pageUrl) . '">';

    // og:image требует абсолютного адреса, поэтому только после указания домена
    if ($imageUrl) {
        $productImage = !empty($page['product']) ? sans_absolute($site, $page['product']['mainImage']) : null;
        $lines[] = '    <meta property="og:image" content="' . sans_esc($productImage ?: $imageUrl) . '">';
    }

    $lines[] = '    <meta name="twitter:card" content="summary_large_image">';
    $lines[] = '    <meta name="twitter:title" content="' . sans_esc($page['title']) . '">';
    $lines[] = '    <meta name="twitter:description" content="' . sans_esc($page['description']) . '">';

    return implode("\n", $lines);
}

/** Микроразметка Schema.org */
function sans_structured_data($site, $page)
{
    $blocks = [];

    $organization = [
        '@type' => 'LocalBusiness',
        'name' => $site['name'],
        'description' => $site['tagline'],
        'telephone' => array_values(array_filter([$site['phone'], $site['phoneSecond']], 'sans_truthy')),
        'email' => $site['email'],
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => $site['streetAddress'],
            'addressLocality' => $site['locality'],
            'addressRegion' => $site['region'],
            'addressCountry' => 'RU',
        ],
        'openingHours' => $site['openingHours'],
        'foundingDate' => $site['foundingYear'],
    ];
    if (!empty($site['url'])) {
        $organization['url'] = rtrim($site['url'], '/');
        $organization['image'] = sans_absolute($site, $site['ogImage']);
    }

    // организацию описываем на главной и контактах, чтобы не дублировать на всех страницах
    if ($page['active'] === 'index' || $page['active'] === 'contacts') {
        $blocks[] = $organization;
    }

    if (!empty($page['product'])) {
        $p = $page['product'];
        $product = [
            '@type' => 'Product',
            'name' => $p['title'],
            'description' => $p['description'],
            'category' => 'Упаковочные материалы',
            'brand' => ['@type' => 'Brand', 'name' => $site['name']],
        ];
        $image = sans_absolute($site, $p['mainImage']);
        if ($image) $product['image'] = $image;
        if (sans_truthy($p['sku'] ?? null)) $product['sku'] = $p['sku'];
        // цены пока нет - предложение появится вместе с прайсом
        if (sans_truthy($p['price'] ?? null)) {
            $product['offers'] = [
                '@type' => 'Offer',
                'price' => $p['price'],
                'priceCurrency' => 'RUB',
                'availability' => 'https://schema.org/InStock',
            ];
        }
        $blocks[] = $product;

        $crumbs = [
            ['name' => 'Главная', 'file' => 'index.html'],
            ['name' => 'Упаковка', 'file' => 'pages/packaging.html'],
            ['name' => $p['title'], 'file' => $page['out']],
        ];
        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $item = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name']];
            $url = sans_absolute($site, $crumb['file']);
            if ($url) $item['item'] = $url;
            $items[] = $item;
        }
        $blocks[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    if (!$blocks) return '';

    $json = count($blocks) === 1
        ? array_merge(['@context' => 'https://schema.org'], $blocks[0])
        : ['@context' => 'https://schema.org', '@graph' => $blocks];

    $text = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    // </script> внутри строки закрыл бы блок раньше времени
    $text = str_replace('</', '<\/', $text);
    $lines = array_map(function ($l) { return '    ' . $l; }, explode("\n", $text));
    return "    <script type=\"application/ld+json\">\n" . implode("\n", $lines) . "\n    </script>";
}

function sans_head($site, $page)
{
    $root = $page['root'];
    $ld = sans_structured_data($site, $page);
    $description = sans_esc($page['description']);
    $title = sans_esc($page['title']);
    $social = sans_social_tags($site, $page);
    $ldBlock = $ld ? $ld . "\n" : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{$description}">
    <title>{$title}</title>
{$social}
    <link rel="icon" type="image/png" href="{$root}images/favicon.png">
    <link rel="stylesheet" href="{$root}css/styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
{$ldBlock}</head>
<body>
HTML;
}

function sans_header($site, $page)
{
    $dir = $page['dir'];
    $items = [];
    foreach ($site['nav'] as $item) {
        $cls = $item['key'] === $page['active'] ? ' class="active"' : '';
        $items[] = '                    <li><a href="' . sans_link($dir, $item['href']) . '"' . $cls . '>' . sans_esc($item['label']) . '</a></li>';
    }
    $items = implode("\n", $items);
    $home = sans_link($dir, 'index.html');
    $name = sans_esc($site['name']);
    $phoneHref = sans_esc($site['phoneHref']);
    $phone = sans_esc($site['phone']);

    return <<<HTML
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <a href="{$home}" class="logo">
                    <span class="company-name">{$name}</span>
                </a>
                <ul class="nav-menu">
{$items}
                </ul>
                <a href="tel:{$phoneHref}" class="btn-call">{$phone}</a>
                <button class="mobile-menu-toggle" aria-label="Меню" aria-expanded="false">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </nav>
        </div>
    </header>
HTML;
}

function sans_footer($site, $page)
{
    $root = $page['root'];
    $dir = $page['dir'];
    $links = [];
    foreach ($site['nav'] as $item) {
        $links[] = '                        <li><a href="' . sans_link($dir, $item['href']) . '">' . sans_esc($item['label']) . '</a></li>';
    }
    $links = implode("\n", $links);
    $name = sans_esc($site['name']);
    $tagline = sans_esc($site['tagline']);
    $phoneHref = sans_esc($site['phoneHref']);
    $phone = sans_esc($site['phone']);
    $email = sans_esc($site['email']);
    $address = sans_esc($site['address']);
    $year = sans_esc($site['copyrightYear']);
    $updated = sans_esc($site['dataUpdated']);
    $privacy = sans_link($dir, 'pages/privacy.html');

    return <<<HTML
    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <div class="footer-logo">
                        <img src="{$root}images/logo.png" alt="{$name}" class="footer-logo-img" loading="lazy" decoding="async">
                        <p>{$tagline}</p>
                    </div>
                </div>
                <div class="footer-section">
                    <h4>Навигация</h4>
                    <ul class="footer-links">
{$links}
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Контакты</h4>
                    <ul class="footer-contacts">
                        <li><a href="tel:{$phoneHref}">{$phone}</a></li>
                        <li><a href="mailto:{$email}">{$email}</a></li>
                        <li>{$address}</li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>© {$year} {$name}. Все права защищены.</p>
                <p><a href="{$privacy}">Политика конфиденциальности</a></p>
                <p>Данные обновлены {$updated}</p>
            </div>
        </div>
    </footer>
HTML;
}

function sans_scroll_top()
{
    return <<<HTML
    <!-- Scroll to Top Button -->
    <button id="scrollToTop" class="scroll-to-top" aria-label="Наверх">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 19V5M12 5L5 12M12 5L19 12" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
HTML;
}

function sans_discount_popup($page)
{
    $privacy = sans_link($page['dir'], 'pages/privacy.html');

    return <<<HTML
    <!-- Discount Popup -->
    <div class="discount-popup" id="discountPopup">
        <div class="discount-popup-mini" id="discountMini">
            <div class="discount-popup-icon">🎁</div>
            <div class="discount-popup-text">
                <strong>Специальное предложение!</strong>
                <p>Скидка при первом заказе</p>
            </div>
            <button class="discount-popup-close" id="discountClose" aria-label="Закрыть">×</button>
        </div>

        <div class="discount-popup-full" id="discountFull">
            <button class="discount-popup-close-full" id="discountCloseFull" aria-label="Закрыть">×</button>
            <div class="discount-popup-header">
                <div class="discount-popup-icon-large">🎁</div>
                <h3>Получите скидку!</h3>
                <p>Оставьте заявку и получите специальную скидку на первый заказ</p>
            </div>
            <form class="discount-form" id="discountForm">
                <div class="form-group">
                    <input type="text" id="discountName" name="name" placeholder="Ваше имя *" required>
                </div>
                <div class="form-group">
                    <input type="tel" id="discountPhone" name="phone" placeholder="Телефон *" required>
                </div>
                <div class="form-group">
                    <input type="email" id="discountEmail" name="email" placeholder="Email">
                </div>
                <div class="form-group">
                    <textarea id="discountMessage" name="message" rows="3" placeholder="Интересующий товар"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-full">Получить скидку</button>
                <label class="form-consent">
                    <input type="checkbox" name="consent" required>
                    <span>Я даю согласие на обработку персональных данных и принимаю <a href="{$privacy}" target="_blank" rel="noopener">политику конфиденциальности</a></span>
                </label>
            </form>
        </div>
    </div>
HTML;
}

function sans_cookie_banner($page)
{
    $privacy = sans_link($page['dir'], 'pages/privacy.html');

    return <<<HTML
    <!-- Cookie Notice -->
    <div class="cookie-banner" id="cookieBanner" role="dialog" aria-live="polite" aria-label="Использование cookie">
        <p class="cookie-banner-text">
            Мы используем cookie и локальное хранилище браузера, чтобы сайт работал корректно — например, чтобы корзина запоминала выбранные товары. Подробности в <a href="{$privacy}">политике конфиденциальности</a>.
        </p>
        <button class="btn btn-primary cookie-banner-accept" id="cookieAccept">Принять</button>
    </div>
HTML;
}

/** Настройки, которые нужны скриптам на странице */
function sans_config_script($site)
{
    $json = json_encode([
        'formEndpoint' => !empty($site['formEndpoint']) ? $site['formEndpoint'] : '',
        'phone' => $site['phone'],
        'phoneHref' => $site['phoneHref'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    $json = str_replace('</', '<\/', $json);

    return "    <script>\n        window.SANS_CONFIG = {$json};\n    </script>";
}

function sans_cart($page)
{
    $root = $page['root'];
    $packaging = sans_link($page['dir'], 'pages/packaging.html');
    $privacy = sans_link($page['dir'], 'pages/privacy.html');

    return <<<HTML
    <!-- Shopping Cart Button -->
    <button id="cartButton" class="cart-button" aria-label="Корзина">
        <img src="{$root}images/icons/shopping-cart.svg" alt="Корзина">
        <span class="cart-badge" id="cartBadge">0</span>
    </button>

    <!-- Shopping Cart Modal -->
    <div class="cart-modal" id="cartModal">
        <div class="cart-modal-content">
            <div class="cart-modal-header">
                <h2>Корзина</h2>
                <button class="cart-close" id="cartClose" aria-label="Закрыть">&times;</button>
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
                    <a href="{$packaging}" class="btn btn-primary">Перейти к товарам</a>
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
                <button class="cart-close" id="cartOrderClose" aria-label="Закрыть">&times;</button>
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
                    <label class="form-consent">
                        <input type="checkbox" name="consent" required>
                        <span>Я даю согласие на обработку персональных данных и принимаю <a href="{$privacy}" target="_blank" rel="noopener">политику конфиденциальности</a></span>
                    </label>
                </form>
            </div>
        </div>
    </div>
HTML;
}

/** Полная страница: шапка + содержимое + подвал + плавающие элементы */
function sans_layout($site, $page)
{
    $parts = [
        sans_head($site, $page),
        sans_header($site, $page),
        '',
        preg_replace('/\s+$/u', '', $page['body']),
        '',
        sans_footer($site, $page),
        '',
        sans_scroll_top(),
    ];

    if (!empty($page['showPopup'])) {
        $parts[] = '';
        $parts[] = sans_discount_popup($page);
    }
    $parts[] = '';
    $parts[] = sans_cart($page);
    $parts[] = '';
    $parts[] = sans_cookie_banner($page);
    array_push($parts, '', sans_config_script($site),
        '    <script src="' . $page['root'] . 'js/script.js"></script>', '</body>', '</html>', '');

    return implode("\n", $parts);
}

/** Карточка товара в сетке каталога */
function sans_product_card($product, $root, $href)
{
    $img = sans_esc($root . $product['image']);
    $cardTitle = sans_esc($product['cardTitle']);
    $short = sans_esc($product['shortDescription']);
    $href = sans_esc($href);

    return <<<HTML
                <div class="product-card">
                    <div class="product-image-placeholder">
                        <img src="{$img}" alt="{$cardTitle}" class="product-image" loading="lazy" decoding="async">
                    </div>
                    <h3>{$cardTitle}</h3>
                    <p>{$short}</p>
                    <a href="{$href}" class="product-link">Подробнее →</a>
                </div>
HTML;
}

/** Карточка варианта с кнопкой "В корзину" */
function sans_variant_card($variant, $root)
{
    $fullName = sans_esc($variant['fullName']);
    $media = !empty($variant['photo'])
        ? '<img src="' . sans_esc($root . $variant['photo']) . '" alt="' . $fullName . '" class="product-image" loading="lazy" decoding="async">'
        : '<span class="placeholder-text">Фото скоро появится</span>';
    $id = sans_esc($variant['id']);
    $name = sans_esc($variant['name']);
    $image = sans_esc($root . $variant['image']);

    return <<<HTML
                <div class="product-card">
                    <div class="product-image-placeholder">
                        {$media}
                    </div>
                    <h3>{$fullName}</h3>
                    <button class="btn-add-to-cart"
                            data-product-id="{$id}"
                            data-product-name="{$name}"
                            data-product-image="{$image}">
                        <img src="{$root}images/icons/shopping-cart.svg" alt="" class="cart-icon">
                        В корзину
                    </button>
                </div>
HTML;
}

/** Страница каталога */
function sans_catalog_body($products, $root)
{
    $cards = [];
    foreach ($products as $p) {
        $cards[] = sans_product_card($p, $root, 'products/' . $p['slug'] . '.html');
    }
    $cards = implode("\n\n", $cards);

    return <<<HTML
    <!-- Page Header -->
    <section class="page-header">
        <div class="container">
            <h1>Товары для упаковки и маркировки</h1>
            <p>Полный ассортимент упаковочных материалов для вашего бизнеса</p>
        </div>
    </section>

    <!-- Products Section -->
    <section class="products-page">
        <div class="container">
            <div class="products-grid">
{$cards}
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container">
            <div class="cta-content">
                <h2>Не нашли нужный товар?</h2>
                <p>Свяжитесь с нами, и мы поможем подобрать оптимальное решение для вашего бизнеса</p>
                <a href="contacts.html" class="btn btn-primary">Связаться с нами</a>
            </div>
        </div>
    </section>
HTML;
}

/** Страница товара */
function sans_product_body($product, $allProducts, $root, $dir)
{
    $specs = [];
    foreach ($product['specs'] as $s) {
        $specs[] = '                            <li><strong>' . sans_esc($s['label']) . ':</strong> ' . sans_esc($s['value']) . '</li>';
    }
    $specs = implode("\n", $specs);

    $features = [];
    foreach ($product['features'] as $f) {
        $features[] = '                            <li>✓ ' . sans_esc($f) . '</li>';
    }
    $features = implode("\n", $features);

    $orderButton = !empty($product['orderUrl'])
        ? '                        <a href="' . sans_esc($product['orderUrl']) . '" class="btn btn-primary" target="_blank" rel="noopener noreferrer">Заказать на сайте →</a>' . "\n"
        : '';

    $variantsSection = '';
    if ($product['variants']) {
        $cards = [];
        foreach ($product['variants'] as $v) {
            $cards[] = sans_variant_card($v, $root);
        }
        $cards = implode("\n\n", $cards);
        $variantsSection = <<<HTML


    <!-- Product Variants -->
    <section class="product-variants">
        <div class="container">
            <h2 class="section-title">Варианты товара</h2>
            <div class="products-grid">
{$cards}
            </div>
        </div>
    </section>
HTML;
    }

    $bySlug = [];
    foreach ($allProducts as $p) {
        if (!isset($bySlug[$p['slug']])) {
            $bySlug[$p['slug']] = $p;
        }
    }
    $related = [];
    foreach ($product['related'] as $slug) {
        if (isset($bySlug[$slug])) {
            $related[] = sans_product_card($bySlug[$slug], $root, $slug . '.html');
        }
    }
    $relatedCards = implode("\n\n", $related);

    $relatedSection = '';
    if ($relatedCards !== '') {
        $relatedSection = <<<HTML


    <!-- Related Products -->
    <section class="related-products">
        <div class="container">
            <h2 class="section-title">Похожие товары</h2>
            <div class="products-grid">
{$relatedCards}
            </div>
        </div>
    </section>
HTML;
    }

    $home = sans_link($dir, 'index.html');
    $packaging = sans_link($dir, 'pages/packaging.html');
    $contacts = sans_link($dir, 'pages/contacts.html');
    $title = sans_esc($product['title']);
    $mainImage = sans_esc($root . $product['mainImage']);
    $description = sans_esc($product['description']);

    return <<<HTML
    <!-- Breadcrumbs -->
    <section class="breadcrumbs">
        <div class="container">
            <a href="{$home}">Главная</a>
            <span>/</span>
            <a href="{$packaging}">Упаковка</a>
            <span>/</span>
            <span>{$title}</span>
        </div>
    </section>

    <!-- Product Detail -->
    <section class="product-detail">
        <div class="container">
            <div class="product-detail-grid">
                <div class="product-detail-images">
                    <div class="product-main-image">
                        <img src="{$mainImage}" alt="{$title}">
                    </div>
                </div>

                <div class="product-detail-info">
                    <h1>{$title}</h1>
                    <p class="product-description">
                        {$description}
                    </p>

                    <div class="product-specs">
                        <h3>Характеристики:</h3>
                        <ul>
{$specs}
                        </ul>
                    </div>

                    <div class="product-features">
                        <h3>Преимущества:</h3>
                        <ul>
{$features}
                        </ul>
                    </div>

                    <div class="product-actions">
{$orderButton}                        <a href="{$contacts}" class="btn btn-secondary">Связаться с нами</a>
                    </div>
                </div>
            </div>
        </div>
    </section>{$variantsSection}{$relatedSection}
HTML;
}

// ------------------------------------------------------------------
// Список страниц и сборка
// ------------------------------------------------------------------

function sans_read_content($name)
{
    return (string)file_get_contents(SANS_ROOT . '/content/' . $name);
}

/** Все страницы сайта: куда пишем, заголовки и функция, дающая содержимое. */
function sans_pages($site, $products)
{
    $fill = function ($name, $root) use ($site, $products) {
        return function () use ($name, $root, $site, $products) {
            return sans_fill(sans_read_content($name), $root, $site, $products);
        };
    };

    $pages = [
        [
            'out' => 'index.html',
            'root' => '',
            'active' => 'index',
            'title' => 'САНС - Упаковочные материалы и складское оборудование в Перми',
            'description' => 'САНС - упаковочные материалы и складское оборудование в Перми с 1995 года',
            'body' => $fill('index.html', ''),
        ],
        [
            'out' => 'pages/about.html',
            'root' => '../',
            'active' => 'about',
            'title' => 'О компании - САНС',
            'description' => 'О компании САНС - 30 лет на рынке упаковочных материалов в Перми',
            'body' => $fill('about.html', '../'),
        ],
        [
            'out' => 'pages/packaging.html',
            'root' => '../',
            'active' => 'packaging',
            'title' => 'Товары для упаковки и маркировки - САНС',
            'description' => 'Товары для упаковки и маркировки - скотч, пленки, ленты, пакеты в Перми',
            'body' => function () use ($products) {
                return sans_catalog_body($products, '../');
            },
        ],
        [
            'out' => 'pages/privacy.html',
            'root' => '../',
            'active' => null,
            'title' => 'Политика конфиденциальности - САНС',
            'description' => 'Порядок обработки персональных данных на сайте САНС',
            'body' => $fill('privacy.html', '../'),
        ],
        [
            'out' => 'pages/contacts.html',
            'root' => '../',
            'active' => 'contacts',
            'title' => 'Контакты - САНС',
            'description' => 'Контакты САНС - телефон, адрес, форма обратной связи в Перми',
            'body' => $fill('contacts.html', '../'),
        ],
    ];

    foreach ($products as $product) {
        $pages[] = [
            'out' => 'pages/products/' . $product['slug'] . '.html',
            'root' => '../../',
            'active' => 'packaging',
            'title' => $product['pageTitle'],
            'description' => $product['metaDescription'],
            'showPopup' => true,
            'product' => $product,
            'body' => function () use ($product, $products) {
                return sans_product_body($product, $products, '../../', 'pages/products');
            },
        ];
    }

    return $pages;
}

function sans_build_sitemap($site, $pages)
{
    $siteUrl = rtrim(isset($site['url']) ? $site['url'] : '', '/');
    if ($siteUrl === '') return null;

    $today = gmdate('Y-m-d');
    $urls = [];
    foreach ($pages as $page) {
        $loc = $page['out'] === 'index.html' ? $siteUrl . '/' : $siteUrl . '/' . $page['out'];
        // главная и каталог обновляются чаще карточек
        $priority = $page['out'] === 'index.html' ? '1.0'
            : ($page['out'] === 'pages/packaging.html' ? '0.9'
            : (!empty($page['product']) ? '0.7' : '0.6'));
        $loc = sans_esc($loc);
        $urls[] = "    <url>\n        <loc>{$loc}</loc>\n        <lastmod>{$today}</lastmod>\n        <priority>{$priority}</priority>\n    </url>";
    }

    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
        . implode("\n", $urls) . "\n"
        . "</urlset>\n";
}

function sans_build_robots($site)
{
    $siteUrl = rtrim(isset($site['url']) ? $site['url'] : '', '/');
    $lines = ['User-agent: *', 'Allow: /', ''];
    if ($siteUrl !== '') {
        array_push($lines, 'Sitemap: ' . $siteUrl . '/sitemap.xml', '');
    }
    return implode("\n", $lines);
}

/**
 * Собирает весь сайт. Записываются только изменившиеся файлы.
 *
 * $checkOnly = true — ничего не пишет, только перечисляет устаревшие файлы.
 * Возвращает ['pages' => N, 'written' => [...], 'stale' => [...], 'errors' => [...]].
 */
function sans_build_all($checkOnly = false)
{
    $result = ['pages' => 0, 'written' => [], 'stale' => [], 'errors' => []];

    $site = sans_read_json(SANS_SITE_FILE);
    $products = sans_read_json(SANS_PRODUCTS_FILE);
    if ($site === null || $products === null) {
        $result['errors'][] = 'Не удалось прочитать data/site.json или data/products.json — сайт не пересобран.';
        return $result;
    }

    // Сначала собираем всё в памяти: если какая-то страница не соберётся,
    // на диске не должно остаться половины старого сайта и половины нового.
    $files = [];
    try {
        $pages = sans_pages($site, $products);
        foreach ($pages as $page) {
            $page['dir'] = dirname($page['out']) === '.' ? '.' : dirname($page['out']);
            $page['body'] = call_user_func($page['body']);
            $files[$page['out']] = sans_layout($site, $page);
        }
        $files['robots.txt'] = sans_build_robots($site);
        $sitemap = sans_build_sitemap($site, $pages);
    } catch (Throwable $e) {
        $result['errors'][] = 'Сайт не пересобран: ' . $e->getMessage();
        return $result;
    }
    $result['pages'] = count($pages);
    if ($sitemap !== null) {
        $files['sitemap.xml'] = $sitemap;
    }

    foreach ($files as $name => $content) {
        $file = SANS_ROOT . '/' . $name;
        $current = is_file($file) ? file_get_contents($file) : null;
        if ($current === $content) {
            continue;
        }
        if ($checkOnly) {
            $result['stale'][] = $name;
            continue;
        }
        if (sans_write_file($file, $content)) {
            $result['written'][] = $name;
        } else {
            $result['errors'][] = 'Не удалось записать ' . $name . '. Проверьте права на папку.';
        }
    }

    // адрес убрали из site.json - старая карта сайта указывала бы в никуда
    if ($sitemap === null && is_file(SANS_ROOT . '/sitemap.xml')) {
        if ($checkOnly) {
            $result['stale'][] = 'sitemap.xml (в site.json не указан адрес сайта)';
        } elseif (@unlink(SANS_ROOT . '/sitemap.xml')) {
            $result['written'][] = 'sitemap.xml (удалён)';
        }
    }

    // страницы удалённых товаров
    $known = [];
    foreach ($products as $p) {
        $known[$p['slug'] . '.html'] = true;
    }
    foreach (glob(SANS_ROOT . '/pages/products/*.html') ?: [] as $file) {
        if (isset($known[basename($file)])) {
            continue;
        }
        $name = 'pages/products/' . basename($file);
        if ($checkOnly) {
            $result['stale'][] = $name . ' (такого товара нет)';
        } elseif (@unlink($file)) {
            $result['written'][] = $name . ' (удалён)';
        } else {
            $result['errors'][] = 'Не удалось удалить ' . $name . '.';
        }
    }

    return $result;
}
