<?php
/**
 * Сборка сайта с компьютера. Тот же сборщик вызывает админка на хостинге.
 *
 * Запуск:  php scripts/build.php
 *          php scripts/build.php --check   (проверить, что файлы актуальны)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/build.php';

$checkOnly = in_array('--check', $argv, true);
$res = sans_build_all($checkOnly);

foreach ($res['errors'] as $e) {
    fwrite(STDERR, $e . "\n");
}
if ($res['errors']) {
    exit(1);
}

if ($checkOnly) {
    foreach ($res['stale'] as $name) {
        echo "устарел: {$name}\n";
    }
    echo $res['stale']
        ? "\nФайлов не совпадает с данными: " . count($res['stale']) . ". Запустите: php scripts/build.php\n"
        : "Все {$res['pages']} страниц актуальны\n";
    exit($res['stale'] ? 1 : 0);
}

$site = sans_load_site();
$products = sans_load_products();
$variants = 0;
foreach ($products as $p) {
    $variants += count($p['variants']);
}
echo "Собрано страниц: {$res['pages']} (изменено: " . count($res['written']) . ")\n";
echo 'Товаров: ' . count($products) . ", вариантов: {$variants}\n";

if (empty($site['formEndpoint'])) {
    echo <<<TXT

ВНИМАНИЕ: в data/site.json не указан адрес приёма заявок ("formEndpoint").
Формы просят позвонить вместо отправки. Впишите адрес обработчика
(например https://formspree.io/f/xxxxxxx) и запустите сборку заново.

TXT;
}

if (empty($site['url'])) {
    echo <<<TXT

ВНИМАНИЕ: в data/site.json не указан адрес сайта ("url").
Пока он пустой, не создаются: canonical, og:url, og:image и sitemap.xml.
Впишите адрес (например https://sans.ru) и запустите сборку заново.

TXT;
}
