// Корень сайта - вычисляется от пути к самому скрипту (js/script.js),
// поэтому работает и в подпапке (GitHub Pages), и на любой глубине страницы
const SITE_ROOT = (() => {
    const el = document.currentScript || document.querySelector('script[src*="script.js"]');
    const scriptUrl = new URL(el.getAttribute('src'), window.location.href);
    return new URL('../', scriptUrl).href;
})();

// Приводит путь к картинке (../../images/..., /images/..., полный URL)
// к виду "images/..." - такой путь не зависит от страницы и от адреса сайта,
// поэтому его безопасно хранить в localStorage
function assetPath(path) {
    if (!path) return '';
    const s = String(path);
    // внешние картинки (например, из будущей CMS) оставляем как есть
    if (/^https?:\/\//i.test(s)) return s;
    const i = s.indexOf('images/');
    if (i !== -1) return s.slice(i);
    return s.replace(/^(\.\.\/)+/, '').replace(/^\.\//, '').replace(/^\//, '');
}

// Разворачивает путь из assetPath() в рабочий URL для текущей страницы
function resolveAsset(path) {
    const clean = assetPath(path);
    return clean ? new URL(clean, SITE_ROOT).href : '';
}

// Mobile Menu Toggle
const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
const navMenu = document.querySelector('.nav-menu');

function closeMobileMenu() {
    if (!mobileMenuToggle) return;
    navMenu.classList.remove('active');
    mobileMenuToggle.classList.remove('active');
    mobileMenuToggle.setAttribute('aria-expanded', 'false');
}

if (mobileMenuToggle) {
    mobileMenuToggle.setAttribute('aria-expanded', 'false');
    mobileMenuToggle.addEventListener('click', () => {
        const isOpen = navMenu.classList.toggle('active');
        mobileMenuToggle.classList.toggle('active', isOpen);
        mobileMenuToggle.setAttribute('aria-expanded', String(isOpen));
    });
}

// Close mobile menu when clicking on a link
const navLinks = document.querySelectorAll('.nav-menu a');
navLinks.forEach(link => {
    link.addEventListener('click', closeMobileMenu);
});

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            const headerOffset = 80;
            const elementPosition = target.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });
        }
    });
});

// Header scroll effect + кнопка "Наверх" - один обработчик на rAF,
// чтобы не считать стили на каждом событии скролла
const header = document.querySelector('.header');
const scrollToTopBtn = document.getElementById('scrollToTop');
let scrollTicking = false;

function handleScroll() {
    const currentScroll = window.pageYOffset;

    if (header) {
        header.style.boxShadow = currentScroll > 100
            ? '0 4px 12px rgba(0, 0, 0, 0.1)'
            : '0 2px 4px rgba(0, 0, 0, 0.05)';
    }

    if (scrollToTopBtn) {
        scrollToTopBtn.classList.toggle('visible', currentScroll > 300);
    }

    scrollTicking = false;
}

window.addEventListener('scroll', () => {
    if (!scrollTicking) {
        scrollTicking = true;
        window.requestAnimationFrame(handleScroll);
    }
}, { passive: true });

if (scrollToTopBtn) {
    scrollToTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

// Form submission
const contactForm = document.getElementById('contactForm');

if (contactForm) {
    contactForm.addEventListener('submit', (e) => {
        e.preventDefault();
        
        // Get form data
        const formData = {
            name: document.getElementById('name').value,
            phone: document.getElementById('phone').value,
            email: document.getElementById('email').value,
            message: document.getElementById('message').value
        };
        
        // Here you would typically send the data to a server
        // For now, we'll just show an alert
        alert(`Спасибо за заявку, ${formData.name}!\n\nМы свяжемся с вами в ближайшее время по телефону ${formData.phone}.`);
        
        // Reset form
        contactForm.reset();
        
        // In a real application, you would send this data to your server:
        /*
        fetch('/api/contact', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(formData)
        })
        .then(response => response.json())
        .then(data => {
            alert('Спасибо за заявку! Мы свяжемся с вами в ближайшее время.');
            contactForm.reset();
        })
        .catch(error => {
            alert('Произошла ошибка. Пожалуйста, попробуйте позже или позвоните нам.');
        });
        */
    });
}

// Intersection Observer for animations
const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
        }
    });
}, observerOptions);

