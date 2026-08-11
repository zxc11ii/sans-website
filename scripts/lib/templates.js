/**
 * Шаблоны страниц. Всё, что раньше было скопировано в 25 HTML-файлов
 * (шапка, подвал, корзина, поп-ап), живёт здесь в одном экземпляре.
 *
 * root - префикс пути к корню сайта для текущей страницы:
 *   главная -> '', pages/*.html -> '../', pages/products/*.html -> '../../'
 */

const path = require('path');

/**
 * Ссылка от текущей страницы к цели, заданной путём от корня сайта.
 * dir - папка страницы ('.', 'pages', 'pages/products').
 */
const link = (dir, target) => path.posix.relative(dir || '.', target) || '.';

const esc = (s) => String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

/** Абсолютный адрес файла на сайте; null, пока домен не задан в site.json */
const absolute = (site, filePath) => {
    if (!site.url) return null;
    const base = site.url.replace(/\/+$/, '');
    const clean = filePath === 'index.html' ? '' : filePath;
    return `${base}/${clean}`;
};

/** canonical, Open Graph и Twitter Card */
function socialTags(site, page) {
    const pageUrl = absolute(site, page.out);
    const imageUrl = absolute(site, site.ogImage);
    const lines = [];

    if (pageUrl) lines.push(`    <link rel="canonical" href="${pageUrl}">`);

    lines.push(
        `    <meta property="og:type" content="${page.product ? 'product' : 'website'}">`,
        `    <meta property="og:site_name" content="${esc(site.name)}">`,
        `    <meta property="og:locale" content="ru_RU">`,
        `    <meta property="og:title" content="${esc(page.title)}">`,
        `    <meta property="og:description" content="${esc(page.description)}">`
    );

    if (pageUrl) lines.push(`    <meta property="og:url" content="${pageUrl}">`);

    // og:image требует абсолютного адреса, поэтому только после указания домена
    if (imageUrl) {
        const productImage = page.product ? absolute(site, page.product.mainImage) : null;
        lines.push(`    <meta property="og:image" content="${productImage || imageUrl}">`);
    }

    lines.push(
        `    <meta name="twitter:card" content="summary_large_image">`,
        `    <meta name="twitter:title" content="${esc(page.title)}">`,
        `    <meta name="twitter:description" content="${esc(page.description)}">`
    );

    return lines.join('\n');
}

/** Микроразметка Schema.org */
function structuredData(site, page) {
    const blocks = [];
    const pageUrl = absolute(site, page.out);

    const organization = {
        '@type': 'LocalBusiness',
        name: site.name,
        description: site.tagline,
        telephone: [site.phone, site.phoneSecond].filter(Boolean),
        email: site.email,
        address: {
            '@type': 'PostalAddress',
            streetAddress: site.streetAddress,
            addressLocality: site.locality,
            addressRegion: site.region,
            addressCountry: 'RU'
        },
        openingHours: site.openingHours,
        foundingDate: site.foundingYear
    };
    if (site.url) {
        organization.url = site.url.replace(/\/+$/, '');
        organization.image = absolute(site, site.ogImage);
    }

    // организацию описываем на главной и контактах, чтобы не дублировать на всех страницах
    if (page.active === 'index' || page.active === 'contacts') {
        blocks.push(organization);
    }

    if (page.product) {
        const product = {
            '@type': 'Product',
            name: page.product.title,
            description: page.product.description,
            category: 'Упаковочные материалы',
            brand: { '@type': 'Brand', name: site.name }
        };
        const image = absolute(site, page.product.mainImage);
        if (image) product.image = image;
        if (page.product.sku) product.sku = page.product.sku;
        // цены пока нет - предложение появится вместе с прайсом
        if (page.product.price) {
            product.offers = {
                '@type': 'Offer',
                price: page.product.price,
                priceCurrency: 'RUB',
                availability: 'https://schema.org/InStock'
            };
        }
        blocks.push(product);

        const crumbs = [
            { name: 'Главная', file: 'index.html' },
            { name: 'Упаковка', file: 'pages/packaging.html' },
            { name: page.product.title, file: page.out }
        ];
        blocks.push({
            '@type': 'BreadcrumbList',
            itemListElement: crumbs.map((crumb, i) => {
                const item = { '@type': 'ListItem', position: i + 1, name: crumb.name };
                const url = absolute(site, crumb.file);
                if (url) item.item = url;
                return item;
            })
        });
    }

    if (!blocks.length) return '';

    const json = blocks.length === 1
        ? { '@context': 'https://schema.org', ...blocks[0] }
        : { '@context': 'https://schema.org', '@graph': blocks };

    void pageUrl;
    return `    <script type="application/ld+json">\n${JSON.stringify(json, null, 4)
        .split('\n').map((l) => '    ' + l).join('\n')}\n    </script>`;
}

