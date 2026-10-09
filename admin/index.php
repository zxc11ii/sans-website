<?php
/**
 * Панель управления сайтом.
 *
 * Позволяет заказчику самому: менять названия, описания, характеристики,
 * варианты и фото товаров, добавлять и удалять товары, менять их порядок,
 * править телефоны, почту и адрес. Данные лежат в data/products.json и
 * data/site.json; после каждого сохранения страницы сайта пересобираются.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/build.php';

define('SANS_PHOTO_DIR', SANS_ROOT . '/images/products');
define('SANS_PHOTO_URL', 'images/products');
// Заглушка для товара без фото, чтобы карточка в каталоге не была пустой
define('SANS_NO_PHOTO', 'images/logo.png');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                  || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
]);
session_name('sans_admin');
session_start();

// Админку нельзя встраивать в чужие страницы (защита от кликджекинга).
// <meta http-equiv="X-Frame-Options"> браузеры игнорируют — только заголовком.
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

// ------------------------------------------------------------------
// Вспомогательное
// ------------------------------------------------------------------

function a_e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Сообщение на следующей странице. Текст обычный, экранируется при выводе. */
function a_flash($msg, $type = 'ok')
{
    $_SESSION['flash'][] = ['text' => $msg, 'type' => $type];
}

function a_redirect($qs = '')
{
    header('Location: index.php' . ($qs ? '?' . $qs : ''));
    exit;
}

function a_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function a_check_token()
{
    $sent = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Сессия устарела. Обновите страницу и повторите.');
    }
}

function a_is_auth()
{
    return !empty($_SESSION['auth']);
}

function a_post($name)
{
    return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
}

/** Пересобирает сайт и сообщает о проблемах. */
function a_rebuild()
{
    $res = sans_build_all();
    foreach ($res['errors'] as $e) {
        a_flash($e, 'err');
    }
    return !$res['errors'];
}

// Помощник для смены пароля: /admin/?newhash=новый-пароль
if (isset($_GET['newhash'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Скопируйте строку ниже в admin/config.php, в SANS_ADMIN_HASH:\n\n";
    echo password_hash((string)$_GET['newhash'], PASSWORD_DEFAULT), "\n";
    exit;
}

// ------------------------------------------------------------------
// Вход и выход
// ------------------------------------------------------------------

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    a_redirect();
}

$loginError = '';

/*
 * Счётчик неверных попыток ведём по IP в файле, а не в сессии: бот просто
 * не сохраняет cookie и с сессионным счётчиком получал бы бесконечные попытки.
 * Файл .php с exit в первой строке — если хостинг отдаёт статику через nginx
 * в обход .htaccess, содержимое всё равно наружу не выдаётся.
 */
define('SANS_GUARD_FILE', SANS_ROOT . '/data/login-guard.php');

function a_guard_load()
{
    if (!is_file(SANS_GUARD_FILE)) {
        return [];
    }
    $raw = (string)@file_get_contents(SANS_GUARD_FILE);
    $data = json_decode(substr($raw, strpos($raw, "\n") + 1), true);
    return is_array($data) ? $data : [];
}

function a_guard_save($data)
{
    $now = time();
    foreach ($data as $ip => $row) {   // старые записи не копим
        if ($row['until'] < $now && $row['last'] < $now - SANS_LOGIN_LOCKOUT) {
            unset($data[$ip]);
        }
    }
    @file_put_contents(SANS_GUARD_FILE, "<?php exit; ?>\n" . json_encode($data), LOCK_EX);
}

if (!a_is_auth() && isset($_POST['password'])) {
    $now = time();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $guard = a_guard_load();
    $row = isset($guard[$ip]) ? $guard[$ip] : ['fails' => 0, 'until' => 0, 'last' => 0];

    if ($row['until'] > $now) {
        $loginError = 'Слишком много попыток. Подождите ' . ceil(($row['until'] - $now) / 60) . ' мин.';
    } elseif (password_verify((string)$_POST['password'], SANS_ADMIN_HASH)) {
        unset($guard[$ip]);
        a_guard_save($guard);
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        a_redirect();
    } else {
        // серия неудач «забывается», если последняя была давно
        if ($row['last'] < $now - SANS_LOGIN_LOCKOUT) {
            $row['fails'] = 0;
        }
        $row['fails']++;
        $row['last'] = $now;
        if ($row['fails'] >= SANS_LOGIN_ATTEMPTS) {
            $row['until'] = $now + SANS_LOGIN_LOCKOUT;
            $row['fails'] = 0;
            $loginError = 'Слишком много попыток. Вход заблокирован на 15 минут.';
        } else {
            $loginError = 'Неверный пароль. Осталось попыток: ' . (SANS_LOGIN_ATTEMPTS - $row['fails']);
        }
        $guard[$ip] = $row;
        a_guard_save($guard);
        usleep(400000); // притормаживаем перебор
    }
}

// ------------------------------------------------------------------
// Фотографии
// ------------------------------------------------------------------

/** Значение из php.ini вида «8M» / «512K» в байтах. -1 = без ограничения. */
function a_ini_bytes($v)
{
    $v = trim((string)$v);
    if ($v === '' || $v === '-1') {
        return -1;
    }
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

/** Сколько реально можно загрузить: наш лимит или лимит хостинга, что меньше. */
function a_upload_limit_mb()
{
    $file = SANS_MAX_UPLOAD;
    $ini = a_ini_bytes(ini_get('upload_max_filesize'));
    if ($ini > 0) {
        $file = min($file, $ini);
    }
    $post = a_ini_bytes(ini_get('post_max_size'));
    if ($post > 0) {
        $file = min($file, $post);
    }
    return round($file / 1048576, 1);
}

/** Транслит для имён файлов и адресов страниц. */
function a_slug($text, $fallback = 'tovar')
{
    $lower = 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя';
    $upper = 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ';
    $lat = ['a','b','v','g','d','e','e','zh','z','i','y','k','l','m','n','o','p','r',
            's','t','u','f','h','c','ch','sh','sch','','y','','e','yu','ya'];
    $lo = preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY);
    $up = preg_split('//u', $upper, -1, PREG_SPLIT_NO_EMPTY);
    $map = [];
    foreach ($lo as $i => $ch) {
        $map[$ch] = $lat[$i];
        $map[$up[$i]] = $lat[$i];
    }
    $text = strtolower(strtr((string)$text, $map));
    $text = trim(preg_replace('/[^a-z0-9]+/', '-', $text), '-');
    $text = trim(substr($text, 0, 40), '-');
    return $text === '' ? $fallback : $text;
}

/** Поворачивает картинку по EXIF-пометке Orientation (1–8). */
function a_apply_exif_orientation($img, $path)
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($path);
    $o = isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;
    if ($o < 2 || $o > 8) {
        return $img;
    }
    // 2,4,5,7 — зеркальные варианты: сначала отражаем по горизонтали
    if (in_array($o, [2, 4, 5, 7], true)) {
        imageflip($img, IMG_FLIP_HORIZONTAL);
    }
    // imagerotate крутит против часовой стрелки
    $angle = [3 => 180, 4 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90];
    if (isset($angle[$o])) {
        $rotated = imagerotate($img, $angle[$o], 0);
        if ($rotated) {
            imagedestroy($img);
            $img = $rotated;
        }
    }
    return $img;
}