// Observe elements for animation
document.addEventListener('DOMContentLoaded', () => {
    const animatedElements = document.querySelectorAll('.spec-card, .equipment-card, .delivery-card, .info-card');
    
    animatedElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });
});

// Phone number formatting
const phoneInput = document.getElementById('phone');
if (phoneInput) {
    phoneInput.addEventListener('input', (e) => {
        let value = e.target.value.replace(/\D/g, '');
        
        if (value.length > 0) {
            if (value[0] === '8') {
                value = '7' + value.slice(1);
            }
            if (value[0] !== '7') {
                value = '7' + value;
            }
        }
        
        let formatted = '+7';
        if (value.length > 1) {
            formatted += ' (' + value.substring(1, 4);
        }
        if (value.length >= 5) {
            formatted += ') ' + value.substring(4, 7);
        }
        if (value.length >= 8) {
            formatted += '-' + value.substring(7, 9);
        }
        if (value.length >= 10) {
            formatted += '-' + value.substring(9, 11);
        }
        
        e.target.value = formatted;
    });
}

// Discount Popup for Product Pages
const discountPopup = document.getElementById('discountPopup');
const discountMini = document.getElementById('discountMini');
const discountClose = document.getElementById('discountClose');
const discountCloseFull = document.getElementById('discountCloseFull');
const discountForm = document.getElementById('discountForm');

// Закрытый поп-ап не показываем повторно 7 дней
const DISCOUNT_DISMISS_KEY = 'sansDiscountDismissedAt';
const DISCOUNT_DISMISS_DAYS = 7;

function isDiscountDismissed() {
    const saved = Number(localStorage.getItem(DISCOUNT_DISMISS_KEY));
    if (!saved) return false;
    return Date.now() - saved < DISCOUNT_DISMISS_DAYS * 24 * 60 * 60 * 1000;
}

function dismissDiscountPopup() {
    discountPopup.classList.remove('show', 'expanded');
    localStorage.setItem(DISCOUNT_DISMISS_KEY, String(Date.now()));
}

if (discountPopup) {
    // Check if we're on a product page
    const isProductPage = window.location.pathname.includes('/products/');

    if (isProductPage && !isDiscountDismissed()) {
        // Random delay between 8-9 seconds
        const delay = Math.floor(Math.random() * (9000 - 8000 + 1)) + 8000;

        setTimeout(() => {
            discountPopup.classList.add('show');
        }, delay);
    }

    // Click on mini popup to expand
    discountMini.addEventListener('click', () => {
        discountPopup.classList.add('expanded');
    });

    // Close mini popup (X button on mini) - closes completely
    discountClose.addEventListener('click', (e) => {
        e.stopPropagation();
        dismissDiscountPopup();
    });

    // Close full popup (X button on full) - closes completely
    discountCloseFull.addEventListener('click', (e) => {
        e.stopPropagation();
        dismissDiscountPopup();
    });
    
    // Click outside full popup - collapse to mini (don't close)
    document.addEventListener('click', (e) => {
        if (discountPopup.classList.contains('expanded')) {
            // Check if click is outside the popup content
            const popupContent = discountPopup.querySelector('.discount-popup-full');
            if (popupContent && !popupContent.contains(e.target) && !discountMini.contains(e.target)) {
                // Collapse to mini instead of closing
                discountPopup.classList.remove('expanded');
            }
        }
    });
    
    // Form submission
    if (discountForm) {
        discountForm.addEventListener('submit', (e) => {
            e.preventDefault();
            
            const formData = {
                name: document.getElementById('discountName').value,
                phone: document.getElementById('discountPhone').value,
                email: document.getElementById('discountEmail').value,
                message: document.getElementById('discountMessage').value,
                type: 'discount'
            };
            
            // Here you would send data to server
            alert(`Спасибо за заявку, ${formData.name}!\n\nМы свяжемся с вами в ближайшее время и расскажем о специальном предложении.\n\nТелефон: ${formData.phone}`);

            // Hide popup (и больше не показываем - заявка уже оставлена)
            dismissDiscountPopup();
            
            // Reset form
            discountForm.reset();
            
            /* In production, use fetch:
            fetch('/api/discount', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                alert('Спасибо за заявку! Мы свяжемся с вами в ближайшее время.');
                discountPopup.classList.remove('show', 'expanded');
                discountForm.reset();
            })
            .catch(error => {
                alert('Произошла ошибка. Пожалуйста, позвоните нам или попробуйте позже.');
            });
            */
        });
    }
}