const head = (site, page) => {
    const { root, title, description } = page;
    const ld = structuredData(site, page);

    return `<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="${esc(description)}">
    <title>${esc(title)}</title>
${socialTags(site, page)}
    <link rel="icon" type="image/png" href="${root}images/favicon.png">
    <link rel="stylesheet" href="${root}css/styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
${ld ? ld + '\n' : ''}</head>
<body>`;
};

const header = (site, { root, dir, active }) => {
    const items = site.nav.map((item) => {
        const cls = item.key === active ? ' class="active"' : '';
        return `                    <li><a href="${link(dir, item.href)}"${cls}>${esc(item.label)}</a></li>`;
    }).join('\n');

    return `    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <a href="${link(dir, 'index.html')}" class="logo">
                    <span class="company-name">${esc(site.name)}</span>
                </a>
                <ul class="nav-menu">
${items}
                </ul>
                <a href="tel:${site.phoneHref}" class="btn-call">${esc(site.phone)}</a>
                <button class="mobile-menu-toggle" aria-label="Меню" aria-expanded="false">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </nav>
        </div>
    </header>`;
};

const footer = (site, { root, dir }) => {
    const links = site.nav.map((item) =>
        `                        <li><a href="${link(dir, item.href)}">${esc(item.label)}</a></li>`
    ).join('\n');

    return `    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <div class="footer-logo">
                        <img src="${root}images/logo.png" alt="${esc(site.name)}" class="footer-logo-img" loading="lazy" decoding="async">
                        <p>${esc(site.tagline)}</p>
                    </div>
                </div>
                <div class="footer-section">
                    <h4>Навигация</h4>
                    <ul class="footer-links">
${links}
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Контакты</h4>
                    <ul class="footer-contacts">
                        <li><a href="tel:${site.phoneHref}">${esc(site.phone)}</a></li>
                        <li><a href="mailto:${site.email}">${esc(site.email)}</a></li>
                        <li>${esc(site.address)}</li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>© ${esc(site.copyrightYear)} ${esc(site.name)}. Все права защищены.</p>
                <p>Данные обновлены ${esc(site.dataUpdated)}</p>
            </div>
        </div>
    </footer>`;
};

const scrollTop = () => `    <!-- Scroll to Top Button -->
    <button id="scrollToTop" class="scroll-to-top" aria-label="Наверх">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 19V5M12 5L5 12M12 5L19 12" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>`;

const discountPopup = () => `    <!-- Discount Popup -->
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
                <p class="form-note">Нажимая кнопку, вы соглашаетесь с политикой конфиденциальности</p>
            </form>
        </div>
    </div>`;

const cart = (site, { root, dir }) => `    <!-- Shopping Cart Button -->
    <button id="cartButton" class="cart-button" aria-label="Корзина">
        <img src="${root}images/icons/shopping-cart.svg" alt="Корзина">
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
                    <a href="${link(dir, 'pages/packaging.html')}" class="btn btn-primary">Перейти к товарам</a>
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
                    <p class="form-note">Нажимая кнопку, вы соглашаетесь с политикой конфиденциальности</p>
                </form>
            </div>
        </div>
    </div>`;

/** Полная страница: шапка + содержимое + подвал + плавающие элементы */
function layout(site, page) {
    const parts = [
        head(site, page),
        header(site, page),
        '',
        page.body.replace(/\s+$/, ''),
        '',
        footer(site, page),
        '',
        scrollTop()
    ];

    if (page.showPopup) parts.push('', discountPopup());
    parts.push('', cart(site, page));
    parts.push('', `    <script src="${page.root}js/script.js"></script>`, '</body>', '</html>', '');

    return parts.join('\n');
}