/**
 * Принимает загруженный файл из $_FILES[$field]: проверяет, что это настоящая
 * картинка, ужимает и кладёт в images/products.
 * Возвращает путь вида images/products/имя.jpg, null — если файл не выбран,
 * или ['error' => текст].
 */
function a_handle_upload($field, $nameBase)
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || is_array($_FILES[$field]['error'])) {
        return null;
    }
    $f = $_FILES[$field];
    $orig = (string)$f['name'];
    switch ($f['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return null;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['error' => 'Файл «' . $orig . '» больше, чем разрешает хостинг (' . a_upload_limit_mb() . ' МБ). Уменьшите фото и загрузите снова.'];
        case UPLOAD_ERR_PARTIAL:
            return ['error' => 'Файл «' . $orig . '» загрузился не полностью — попробуйте ещё раз.'];
        default:
            return ['error' => 'Хостинг не смог принять файл «' . $orig . '» (код ошибки ' . (int)$f['error'] . '). Сообщите разработчику.'];
    }

    $tmp = $f['tmp_name'];
    if ($f['size'] > SANS_MAX_UPLOAD) {
        return ['error' => 'Файл больше ' . round(SANS_MAX_UPLOAD / 1048576) . ' МБ: ' . $orig];
    }

    // Тип определяем по содержимому, а не по расширению — расширению верить нельзя
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return ['error' => 'Поддерживаются только фото JPG, PNG и WEBP: ' . $orig];
    }

    if (!is_dir(SANS_PHOTO_DIR)) {
        @mkdir(SANS_PHOTO_DIR, 0755, true);
    }

    $base = a_slug($nameBase, 'foto');
    $gd = function_exists('imagecreatetruecolor');
    // без GD сохраняем как есть, поэтому и расширение исходное
    $ext = $gd ? 'jpg' : [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
    $name = $base . '.' . $ext;
    for ($n = 2; file_exists(SANS_PHOTO_DIR . '/' . $name); $n++) {
        $name = $base . '-' . $n . '.' . $ext;
    }
    $dest = SANS_PHOTO_DIR . '/' . $name;

    if (!$gd) {
        if (!move_uploaded_file($tmp, $dest)) {
            return ['error' => 'Не удалось сохранить файл: ' . $orig];
        }
        return SANS_PHOTO_URL . '/' . $name;
    }

    // Распакованная картинка занимает ~5 байт на пиксель: снимок 50 Мп — около
    // 250 МБ. Если это больше memory_limit, PHP падает с белым экраном.
    // Пробуем поднять лимит, а если нельзя — понятная ошибка.
    $need = (int)($info[0] * $info[1] * 5.2) + memory_get_usage() + 16 * 1048576;
    $limit = a_ini_bytes(ini_get('memory_limit'));
    if ($limit > 0 && $need > $limit && function_exists('ini_set')) {
        @ini_set('memory_limit', (string)(int)ceil($need / 1048576) . 'M');
        $limit = a_ini_bytes(ini_get('memory_limit'));
    }
    if ($limit > 0 && $need > $limit) {
        return ['error' => 'Фото «' . $orig . '» слишком большое по разрешению ('
            . round($info[0] * $info[1] / 1e6) . ' Мп) для сервера. Сохраните его в меньшем'
            . ' размере (например, перешлите себе в мессенджере) и загрузите снова.'];
    }

    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($tmp); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($tmp);  break;
        default:             $src = @imagecreatefromwebp($tmp); break;
    }
    if (!$src) {
        return ['error' => 'Не удалось прочитать изображение: ' . $orig];
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, SANS_IMG_MAX_SIDE / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    // прозрачность у PNG заливаем белым, иначе в JPEG она станет чёрной
    imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    // Телефоны часто пишут кадр «лёжа» и ставят в EXIF пометку, как его повернуть.
    // GD эту пометку не читает, и вертикальное фото без поворота легло бы на бок.
    if ($info[2] === IMAGETYPE_JPEG) {
        $dst = a_apply_exif_orientation($dst, $tmp);
    }

    $ok = imagejpeg($dst, $dest, SANS_JPEG_QUALITY);
    imagedestroy($dst);
    if (!$ok) {
        return ['error' => 'Не удалось сохранить изображение: ' . $orig];
    }
    return SANS_PHOTO_URL . '/' . $name;
}

