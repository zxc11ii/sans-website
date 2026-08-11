/**
 * Разовый скрипт: вытаскивает данные о товарах из существующих HTML-страниц
 * в data/products.json. После перехода на сборку (scripts/build.js) нужен
 * только как справка - товары редактируются в JSON, а не в HTML.
 *
 * Запуск: node scripts/extract-content.js
 */
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');

// --- вспомогательные ---------------------------------------------------

const clean = (s) => s
    .replace(/\s+/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&nbsp;/g, ' ')
    .trim();

// приводит ../../images/x к images/x
const imgPath = (s) => s.replace(/^(\.\.\/)+/, '');

function section(html, name) {
    const re = new RegExp(`<section class="${name}">([\\s\\S]*?)</section>`);
    const m = html.match(re);
    return m ? m[1] : '';
}

function splitCards(html) {
    return html.split(/<div class="product-card">/).slice(1);
}

// --- порядок каталога --------------------------------------------------

const packaging = read('pages/packaging.html');
const catalogOrder = [];
for (const card of splitCards(section(packaging, 'products-page'))) {
    const href = card.match(/href="products\/([a-z-]+)\.html"/);
    const title = card.match(/<h3>([\s\S]*?)<\/h3>/);
    const short = card.match(/<p>([\s\S]*?)<\/p>/);
    const image = card.match(/<img src="([^"]+)"/);
    if (!href) continue;
    catalogOrder.push({
        slug: href[1],
        cardTitle: clean(title[1]),
        shortDescription: clean(short[1]),
        cardImage: imgPath(image[1])
    });
}

// --- страницы товаров --------------------------------------------------

const products = catalogOrder.map((entry) => {
    const file = `pages/products/${entry.slug}.html`;
    const html = read(file);

    const detail = section(html, 'product-detail');
    const variantsHtml = section(html, 'product-variants');
    const relatedHtml = section(html, 'related-products');

    const title = clean(html.match(/<h1>([\s\S]*?)<\/h1>/)[1]);
    // у четырёх страниц meta description не было - подставляем описание из каталога
    const metaMatch = html.match(/<meta name="description" content="([^"]*)"/);
    const metaDescription = metaMatch
        ? clean(metaMatch[1])
        : clean(`${entry.cardTitle} - ${entry.shortDescription}`);
    const pageTitle = clean(html.match(/<title>([\s\S]*?)<\/title>/)[1]);
    const mainImage = imgPath(detail.match(/<div class="product-main-image">[\s\S]*?<img src="([^"]+)"/)[1]);
    const description = clean(detail.match(/<p class="product-description">([\s\S]*?)<\/p>/)[1]);

    // характеристики: <li><strong>Ширина:</strong> 9-150 мм</li>
    const specsBlock = detail.match(/<div class="product-specs">([\s\S]*?)<\/div>/)[1];
    const specs = [...specsBlock.matchAll(/<li>\s*<strong>([\s\S]*?):?<\/strong>([\s\S]*?)<\/li>/g)]
        .map((m) => ({ label: clean(m[1]).replace(/:$/, ''), value: clean(m[2]) }));

    // преимущества: <li>✓ Высокая прочность</li>
    const featuresBlock = detail.match(/<div class="product-features">([\s\S]*?)<\/div>/)[1];
    const features = [...featuresBlock.matchAll(/<li>([\s\S]*?)<\/li>/g)]
        .map((m) => clean(m[1]).replace(/^✓\s*/, ''));

    const orderUrl = (detail.match(/<a href="(https?:[^"]+)"[^>]*class="btn btn-primary"/) || [])[1] || null;

    const variants = splitCards(variantsHtml).map((card, index) => {
        const h3 = clean(card.match(/<h3>([\s\S]*?)<\/h3>/)[1]);
        const id = (card.match(/data-product-id="([^"]+)"/) || [])[1];
        const shortName = (card.match(/data-product-name="([^"]+)"/) || [])[1];
        const image = (card.match(/data-product-image="([^"]+)"/) || [])[1];
        const photo = (card.match(/<div class="product-image-placeholder">\s*<img src="([^"]+)"/) || [])[1];
        return {
            // id и краткое имя нужны корзине; у одного варианта их не было - достраиваем
            id: id || `${entry.slug}-${index + 1}`,
            name: shortName || h3,
            fullName: h3,
            image: image ? imgPath(image) : (entry.cardImage || null),
            photo: photo ? imgPath(photo) : null
        };
    });

    const related = [...relatedHtml.matchAll(/<a href="([a-z-]+)\.html" class="product-link"/g)]
        .map((m) => m[1]);

    return {
        slug: entry.slug,
        title,
        pageTitle,
        metaDescription,
        shortDescription: entry.shortDescription,
        cardTitle: entry.cardTitle,
        description,
        image: entry.cardImage,
        mainImage,
        orderUrl,
        // зарезервировано под будущий прайс - пустое не выводится на странице
        sku: null,
        price: null,
        unit: null,
        specs,
        features,
        variants,
        related
    };
});

// --- проверки ----------------------------------------------------------

const problems = [];
const slugs = new Set(products.map((p) => p.slug));
let variantCount = 0;
const seenIds = new Set();

for (const p of products) {
    if (!p.title) problems.push(`${p.slug}: пустой заголовок`);
    if (!p.specs.length) problems.push(`${p.slug}: не разобраны характеристики`);
    if (!p.features.length) problems.push(`${p.slug}: не разобраны преимущества`);
    if (!p.orderUrl) problems.push(`${p.slug}: нет внешней ссылки на заказ`);
    for (const r of p.related) {
        if (!slugs.has(r)) problems.push(`${p.slug}: ссылка на несуществующий товар ${r}`);
    }
    for (const v of p.variants) {
        variantCount++;
        if (seenIds.has(v.id)) problems.push(`дубль id варианта: ${v.id}`);
        else seenIds.add(v.id);
    }
}

fs.mkdirSync(path.join(ROOT, 'data'), { recursive: true });
fs.writeFileSync(
    path.join(ROOT, 'data/products.json'),
    JSON.stringify(products, null, 2) + '\n',
    'utf8'
);

console.log(`Товаров: ${products.length}`);
console.log(`Вариантов: ${variantCount}`);
console.log(`Характеристик: ${products.reduce((s, p) => s + p.specs.length, 0)}`);
console.log(`Преимуществ: ${products.reduce((s, p) => s + p.features.length, 0)}`);
console.log(problems.length ? `\nТребует внимания:\n- ${problems.join('\n- ')}` : '\nПроблем не найдено');