/** Карточка товара в сетке каталога */
const productCard = (product, { root, href }) => `                <div class="product-card">
                    <div class="product-image-placeholder">
                        <img src="${root}${product.image}" alt="${esc(product.cardTitle)}" class="product-image" loading="lazy" decoding="async">
                    </div>
                    <h3>${esc(product.cardTitle)}</h3>
                    <p>${esc(product.shortDescription)}</p>
                    <a href="${href}" class="product-link">Подробнее →</a>
                </div>`;

/** Карточка варианта с кнопкой "В корзину" */
const variantCard = (variant, { root }) => {
    const media = variant.photo
        ? `<img src="${root}${variant.photo}" alt="${esc(variant.fullName)}" class="product-image" loading="lazy" decoding="async">`
        : `<span class="placeholder-text">Фото скоро появится</span>`;

    return `                <div class="product-card">
                    <div class="product-image-placeholder">
                        ${media}
                    </div>
                    <h3>${esc(variant.fullName)}</h3>
                    <button class="btn-add-to-cart"
                            data-product-id="${esc(variant.id)}"
                            data-product-name="${esc(variant.name)}"
                            data-product-image="${root}${variant.image}">
                        <img src="${root}images/icons/shopping-cart.svg" alt="" class="cart-icon">
                        В корзину
                    </button>
                </div>`;
};

/** Страница каталога */
function catalogBody(products, { root }) {
    const cards = products
        .map((p) => productCard(p, { root, href: `products/${p.slug}.html` }))
        .join('\n\n');

    return `    <!-- Page Header -->
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
${cards}
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
    </section>`;
}

/** Страница товара */
function productBody(product, allProducts, { root, dir }) {
    const specs = product.specs
        .map((s) => `                            <li><strong>${esc(s.label)}:</strong> ${esc(s.value)}</li>`)
        .join('\n');

    const features = product.features
        .map((f) => `                            <li>✓ ${esc(f)}</li>`)
        .join('\n');

    const orderButton = product.orderUrl
        ? `                        <a href="${product.orderUrl}" class="btn btn-primary" target="_blank" rel="noopener noreferrer">Заказать на сайте →</a>\n`
        : '';

    const variantsSection = product.variants.length ? `

    <!-- Product Variants -->
    <section class="product-variants">
        <div class="container">
            <h2 class="section-title">Варианты товара</h2>
            <div class="products-grid">
${product.variants.map((v) => variantCard(v, { root })).join('\n\n')}
            </div>
        </div>
    </section>` : '';

    const relatedCards = product.related
        .map((slug) => allProducts.find((p) => p.slug === slug))
        .filter(Boolean)
        .map((p) => productCard(p, { root, href: `${p.slug}.html` }))
        .join('\n\n');

    const relatedSection = relatedCards ? `

    <!-- Related Products -->
    <section class="related-products">
        <div class="container">
            <h2 class="section-title">Похожие товары</h2>
            <div class="products-grid">
${relatedCards}
            </div>
        </div>
    </section>` : '';

    return `    <!-- Breadcrumbs -->
    <section class="breadcrumbs">
        <div class="container">
            <a href="${link(dir, 'index.html')}">Главная</a>
            <span>/</span>
            <a href="${link(dir, 'pages/packaging.html')}">Упаковка</a>
            <span>/</span>
            <span>${esc(product.title)}</span>
        </div>
    </section>

    <!-- Product Detail -->
    <section class="product-detail">
        <div class="container">
            <div class="product-detail-grid">
                <div class="product-detail-images">
                    <div class="product-main-image">
                        <img src="${root}${product.mainImage}" alt="${esc(product.title)}">
                    </div>
                </div>

                <div class="product-detail-info">
                    <h1>${esc(product.title)}</h1>
                    <p class="product-description">
                        ${esc(product.description)}
                    </p>

                    <div class="product-specs">
                        <h3>Характеристики:</h3>
                        <ul>
${specs}
                        </ul>
                    </div>

                    <div class="product-features">
                        <h3>Преимущества:</h3>
                        <ul>
${features}
                        </ul>
                    </div>

                    <div class="product-actions">
${orderButton}                        <a href="${link(dir, 'pages/contacts.html')}" class="btn btn-secondary">Связаться с нами</a>
                    </div>
                </div>
            </div>
        </div>
    </section>${variantsSection}${relatedSection}`;
}

module.exports = { layout, catalogBody, productBody, esc };