/** Используется ли картинка где-нибудь на сайте — тогда файл не удаляем. */
function a_image_in_use($products, $path)
{
    foreach ($products as $p) {
        if ($p['image'] === $path || $p['mainImage'] === $path) {
            return true;
        }
        foreach ($p['variants'] as $v) {
            if ($v['image'] === $path || $v['photo'] === $path) {
                return true;
            }
        }
    }
    $site = sans_load_site();
    if (isset($site['ogImage']) && $site['ogImage'] === $path) {
        return true;
    }
    // фото могут быть вставлены в тексты главной и других страниц
    foreach (glob(SANS_ROOT . '/content/*.html') ?: [] as $file) {
        if (strpos((string)file_get_contents($file), $path) !== false) {
            return true;
        }
    }
    return false;
}

/** Удаляет файл фото, если он больше нигде не нужен. Только внутри images/products. */
function a_delete_image_if_unused($products, $path)
{
    if (strpos($path, SANS_PHOTO_URL . '/') !== 0 || a_image_in_use($products, $path)) {
        return;
    }
    $file = SANS_PHOTO_DIR . '/' . basename($path);   // защита от путей вида ../../
    if (is_file($file) && !@unlink($file)) {
        a_flash('Файл старого фото удалить не удалось: ' . basename($path) . '. Проверьте права на папку images/products.', 'err');
    }
}

// ------------------------------------------------------------------
// Разбор полей формы товара
// ------------------------------------------------------------------

/** «Ширина: 9-150 мм» по строке → [['label' => 'Ширина', 'value' => '9-150 мм'], ...] */
function a_parse_specs($text, &$warnings)
{
    $out = [];
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) {
            $warnings[] = 'В характеристике «' . $line . '» нет двоеточия — она записана как название без значения.';
            $out[] = ['label' => $line, 'value' => ''];
            continue;
        }
        $out[] = ['label' => trim(substr($line, 0, $pos)), 'value' => trim(substr($line, $pos + 1))];
    }
    return $out;
}

function a_specs_text($specs)
{
    $lines = [];
    foreach ($specs as $s) {
        $lines[] = $s['label'] . ': ' . $s['value'];
    }
    return implode("\n", $lines);
}

function a_parse_lines($text)
{
    $out = [];
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/** Номер товара в массиве по адресу страницы. */
function a_find($products, $slug)
{
    foreach ($products as $i => $p) {
        if ($p['slug'] === $slug) {
            return $i;
        }
    }
    return null;
}

/** Все id вариантов: корзина различает позиции по ним, повторяться они не должны. */
function a_variant_ids($products)
{
    $ids = [];
    foreach ($products as $p) {
        foreach ($p['variants'] as $v) {
            $ids[$v['id']] = true;
        }
    }
    return $ids;
}

/** Телефон «+7 (342) 210-33-00» → «+73422103300» для ссылки tel: */
function a_phone_href($phone)
{
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    }
    return '+' . $digits;
}

// ------------------------------------------------------------------
// Действия
// ------------------------------------------------------------------

// Если файл больше, чем хостинг принимает за одну отправку (post_max_size),
// PHP выбрасывает всю форму целиком. Без этой проверки заказчик видел бы
// непонятное «Сессия устарела».
if (a_is_auth() && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)
    && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    a_flash('Фото слишком большое: хостинг принимает до ' . a_upload_limit_mb()
        . ' МБ. Ничего не сохранено — уменьшите фото и попробуйте снова.', 'err');
    $back = '';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        parse_str((string)parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY), $q);
        $back = http_build_query(array_intersect_key($q, ['edit' => 1, 'tab' => 1]));
    }
    a_redirect($back);
}