// ===== Shopping Cart System =====
class ShoppingCart {
    constructor() {
        this.items = this.loadCart();
        this.init();
    }
    
    init() {
        this.updateCartButton();
        this.attachEventListeners();
    }
    
    loadCart() {
        const saved = localStorage.getItem('sansCart');
        if (!saved) return [];

        try {
            const items = JSON.parse(saved);
            if (!Array.isArray(items)) return [];
            // Старые корзины хранили путь относительно страницы товара
            // (../../images/...) - он ломался при открытии корзины с другой
            // страницы, поэтому приводим все пути к абсолютным
            return items.map(item => ({
                ...item,
                image: assetPath(item.image)
            }));
        } catch (e) {
            return [];
        }
    }
    
    saveCart() {
        localStorage.setItem('sansCart', JSON.stringify(this.items));
    }
    
    addItem(product) {
        const existing = this.items.find(item => item.id === product.id);
        
        if (existing) {
            existing.quantity += 1;
        } else {
            this.items.push({
                id: product.id,
                name: product.name,
                image: assetPath(product.image),
                quantity: 1
            });
        }
        
        this.saveCart();
        this.updateCartButton();
        this.showNotification('Товар добавлен в корзину');
    }
    
    removeItem(productId) {
        this.items = this.items.filter(item => item.id !== productId);
        this.saveCart();
        this.updateCartButton();
        this.renderCart();
    }
    
    updateQuantity(productId, newQuantity) {
        const item = this.items.find(item => item.id === productId);
        if (item) {
            if (newQuantity <= 0) {
                this.removeItem(productId);
            } else {
                item.quantity = newQuantity;
                this.saveCart();
                this.renderCart();
            }
        }
    }
    
    getTotalCount() {
        return this.items.reduce((sum, item) => sum + item.quantity, 0);
    }
    
    updateCartButton() {
        const count = this.getTotalCount();
        const cartButton = document.getElementById('cartButton');
        const cartBadge = document.getElementById('cartBadge');
        const discountPopup = document.querySelector('.discount-popup');
        
        if (!cartButton) return;

        const hasItems = count > 0;
        cartButton.classList.toggle('visible', hasItems);

        if (cartBadge) {
            cartBadge.textContent = count;
        }

        // Когда кнопка корзины появляется, поп-ап скидки поднимается выше,
        // чтобы её не перекрывать. Отступы заданы в CSS для каждого
        // брейкпоинта (.discount-popup / .discount-popup.cart-visible)
        if (discountPopup) {
            discountPopup.classList.toggle('cart-visible', hasItems);
        }
    }
    
