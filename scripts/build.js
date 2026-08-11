/**
 * Сборка сайта: собирает все HTML-страницы из данных и шаблонов.
 *
 *   data/products.json  - товары и варианты
 *   data/site.json      - телефон, адрес, почта, меню
 *   content/*.html      - текст уникальных страниц (главная, о компании, контакты)
 *   scripts/lib/        - шаблоны (шапка, подвал, корзина, карточки)
 *
 * Запуск:  node scripts/build.js
 *          node scripts/build.js --check   (проверить, что файлы актуальны)
 */
const fs = require('fs');
const path = require('path');
const { layout, catalogBody, productBody } = require('./lib/templates');

const ROOT = path.join(__dirname, '..');
const CHECK_ONLY = process.argv.includes('--check');

const site = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/site.json'), 'utf8'));
const products = JSON.parse(fs.readFileSync(path.join(ROOT, 'data/products.json'), 'utf8'));

const readContent = (name) => fs.readFileSync(path.join(ROOT, 'content', name), 'utf8');

// Подстановки в текстовых страницах: {{root}}, {{productCount}}, {{phone}} и т.д.
function fill(text, root) {
    // доступны все строковые поля site.json плюс служебные
    const tokens = {
        ...Object.fromEntries(Object.entries(site).filter(([, v]) => typeof v === 'string')),
        root,
        productCount: String(products.length),
        productCountWord: productWord(products.length)
    };
    return text.replace(/\{\{(\w+)\}\}/g, (match, key) => {
        if (!(key in tokens)) throw new Error(`Неизвестная подстановка {{${key}}}`);
        return tokens[key];
    });
}

function productWord(count) {
    const last = count % 10;
    const lastTwo = count % 100;
    if (lastTwo >= 11 && lastTwo <= 19) return 'товаров';
    if (last === 1) return 'товар';
    if (last >= 2 && last <= 4) return 'товара';
    return 'товаров';
}

// --- список страниц ----------------------------------------------------

const pages = [
    {
        out: 'index.html',
        root: '',
        active: 'index',
        title: 'САНС - Упаковочные материалы и складское оборудование в Перми',
        description: 'САНС - упаковочные материалы и складское оборудование в Перми с 1995 года',
        body: () => fill(readContent('index.html'), '')
    },
    {
        out: 'pages/about.html',
        root: '../',
        active: 'about',
        title: 'О компании - САНС',
        description: 'О компании САНС - 30 лет на рынке упаковочных материалов в Перми',
        body: () => fill(readContent('about.html'), '../')
    },
    {
        out: 'pages/packaging.html',
        root: '../',
        active: 'packaging',
        title: 'Товары для упаковки и маркировки - САНС',
        description: 'Товары для упаковки и маркировки - скотч, пленки, ленты, пакеты в Перми',
        body: () => catalogBody(products, { root: '../' })
    },
    {
        out: 'pages/contacts.html',
        root: '../',
        active: 'contacts',
        title: 'Контакты - САНС',
        description: 'Контакты САНС - телефон, адрес, форма обратной связи в Перми',
        body: () => fill(readContent('contacts.html'), '../')
    }
];

for (const product of products) {
    pages.push({
        out: `pages/products/${product.slug}.html`,
        root: '../../',
        active: 'packaging',
        title: product.pageTitle,
        description: product.metaDescription,
        showPopup: true,
        body: () => productBody(product, products, { root: '../../', dir: 'pages/products' })
    });
}

// --- запись ------------------------------------------------------------

let written = 0;
let stale = 0;

for (const page of pages) {
    const dir = path.posix.dirname(page.out);
    const html = layout(site, { ...page, dir, body: page.body() });
    const file = path.join(ROOT, page.out);
    const current = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : null;

    if (current === html) continue;

    if (CHECK_ONLY) {
        stale++;
        console.log(`устарел: ${page.out}`);
        continue;
    }

    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, html, 'utf8');
    written++;
}

if (CHECK_ONLY) {
    console.log(stale
        ? `\nСтраниц не совпадает с данными: ${stale}. Запустите: node scripts/build.js`
        : `Все ${pages.length} страниц актуальны`);
    process.exit(stale ? 1 : 0);
}

const variants = products.reduce((sum, p) => sum + p.variants.length, 0);
console.log(`Собрано страниц: ${pages.length} (изменено: ${written})`);
console.log(`Товаров: ${products.length}, вариантов: ${variants}`);