if (a_is_auth() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    a_check_token();
    $action = a_post('action');
    $products = sans_load_products();
    $slug = a_post('slug');
    $idx = $slug !== '' ? a_find($products, $slug) : null;

    // --- сохранение товара ---
    if ($action === 'save') {
        if ($idx === null) {
            a_flash('Товар не найден — возможно, его уже удалили.', 'err');
            a_redirect();
        }
        $p = $products[$idx];
        $back = 'edit=' . rawurlencode($slug);
        $warnings = [];

        $title = a_post('title');
        if ($title === '') {
            a_flash('Название не может быть пустым. Ничего не сохранено.', 'err');
            a_redirect($back);
        }
        $p['title'] = $title;
        $p['cardTitle'] = a_post('cardTitle') !== '' ? a_post('cardTitle') : $title;
        $p['shortDescription'] = a_post('shortDescription');
        $p['description'] = a_post('description');
        $p['pageTitle'] = a_post('pageTitle') !== '' ? a_post('pageTitle') : $title . ' - САНС';
        $p['metaDescription'] = a_post('metaDescription') !== '' ? a_post('metaDescription') : $p['shortDescription'];
        $p['specs'] = a_parse_specs(a_post('specs'), $warnings);
        $p['features'] = a_parse_lines(a_post('features'));

        $orderUrl = a_post('orderUrl');
        if ($orderUrl !== '' && !preg_match('~^https?://[^\s<>"]+$~i', $orderUrl)) {
            $warnings[] = 'Ссылка «Заказать на сайте» должна начинаться с https:// — оставлена прежняя.';
        } else {
            $p['orderUrl'] = $orderUrl;
        }

        $price = preg_replace('/\D/', '', a_post('price'));
        $p['price'] = $price === '' ? null : (int)$price;
        $p['sku'] = a_post('sku') !== '' ? a_post('sku') : null;
        $p['unit'] = a_post('unit') !== '' ? a_post('unit') : null;

        // похожие товары: уже отмеченные остаются на своих местах, новые — в конец
        $checked = isset($_POST['related']) && is_array($_POST['related']) ? array_map('strval', $_POST['related']) : [];
        $related = [];
        foreach ($p['related'] as $r) {
            if (in_array($r, $checked, true)) {
                $related[] = $r;
            }
        }
        foreach ($products as $other) {
            if ($other['slug'] !== $slug && in_array($other['slug'], $checked, true) && !in_array($other['slug'], $related, true)) {
                $related[] = $other['slug'];
            }
        }
        $p['related'] = $related;

        // варианты: существующие узнаём по id, пустые новые строки пропускаем
        $old = [];
        foreach ($p['variants'] as $v) {
            $old[$v['id']] = $v;
        }
        $usedIds = a_variant_ids($products);
        $variants = [];
        $rows = isset($_POST['v']) && is_array($_POST['v']) ? $_POST['v'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? (string)$row['id'] : '';
            $name = isset($row['name']) ? trim((string)$row['name']) : '';
            $full = isset($row['fullName']) ? trim((string)$row['fullName']) : '';
            if (!empty($row['del']) || ($name === '' && $full === '')) {
                continue;
            }
            if ($name === '') $name = $full;
            if ($full === '') $full = $name;

            if ($id !== '' && isset($old[$id])) {
                $v = $old[$id];
            } else {
                $base = $slug . '-' . a_slug($name, 'variant');
                $newId = $base;
                for ($n = 2; isset($usedIds[$newId]); $n++) {
                    $newId = $base . '-' . $n;
                }
                $usedIds[$newId] = true;
                $v = ['id' => $newId, 'name' => '', 'fullName' => '', 'image' => $p['image'], 'photo' => null];
            }
            $v['name'] = $name;
            $v['fullName'] = $full;
            $variants[] = $v;
        }
        $p['variants'] = $variants;

        // новое главное фото
        $oldImage = $p['image'];
        $up = a_handle_upload('photo', $p['slug']);
        if (is_array($up)) {
            $warnings[] = $up['error'] . ' Остальные изменения сохранены.';
        } elseif ($up !== null) {
            $p['image'] = $up;
            $p['mainImage'] = $up;
            // варианты с той же картинкой показываются в корзине — меняем и им
            foreach ($p['variants'] as &$v) {
                if ($v['image'] === $oldImage) {
                    $v['image'] = $up;
                }
            }
            unset($v);
        }

        $products[$idx] = $p;
        if (sans_save_json(SANS_PRODUCTS_FILE, $products)) {
            if ($up !== null && !is_array($up)) {
                a_delete_image_if_unused($products, $oldImage);
            }
            if (a_rebuild()) {
                a_flash('Сохранено: ' . $title . '. Сайт обновлён.');
            }
        } else {
            a_flash('Не удалось сохранить. Проверьте права на папку data.', 'err');
        }
        foreach ($warnings as $w) {
            a_flash($w, 'err');
        }
        a_redirect($back);
    }

    // --- добавление товара ---
    if ($action === 'add') {
        $title = a_post('title');
        if ($title === '') {
            a_flash('Укажите название товара.', 'err');
            a_redirect();
        }
        $base = a_slug($title);
        $newSlug = $base;
        for ($n = 2; a_find($products, $newSlug) !== null; $n++) {
            $newSlug = $base . '-' . $n;
        }

        $image = SANS_NO_PHOTO;
        $up = a_handle_upload('photo', $newSlug);
        if (is_array($up)) {
            a_flash($up['error'] . ' Товар добавлен без фото.', 'err');
        } elseif ($up !== null) {
            $image = $up;
        }

        $short = a_post('shortDescription');
        $products[] = [
            'slug' => $newSlug,
            'title' => $title,
            'pageTitle' => $title . ' - САНС',
            'metaDescription' => $short,
            'shortDescription' => $short,
            'cardTitle' => $title,
            'description' => $short,
            'image' => $image,
            'mainImage' => $image,
            'orderUrl' => '',
            'sku' => null,
            'price' => null,
            'unit' => null,
            'specs' => [],
            'features' => [],
            'variants' => [],
            'related' => [],
        ];
        if (sans_save_json(SANS_PRODUCTS_FILE, $products)) {
            if (a_rebuild()) {
                a_flash('Товар добавлен: ' . $title . '. Заполните описание, характеристики и варианты.');
            }
            if ($image === SANS_NO_PHOTO) {
                a_flash('Фото не загружено — пока в карточке стоит логотип.', 'err');
            }
            a_redirect('edit=' . rawurlencode($newSlug));
        }
        a_flash('Не удалось сохранить. Проверьте права на папку data.', 'err');
        a_redirect();
    }

    // --- удаление товара ---
    if ($action === 'delete') {
        if ($idx === null) {
            a_redirect();
        }
        $removed = $products[$idx];
        array_splice($products, $idx, 1);
        // убираем его из «Похожих товаров» у остальных
        foreach ($products as &$other) {
            $other['related'] = array_values(array_diff($other['related'], [$slug]));
        }
        unset($other);

        if (sans_save_json(SANS_PRODUCTS_FILE, $products)) {
            a_delete_image_if_unused($products, $removed['image']);
            a_delete_image_if_unused($products, $removed['mainImage']);
            if (a_rebuild()) {
                a_flash('Товар удалён: ' . $removed['title'] . '.');
            }
        } else {
            a_flash('Не удалось сохранить. Проверьте права на папку data.', 'err');
        }
        a_redirect();
    }

    // --- перемещение товара вверх/вниз в каталоге ---
    if ($action === 'move') {
        $j = $idx === null ? null : $idx + (a_post('dir') === 'up' ? -1 : 1);
        if ($j !== null && $j >= 0 && $j < count($products)) {
            $tmp = $products[$idx];
            $products[$idx] = $products[$j];
            $products[$j] = $tmp;
            if (sans_save_json(SANS_PRODUCTS_FILE, $products)) {
                a_rebuild();
            } else {
                a_flash('Не удалось сохранить. Проверьте права на папку data.', 'err');
            }
        }
        a_redirect();
    }

    // --- контакты ---
    if ($action === 'site') {
        $site = sans_load_site();
        $errors = [];
        foreach (['phone' => 'Основной телефон', 'phoneSecond' => 'Второй телефон', 'phoneMessenger' => 'Телефон для мессенджеров'] as $key => $label) {
            $val = a_post($key);
            if (strlen(preg_replace('/\D/', '', $val)) < 10) {
                $errors[] = $label . ': нужно не меньше 10 цифр.';
                continue;
            }
            $site[$key] = $val;
            $site[$key . 'Href'] = a_phone_href($val);
        }
        $email = a_post('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Почта указана неверно.';
        } else {
            $site['email'] = $email;
        }
        foreach (['address', 'streetAddress'] as $key) {
            if (a_post($key) !== '') {
                $site[$key] = a_post($key);
            }
        }

        if ($errors) {
            foreach ($errors as $e) {
                a_flash($e, 'err');
            }
            a_flash('Остальные поля сохранены.', 'err');
        }
        if (sans_save_json(SANS_SITE_FILE, $site)) {
            if (a_rebuild() && !$errors) {
                a_flash('Контакты сохранены. Сайт обновлён.');
            }
        } else {
            a_flash('Не удалось сохранить. Проверьте права на папку data.', 'err');
        }
        a_redirect('tab=site');
    }

    a_redirect();
}