    showNotification(message) {
        // Simple notification
        const notification = document.createElement('div');
        notification.className = 'cart-notification';
        notification.textContent = message;
        notification.style.cssText = `
            position: fixed;
            top: 100px;
            right: 30px;
            background: #C41E3A;
            color: white;
            padding: 16px 24px;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            z-index: 9999;
            max-width: calc(100vw - 60px);
            animation: slideInRight 0.3s ease;
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => notification.remove(), 300);
        }, 2000);
    }
    
    renderCart() {
        const cartItemsContainer = document.getElementById('cartItems');
        const cartEmpty = document.getElementById('cartEmpty');
        const cartFooter = document.querySelector('.cart-modal-footer');
        const cartTotalCount = document.getElementById('cartTotalCount');
        
        if (!cartItemsContainer) return;
        
        if (this.items.length === 0) {
            cartEmpty.style.display = 'block';
            cartItemsContainer.style.display = 'none';
            cartFooter.style.display = 'none';
        } else {
            cartEmpty.style.display = 'none';
            cartItemsContainer.style.display = 'flex';
            cartFooter.style.display = 'block';
            
            cartItemsContainer.innerHTML = this.items.map(item => `
                <div class="cart-item" data-id="${item.id}">
                    <div class="cart-item-image">
                        ${item.image ? `<img src="${resolveAsset(item.image)}" alt="${item.name}" loading="lazy">` : ''}
                    </div>
                    <div class="cart-item-info">
                        <h3>${item.name}</h3>
                        <p>Количество: ${item.quantity} шт.</p>
                    </div>
                    <div class="cart-item-actions">
                        <div class="cart-item-quantity">
                            <button class="cart-qty-minus" data-id="${item.id}">−</button>
                            <span>${item.quantity}</span>
                            <button class="cart-qty-plus" data-id="${item.id}">+</button>
                        </div>
                        <button class="cart-item-remove" data-id="${item.id}">Удалить</button>
                    </div>
                </div>
            `).join('');
            
            if (cartTotalCount) {
                const total = this.getTotalCount();
                cartTotalCount.textContent = `${total} ${this.getItemWord(total)}`;
            }
            
            // Attach item event listeners
            this.attachItemListeners();
        }
    }
    
    getItemWord(count) {
        const lastDigit = count % 10;
        const lastTwoDigits = count % 100;
        
        if (lastTwoDigits >= 11 && lastTwoDigits <= 19) {
            return 'товаров';
        }
        if (lastDigit === 1) {
            return 'товар';
        }
        if (lastDigit >= 2 && lastDigit <= 4) {
            return 'товара';
        }
        return 'товаров';
    }
    
    attachItemListeners() {
        // Plus buttons
        document.querySelectorAll('.cart-qty-plus').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;
                const item = this.items.find(item => item.id === id);
                if (item) {
                    this.updateQuantity(id, item.quantity + 1);
                }
            });
        });
        
        // Minus buttons
        document.querySelectorAll('.cart-qty-minus').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;
                const item = this.items.find(item => item.id === id);
                if (item) {
                    this.updateQuantity(id, item.quantity - 1);
                }
            });
        });
        
        // Remove buttons
        document.querySelectorAll('.cart-item-remove').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;
                this.removeItem(id);
            });
        });
    }
    
    attachEventListeners() {
        // Cart button click
        const cartButton = document.getElementById('cartButton');
        if (cartButton) {
            cartButton.addEventListener('click', () => {
                this.openCart();
            });
        }
        
        // Close cart modal
        const cartClose = document.getElementById('cartClose');
        if (cartClose) {
            cartClose.addEventListener('click', () => {
                this.closeCart();
            });
        }
        
        // Close on backdrop click
        const cartModal = document.getElementById('cartModal');
        if (cartModal) {
            cartModal.addEventListener('click', (e) => {
                if (e.target === cartModal) {
                    this.closeCart();
                }
            });
        }
        
        // Checkout button
        const checkoutBtn = document.getElementById('cartCheckoutBtn');
        if (checkoutBtn) {
            checkoutBtn.addEventListener('click', () => {
                this.checkout();
            });
        }
        
        // Close order form modal
        const orderClose = document.getElementById('cartOrderClose');
        if (orderClose) {
            orderClose.addEventListener('click', () => {
                this.closeOrderForm();
            });
        }
        
        // Close order form on backdrop click
        const orderModal = document.getElementById('cartOrderModal');
        if (orderModal) {
            orderModal.addEventListener('click', (e) => {
                if (e.target === orderModal) {
                    this.closeOrderForm();
                }
            });
        }
        
        // Order form submission
        const orderForm = document.getElementById('cartOrderForm');
        if (orderForm) {
            orderForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.submitOrder();
            });
        }
        
        // Add to cart buttons
        document.querySelectorAll('.btn-add-to-cart').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const productData = {
                    id: btn.dataset.productId,
                    name: btn.dataset.productName,
                    image: btn.dataset.productImage || ''
                };
                this.addItem(productData);
            });
        });
    }
    
    submitOrder() {
        const formData = {
            name: document.getElementById('cartOrderName').value,
            phone: document.getElementById('cartOrderPhone').value,
            email: document.getElementById('cartOrderEmail').value,
            message: document.getElementById('cartOrderMessage').value,
            items: this.items
        };
        
        // Generate order text for alert
        const orderText = this.items.map(item => 
            `${item.name} - ${item.quantity} шт.`
        ).join('\n');
        
        const totalCount = this.getTotalCount();
        
        // Show success message
        alert(`Спасибо за заявку, ${formData.name}!\n\nВаш заказ:\n${orderText}\n\nВсего: ${totalCount} ${this.getItemWord(totalCount)}\n\nМы свяжемся с вами в ближайшее время по телефону ${formData.phone}.`);
        
        // Clear cart and close modals
        this.items = [];
        this.saveCart();
        this.updateCartButton();
        this.closeOrderForm();
        
        // Reset form
        document.getElementById('cartOrderForm').reset();
        
        /* In production, send data to server:
        fetch('/api/order', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(formData)
        })
        .then(response => response.json())
        .then(data => {
            alert('Спасибо за заявку! Мы свяжемся с вами в ближайшее время.');
            this.items = [];
            this.saveCart();
            this.updateCartButton();
            this.closeOrderForm();
            document.getElementById('cartOrderForm').reset();
        })
        .catch(error => {
            alert('Произошла ошибка. Пожалуйста, позвоните нам или попробуйте позже.');
        });
        */
    }
    
    openCart() {
        const cartModal = document.getElementById('cartModal');
        if (cartModal) {
            cartModal.classList.add('active');
            this.renderCart();
            document.body.style.overflow = 'hidden';
        }
    }
    
    closeCart() {
        const cartModal = document.getElementById('cartModal');
        if (cartModal) {
            cartModal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
    
    checkout() {
        if (this.items.length === 0) return;
        
        // Close cart modal
        this.closeCart();
        
        // Open order form modal
        this.openOrderForm();
    }
    
    openOrderForm() {
        const orderModal = document.getElementById('cartOrderModal');
        const orderItems = document.getElementById('cartOrderItems');
        
        if (!orderModal || !orderItems) return;
        
        // Generate order summary
        const totalCount = this.getTotalCount();
        const itemsHtml = this.items.map(item => `
            <div class="cart-order-item">
                <span class="cart-order-item-name">${item.name}</span>
                <span class="cart-order-item-qty">${item.quantity} шт.</span>
            </div>
        `).join('');
        
        orderItems.innerHTML = itemsHtml + `
            <div class="cart-order-item">
                <span class="cart-order-item-name">Всего:</span>
                <span class="cart-order-item-qty">${totalCount} ${this.getItemWord(totalCount)}</span>
            </div>
        `;
        
        orderModal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    closeOrderForm() {
        const orderModal = document.getElementById('cartOrderModal');
        if (orderModal) {
            orderModal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
}

// Initialize cart when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.shoppingCart = new ShoppingCart();
});

// Закрытие по Esc: сначала модалки корзины, затем мобильное меню
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;

    const orderModal = document.getElementById('cartOrderModal');
    const cartModal = document.getElementById('cartModal');

    if (orderModal && orderModal.classList.contains('active')) {
        window.shoppingCart.closeOrderForm();
    } else if (cartModal && cartModal.classList.contains('active')) {
        window.shoppingCart.closeCart();
    } else if (discountPopup && discountPopup.classList.contains('expanded')) {
        discountPopup.classList.remove('expanded');
    } else if (navMenu && navMenu.classList.contains('active')) {
        closeMobileMenu();
    }
});

// Add CSS animations for notifications
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
