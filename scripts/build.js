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
        product,
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

// --- sitemap.xml и robots.txt -----------------------------------------

const siteUrl = (site.url || '').replace(/\/+$/, '');

function buildSitemap() {
    if (!siteUrl) return null;

    const today = new Date().toISOString().slice(0, 10);
    const urls = pages.map((page) => {
        const loc = page.out === 'index.html' ? `${siteUrl}/` : `${siteUrl}/${page.out}`;
        // главная и каталог обновляются чаще карточек
        const priority = page.out === 'index.html' ? '1.0'
            : page.out === 'pages/packaging.html' ? '0.9'
            : page.product ? '0.7' : '0.6';
        return `    <url>
        <loc>${loc}</loc>
        <lastmod>${today}</lastmod>
        <priority>${priority}</priority>
    </url>`;
    });

    return `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls.join('\n')}
</urlset>
`;
}

function buildRobots() {
    const lines = ['User-agent: *', 'Allow: /', ''];
    if (siteUrl) lines.push(`Sitemap: ${siteUrl}/sitemap.xml`, '');
    return lines.join('\n');
}

const extraFiles = [['robots.txt', buildRobots()]];
const sitemap = buildSitemap();
if (sitemap) {
    extraFiles.push(['sitemap.xml', sitemap]);
} else if (fs.existsSync(path.join(ROOT, 'sitemap.xml'))) {
    // адрес убрали из site.json - старая карта сайта указывала бы в никуда
    if (CHECK_ONLY) {
        stale++;
        console.log('лишний: sitemap.xml (в site.json не указан адрес сайта)');
    } else {
        fs.unlinkSync(path.join(ROOT, 'sitemap.xml'));
        written++;
    }
}

for (const [name, content] of extraFiles) {
    const file = path.join(ROOT, name);
    const current = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : null;
    if (current === content) continue;

    if (CHECK_ONLY) {
        stale++;
        console.log(`устарел: ${name}`);
        continue;
    }
    fs.writeFileSync(file, content, 'utf8');
    written++;
}

if (CHECK_ONLY) {
    console.log(stale
        ? `\nФайлов не совпадает с данными: ${stale}. Запустите: node scripts/build.js`
        : `Все ${pages.length} страниц актуальны`);
    process.exit(stale ? 1 : 0);
}

const variants = products.reduce((sum, p) => sum + p.variants.length, 0);
console.log(`Собрано страниц: ${pages.length} (изменено: ${written})`);
console.log(`Товаров: ${products.length}, вариантов: ${variants}`);

if (!siteUrl) {
    console.log(`
ВНИМАНИЕ: в data/site.json не указан адрес сайта ("url").
Пока он пустой, не создаются: canonical, og:url, og:image и sitemap.xml.
Впишите адрес (например https://sans.ru) и запустите сборку заново.`);
}