// ------------------------------------------------------------------
// Вывод
// ------------------------------------------------------------------

$flash = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
unset($_SESSION['flash']);

$products = a_is_auth() ? sans_load_products() : [];
$site = a_is_auth() ? sans_load_site() : [];
$tab = isset($_GET['tab']) && $_GET['tab'] === 'site' ? 'site' : 'products';
$edit = null;
if (a_is_auth() && isset($_GET['edit'])) {
    $i = a_find($products, (string)$_GET['edit']);
    if ($i !== null) {
        $edit = $products[$i];
    }
}
$limitMb = a_upload_limit_mb();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Управление сайтом — САНС</title>
<style>
    :root {
        --red: #C41E3A;
        --red-dark: #8B1428;
        --blue: #2C3E50;
        --bg: #F5F5F5;
        --card: #FFFFFF;
        --line: #E0E0E0;
        --text: #2C2C2C;
        --muted: #6B6B6B;
        --ok: #2E7D4F;
        color-scheme: only light;
        accent-color: var(--red);
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        background: var(--bg);
        color: var(--text);
        font: 15px/1.55 system-ui, "Segoe UI", sans-serif;
        padding: 0 0 60px;
    }
    a { color: var(--red); }
    .bar {
        background: var(--blue);
        color: #fff;
        padding: 12px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px 16px;
        flex-wrap: wrap;
        position: sticky;
        top: 0;
        z-index: 10;
    }
    .bar h1 { font-size: 17px; letter-spacing: 1px; }
    .bar .btn { border-color: rgba(255,255,255,.6); color: #fff; }
    .bar .btn:hover { background: #fff; color: var(--blue); }
    .wrap { max-width: 1000px; margin: 0 auto; padding: 22px 20px; }
    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .tabs a {
        padding: 9px 16px; border: 1px solid var(--line); background: var(--card);
        text-decoration: none; color: var(--text); font-size: 14px; border-radius: 6px;
    }
    .tabs a.on { border-color: var(--red); color: var(--red); font-weight: 600; }
    .flash { padding: 11px 15px; margin-bottom: 10px; border-left: 4px solid var(--ok); background: #E8F4EC; border-radius: 4px; }
    .flash.err { border-left-color: var(--red); background: #FBEAEC; }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
    .card h2 { font-size: 17px; color: var(--blue); margin-bottom: 14px; }
    .card h3 { font-size: 15px; color: var(--blue); margin: 22px 0 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 9px 8px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: middle; }
    th { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); font-weight: 600; }
    td.num { text-align: center; font-variant-numeric: tabular-nums; }
    td.act { white-space: nowrap; text-align: right; }
    .thumb { width: 52px; height: 52px; object-fit: cover; display: block; border-radius: 4px; background: var(--bg); }
    .btn {
        display: inline-block; padding: 8px 14px; border: 1px solid var(--red); border-radius: 6px;
        background: none; color: var(--red); cursor: pointer; font: inherit; font-size: 14px;
        text-decoration: none; line-height: 1.3;
    }
    .btn:hover { background: var(--red); color: #fff; }
    .btn.primary { background: var(--red); color: #fff; }
    .btn.primary:hover { background: var(--red-dark); border-color: var(--red-dark); }
    .btn.small { padding: 4px 9px; font-size: 13px; }
    .btn.ghost { border-color: var(--line); color: var(--text); }
    .btn.ghost:hover { background: var(--bg); color: var(--text); }
    .btn:disabled { opacity: .35; cursor: default; background: none; color: var(--red); }
    input[type=text], input[type=email], input[type=password], textarea {
        background: #fff; border: 1px solid #C9C9C9; color: var(--text); border-radius: 6px;
        padding: 9px 11px; font: inherit; width: 100%;
    }
    textarea { resize: vertical; min-height: 80px; }
    input:focus, textarea:focus, button:focus-visible, a:focus-visible {
        outline: 2px solid var(--red); outline-offset: 1px;
    }
    label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 5px; font-weight: 500; }
    .field { margin-bottom: 15px; }
    .hint { color: var(--muted); font-size: 13px; margin-top: 4px; }
    .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
    .photo-row { display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap; }
    .photo-row img { width: 160px; height: 160px; object-fit: cover; border: 1px solid var(--line); border-radius: 6px; }
    .photo-row .field { flex: 1; min-width: 220px; }
    .checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 6px 16px; }
    .checks label { color: var(--text); font-weight: 400; font-size: 14px; display: flex; gap: 8px; align-items: center; margin: 0; }
    .variants td { padding: 5px 4px; }
    .variants input[type=text] { padding: 6px 8px; font-size: 14px; }
    .variants .new td { background: #FAFAFA; }
    details summary { cursor: pointer; color: var(--blue); font-weight: 600; margin: 18px 0 10px; }
    .actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 8px; }
    .savebar {
        position: sticky; bottom: 0; background: var(--card); border-top: 1px solid var(--line);
        margin: 20px -20px -20px; padding: 14px 20px; border-radius: 0 0 8px 8px;
    }
    .inline { display: inline; }
    .muted { color: var(--muted); font-size: 13px; }
    .login { max-width: 360px; margin: 12vh auto; }
    @media (max-width: 680px) {
        .row2, .row3 { grid-template-columns: 1fr; }
        .wrap { padding: 14px 10px; }
        .card { padding: 14px; }
        .savebar { margin: 16px -14px -14px; padding: 12px 14px; }
        th.hide, td.hide { display: none; }
        .variants th:nth-child(2), .variants td:nth-child(2) { display: none; }
    }
</style>
</head>
<body>

<?php if (!a_is_auth()): ?>

    <div class="wrap login">
        <div class="card">
            <h2>Управление сайтом САНС</h2>
            <?php if ($loginError): ?>
                <div class="flash err"><?= a_e($loginError) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="field">
                    <label for="pw">Пароль</label>
                    <input type="password" id="pw" name="password" autofocus autocomplete="current-password">
                </div>
                <button class="btn primary" type="submit">Войти</button>
            </form>
        </div>
    </div>

<?php else: ?>

    <div class="bar">
        <h1>Управление сайтом — САНС</h1>
        <div>
            <a class="btn small" href="../index.html" target="_blank" rel="noopener">Открыть сайт</a>
            <a class="btn small" href="?logout=1">Выйти</a>
        </div>
    </div>

    <div class="wrap">

        <?php foreach ($flash as $f): ?>
            <div class="flash <?= $f['type'] === 'err' ? 'err' : '' ?>" role="status"><?= a_e($f['text']) ?></div>
        <?php endforeach; ?>

        <nav class="tabs">
            <a href="index.php" class="<?= $tab === 'products' ? 'on' : '' ?>">Товары (<?= count($products) ?>)</a>
            <a href="?tab=site" class="<?= $tab === 'site' ? 'on' : '' ?>">Контакты</a>
        </nav>

<?php if ($tab === 'site'): ?>
        <!-- ================= КОНТАКТЫ ================= -->
        <div class="card">
            <h2>Контакты</h2>
            <p class="muted" style="margin-bottom:16px">Меняются сразу на всех страницах: в шапке, подвале, на странице «Контакты» и в политике конфиденциальности.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= a_token() ?>">
                <input type="hidden" name="action" value="site">
                <div class="row3">
                    <div class="field">
                        <label for="s-phone">Основной телефон</label>
                        <input type="text" id="s-phone" name="phone" inputmode="tel" value="<?= a_e($site['phone'] ?? '') ?>" required>
                    </div>
                    <div class="field">
                        <label for="s-phone2">Второй телефон</label>
                        <input type="text" id="s-phone2" name="phoneSecond" inputmode="tel" value="<?= a_e($site['phoneSecond'] ?? '') ?>" required>
                    </div>
                    <div class="field">
                        <label for="s-phone3">Телефон для мессенджеров</label>
                        <input type="text" id="s-phone3" name="phoneMessenger" inputmode="tel" value="<?= a_e($site['phoneMessenger'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label for="s-email">Почта</label>
                    <input type="email" id="s-email" name="email" value="<?= a_e($site['email'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="s-addr">Адрес (как показывать на сайте)</label>
                    <input type="text" id="s-addr" name="address" value="<?= a_e($site['address'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label for="s-street">Улица и дом — для Яндекса и Google</label>
                    <input type="text" id="s-street" name="streetAddress" value="<?= a_e($site['streetAddress'] ?? '') ?>">
                    <p class="hint">Без города, например: ул. Героев Хасана, 105, корпус 44/2</p>
                </div>
                <button class="btn primary" type="submit">Сохранить</button>
            </form>
        </div>

<?php elseif ($edit): ?>
        <!-- ================= РЕДАКТИРОВАНИЕ ТОВАРА ================= -->
        <?php
            $pageUrl = '../pages/products/' . rawurlencode($edit['slug']) . '.html';
            $isNoPhoto = $edit['image'] === SANS_NO_PHOTO;
        ?>
        <div class="card">
            <h2><?= a_e($edit['title']) ?></h2>
            <p class="muted" style="margin:-8px 0 16px">
                Страница товара: <a href="<?= a_e($pageUrl) ?>" target="_blank" rel="noopener">pages/products/<?= a_e($edit['slug']) ?>.html</a>
            </p>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= a_token() ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="slug" value="<?= a_e($edit['slug']) ?>">

                <div class="photo-row field">
                    <img src="../<?= a_e($edit['image']) ?>" alt="">
                    <div class="field">
                        <label for="e-photo"><?= $isNoPhoto ? 'Загрузить фото' : 'Заменить фото' ?></label>
                        <input type="file" id="e-photo" name="photo" accept="image/jpeg,image/png,image/webp">
                        <p class="hint">JPG, PNG или WEBP до <?= a_e($limitMb) ?> МБ. Большое фото уменьшится автоматически.</p>
                    </div>
                </div>

                <div class="field">
                    <label for="e-title">Название на странице товара</label>
                    <input type="text" id="e-title" name="title" value="<?= a_e($edit['title']) ?>" required>
                </div>
                <div class="row2">
                    <div class="field">
                        <label for="e-card">Название в каталоге</label>
                        <input type="text" id="e-card" name="cardTitle" value="<?= a_e($edit['cardTitle']) ?>">
                    </div>
                    <div class="field">
                        <label for="e-short">Подпись в каталоге</label>
                        <input type="text" id="e-short" name="shortDescription" value="<?= a_e($edit['shortDescription']) ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="e-desc">Описание</label>
                    <textarea id="e-desc" name="description" rows="3"><?= a_e($edit['description']) ?></textarea>
                </div>
                <div class="row2">
                    <div class="field">
                        <label for="e-specs">Характеристики</label>
                        <textarea id="e-specs" name="specs" rows="6"><?= a_e(a_specs_text($edit['specs'])) ?></textarea>
                        <p class="hint">По одной в строке: <b>Ширина: 9-150 мм</b></p>
                    </div>
                    <div class="field">
                        <label for="e-feat">Преимущества</label>
                        <textarea id="e-feat" name="features" rows="6"><?= a_e(implode("\n", $edit['features'])) ?></textarea>
                        <p class="hint">По одному в строке</p>
                    </div>
                </div>
                <div class="field">
                    <label for="e-order">Ссылка «Заказать на сайте»</label>
                    <input type="text" id="e-order" name="orderUrl" inputmode="url" value="<?= a_e($edit['orderUrl']) ?>">
                    <p class="hint">Если оставить пустой, кнопки не будет</p>
                </div>

                <h3>Варианты (<?= count($edit['variants']) ?>)</h3>
                <p class="muted" style="margin-bottom:8px">Позиции с кнопкой «В корзину». Чтобы добавить вариант, заполните пустую строку внизу; чтобы удалить — отметьте галочку.</p>
                <table class="variants">
                    <thead>
                        <tr><th>Коротко (в корзине)</th><th>Полностью (на карточке)</th><th title="Удалить">✕</th></tr>
                    </thead>
                    <tbody>
                    <?php $vi = 0; ?>
                    <?php foreach ($edit['variants'] as $v): ?>
                        <tr>
                            <td>
                                <input type="hidden" name="v[<?= $vi ?>][id]" value="<?= a_e($v['id']) ?>">
                                <input type="text" name="v[<?= $vi ?>][name]" value="<?= a_e($v['name']) ?>" aria-label="Коротко">
                            </td>
                            <td><input type="text" name="v[<?= $vi ?>][fullName]" value="<?= a_e($v['fullName']) ?>" aria-label="Полностью"></td>
                            <td><input type="checkbox" name="v[<?= $vi ?>][del]" value="1" aria-label="Удалить вариант"></td>
                        </tr>
                        <?php $vi++; ?>
                    <?php endforeach; ?>
                    <?php for ($k = 0; $k < 3; $k++, $vi++): ?>
                        <tr class="new">
                            <td><input type="text" name="v[<?= $vi ?>][name]" placeholder="Новый вариант" aria-label="Коротко"></td>
                            <td><input type="text" name="v[<?= $vi ?>][fullName]" placeholder="Полное название (необязательно)" aria-label="Полностью"></td>
                            <td></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>

                <h3>Похожие товары</h3>
                <div class="checks">
                    <?php foreach ($products as $other): ?>
                        <?php if ($other['slug'] === $edit['slug']) continue; ?>
                        <label>
                            <input type="checkbox" name="related[]" value="<?= a_e($other['slug']) ?>"
                                <?= in_array($other['slug'], $edit['related'], true) ? 'checked' : '' ?>>
                            <?= a_e($other['cardTitle']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <details>
                    <summary>Для поисковиков и будущего прайса</summary>
                    <div class="field">
                        <label for="e-ptitle">Заголовок вкладки браузера</label>
                        <input type="text" id="e-ptitle" name="pageTitle" value="<?= a_e($edit['pageTitle']) ?>">
                    </div>
                    <div class="field">
                        <label for="e-meta">Описание для Яндекса и Google</label>
                        <textarea id="e-meta" name="metaDescription" rows="2"><?= a_e($edit['metaDescription']) ?></textarea>
                        <p class="hint">Показывается в результатах поиска под ссылкой. Желательно до 160 символов.</p>
                    </div>
                    <div class="row3">
                        <div class="field">
                            <label for="e-price">Цена, ₽</label>
                            <input type="text" id="e-price" name="price" inputmode="numeric" value="<?= a_e($edit['price']) ?>">
                        </div>
                        <div class="field">
                            <label for="e-sku">Артикул</label>
                            <input type="text" id="e-sku" name="sku" value="<?= a_e($edit['sku']) ?>">
                        </div>
                        <div class="field">
                            <label for="e-unit">Единица</label>
                            <input type="text" id="e-unit" name="unit" value="<?= a_e($edit['unit']) ?>" placeholder="шт., рулон…">
                        </div>
                    </div>
                    <p class="hint">Цена и артикул пока на страницах не показываются — они уходят только в разметку для поисковиков.</p>
                </details>

                <div class="savebar actions">
                    <button class="btn primary" type="submit">Сохранить</button>
                    <a class="btn ghost" href="index.php">Назад к списку</a>
                </div>
            </form>
        </div>

<?php else: ?>
        <!-- ================= СПИСОК ТОВАРОВ ================= -->
        <div class="card">
            <h2>Товары в каталоге</h2>
            <p class="muted" style="margin:-6px 0 12px">Порядок здесь — это порядок карточек на странице «Упаковка».</p>
            <table>
                <thead>
                    <tr>
                        <th>Фото</th>
                        <th>Название</th>
                        <th class="hide" style="text-align:center">Вариантов</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php $last = count($products) - 1; ?>
                <?php foreach ($products as $i => $p): ?>
                    <tr>
                        <td><img class="thumb" src="../<?= a_e($p['image']) ?>" alt="" loading="lazy"></td>
                        <td>
                            <a href="?edit=<?= a_e(rawurlencode($p['slug'])) ?>"><?= a_e($p['cardTitle']) ?></a>
                            <?php if ($p['image'] === SANS_NO_PHOTO): ?><div class="muted">нет фото</div><?php endif; ?>
                        </td>
                        <td class="num hide"><?= count($p['variants']) ?></td>
                        <td class="act">
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf" value="<?= a_token() ?>">
                                <input type="hidden" name="action" value="move">
                                <input type="hidden" name="slug" value="<?= a_e($p['slug']) ?>">
                                <button class="btn small ghost" name="dir" value="up" title="Выше" aria-label="Выше" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
                                <button class="btn small ghost" name="dir" value="down" title="Ниже" aria-label="Ниже" <?= $i === $last ? 'disabled' : '' ?>>↓</button>
                            </form>
                            <a class="btn small" href="?edit=<?= a_e(rawurlencode($p['slug'])) ?>">Изменить</a>
                            <form method="post" class="inline"
                                  onsubmit="return confirm(this.dataset.q)" data-q="Удалить товар «<?= a_e($p['cardTitle']) ?>» вместе со страницей?">
                                <input type="hidden" name="csrf" value="<?= a_token() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="slug" value="<?= a_e($p['slug']) ?>">
                                <button class="btn small" type="submit">Удалить</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Добавить товар</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= a_token() ?>">
                <input type="hidden" name="action" value="add">
                <div class="row2">
                    <div class="field">
                        <label for="n-title">Название</label>
                        <input type="text" id="n-title" name="title" required>
                    </div>
                    <div class="field">
                        <label for="n-short">Подпись в каталоге</label>
                        <input type="text" id="n-short" name="shortDescription" placeholder="Например: ширина 50-100 мм, белый и прозрачный">
                    </div>
                </div>
                <div class="field">
                    <label for="n-photo">Фото</label>
                    <input type="file" id="n-photo" name="photo" accept="image/jpeg,image/png,image/webp">
                    <p class="hint">До <?= a_e($limitMb) ?> МБ. Остальное — описание, характеристики, варианты — заполняется на следующем шаге.</p>
                </div>
                <button class="btn primary" type="submit">Добавить товар</button>
            </form>
        </div>
<?php endif; ?>

    </div>

<?php endif; ?>

</body>
</html>
