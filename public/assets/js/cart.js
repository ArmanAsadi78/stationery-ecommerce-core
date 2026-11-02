/**
 * =====================================================================
 * AJAX DRAWER CART & UI INTERACTIONS (تحریرچی)
 * Lightweight Vanilla JS with Fetch API
 * =====================================================================
 */

document.addEventListener('DOMContentLoaded', () => {
    const backdrop = document.getElementById('drawerCartBackdrop');
    const closeBtn = document.getElementById('drawerCartClose');
    const openBtns = document.querySelectorAll('.cart-btn-trigger');
    const cartBadge = document.getElementById('headerCartBadge');
    const drawerBody = document.getElementById('drawerCartBody');
    const drawerSubtotal = document.getElementById('drawerCartSubtotal');
    const toastContainer = document.getElementById('toastContainer');

    // Retrieve CSRF token from meta or window
    const getCsrfToken = () => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    };

    // Open / Close Drawer
    const openDrawer = () => {
        backdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
        fetchCartData();
    };

    const closeDrawer = () => {
        backdrop.classList.remove('active');
        document.body.style.overflow = '';
    };

    openBtns.forEach(btn => btn.addEventListener('click', (e) => {
        e.preventDefault();
        openDrawer();
    }));

    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);

    if (backdrop) {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) closeDrawer();
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && backdrop.classList.contains('active')) {
            closeDrawer();
        }
    });

    // Toast Notification helper
    const showToast = (message, type = 'success') => {
        if (!toastContainer) return;
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <span>${type === 'success' ? '✓' : '⚠️'}</span>
            <div>${message}</div>
        `;
        toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(20px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    };

    // Fetch and re-render Drawer Cart content
    const fetchCartData = async () => {
        try {
            const res = await fetch('/cart/data', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            if (data.success && data.cart) {
                renderDrawer(data.cart);
            }
        } catch (err) {
            console.error('Failed to fetch cart data:', err);
        }
    };

    // Render Drawer DOM
    const renderDrawer = (cart) => {
        // Update header badge with reactive pulse animation
        if (cartBadge) {
            cartBadge.textContent = cart.total_count;
            if (cart.total_count > 0) {
                cartBadge.classList.add('has-items');
            } else {
                cartBadge.classList.remove('has-items');
            }
        }

        if (drawerSubtotal) {
            drawerSubtotal.textContent = cart.formatted_subtotal;
        }

        if (!drawerBody) return;

        if (!cart.items || cart.items.length === 0) {
            drawerBody.innerHTML = `
                <div class="drawer-empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                    </svg>
                    <p style="font-size: 16px; font-weight: bold; margin-bottom: 6px;">سبد خرید شما خالی است</p>
                    <p style="font-size: 13px;">کالاهای مورد علاقه خود را به سبد خرید اضافه نمایید.</p>
                </div>
            `;
            const footer = document.querySelector('.drawer-footer');
            if (footer) footer.style.display = 'none';
            return;
        }

        const footer = document.querySelector('.drawer-footer');
        if (footer) footer.style.display = 'block';

        let html = '';
        cart.items.forEach(item => {
            html += `
                <div class="cart-item-row" data-key="${item.key}">
                    <div class="cart-item-img">
                        <img src="${item.image || '/assets/images/placeholder.webp'}" alt="${item.title}">
                    </div>
                    <div class="cart-item-info">
                        <h4 class="cart-item-title"><a href="/products/${item.slug}">${item.title}</a></h4>
                        ${item.variant_title ? `<span class="cart-item-variant">${item.variant_title}</span>` : ''}
                        <div class="cart-item-bottom">
                            <div class="cart-item-qty">
                                <button class="btn-qty-minus" data-key="${item.key}" data-qty="${item.quantity - 1}">-</button>
                                <span>${item.quantity}</span>
                                <button class="btn-qty-plus" data-key="${item.key}" data-qty="${item.quantity + 1}" ${item.quantity >= item.max_stock ? 'disabled' : ''}>+</button>
                            </div>
                            <span class="cart-item-price">${formatToman(item.subtotal)}</span>
                            <button class="cart-item-remove" data-key="${item.key}" title="حذف کالا">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });

        drawerBody.innerHTML = html;
        bindDrawerEvents();
    };

    // Format currency to Persian with Toman
    const formatToman = (amount) => {
        const faNums = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        const formatted = Math.round(amount).toLocaleString('en-US').replace(/\d/g, d => faNums[d]);
        return `${formatted} تومان`;
    };

    // Event handlers for items inside drawer
    const bindDrawerEvents = () => {
        // Plus / Minus Buttons
        drawerBody.querySelectorAll('.btn-qty-plus, .btn-qty-minus').forEach(btn => {
            btn.addEventListener('click', async () => {
                const key = btn.getAttribute('data-key');
                const qty = parseInt(btn.getAttribute('data-qty'), 10);
                await updateQuantity(key, qty);
            });
        });

        // Remove item
        drawerBody.querySelectorAll('.cart-item-remove').forEach(btn => {
            btn.addEventListener('click', async () => {
                const key = btn.getAttribute('data-key');
                await removeItem(key);
            });
        });
    };

    // AJAX API Calls
    const addToCart = async (productId, variantId = null, quantity = 1) => {
        try {
            const res = await fetch('/cart/add', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({
                    _csrf_token: getCsrfToken(),
                    product_id: productId,
                    variant_id: variantId,
                    quantity: quantity
                })
            });

            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                if (data.cart) renderDrawer(data.cart);
                openDrawer();
            } else {
                showToast(data.message || 'خطا در افزودن کالا', 'error');
            }
        } catch (err) {
            showToast('خطای ارتباط با سرور', 'error');
        }
    };

    const updateQuantity = async (cartKey, quantity) => {
        try {
            const res = await fetch('/cart/update', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({
                    _csrf_token: getCsrfToken(),
                    cart_key: cartKey,
                    quantity: quantity
                })
            });

            const data = await res.json();
            if (data.success && data.cart) {
                renderDrawer(data.cart);
            } else {
                showToast(data.message || 'خطا در تغییر تعداد', 'error');
            }
        } catch (err) {
            showToast('خطای ارتباط با سرور', 'error');
        }
    };

    const removeItem = async (cartKey) => {
        try {
            const res = await fetch('/cart/remove', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({
                    _csrf_token: getCsrfToken(),
                    cart_key: cartKey
                })
            });

            const data = await res.json();
            if (data.success && data.cart) {
                showToast(data.message, 'success');
                renderDrawer(data.cart);
            }
        } catch (err) {
            showToast('خطای ارتباط با سرور', 'error');
        }
    };

    // Quick Add Button handler (Homepage & Catalog)
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-quick-add');
        if (btn) {
            e.preventDefault();
            const pId = parseInt(btn.getAttribute('data-id'), 10);
            addToCart(pId, null, 1);
        }
    });

    // Product Detail Page Form Submit
    const detailForm = document.getElementById('productDetailForm');
    if (detailForm) {
        detailForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const pId = parseInt(detailForm.getAttribute('data-product-id'), 10);
            const variantInput = detailForm.querySelector('input[name="variant_id"]:checked');
            const variantId = variantInput ? parseInt(variantInput.value, 10) : null;
            const qtyInput = document.getElementById('detailQtyInput');
            const qty = qtyInput ? parseInt(qtyInput.value, 10) : 1;

            addToCart(pId, variantId, qty);
        });

        // Detail Page Quantity Stepper
        const btnPlus = document.getElementById('btnDetailQtyPlus');
        const btnMinus = document.getElementById('btnDetailQtyMinus');
        const qtyInput = document.getElementById('detailQtyInput');

        if (btnPlus && qtyInput) {
            btnPlus.addEventListener('click', () => {
                const max = parseInt(qtyInput.getAttribute('max') || '99', 10);
                const cur = parseInt(qtyInput.value || '1', 10);
                if (cur < max) qtyInput.value = cur + 1;
            });
        }

        if (btnMinus && qtyInput) {
            btnMinus.addEventListener('click', () => {
                const cur = parseInt(qtyInput.value || '1', 10);
                if (cur > 1) qtyInput.value = cur - 1;
            });
        }
    }

    // =====================================================================
    // PRODUCT CAROUSEL INTERACTION (Smooth Scroll & Mouse Drag)
    // =====================================================================
    const carouselTrack = document.getElementById('featuredCarouselTrack');
    const carouselPrevBtn = document.getElementById('carouselPrevBtn');
    const carouselNextBtn = document.getElementById('carouselNextBtn');

    if (carouselTrack) {
        const getScrollDistance = () => {
            const card = carouselTrack.querySelector('.carousel-slide');
            return card ? card.offsetWidth + 22 : 292;
        };

        if (carouselPrevBtn) {
            carouselPrevBtn.addEventListener('click', () => {
                // In RTL, moving to "previous" items scrolls towards the right
                carouselTrack.scrollBy({ left: getScrollDistance(), behavior: 'smooth' });
            });
        }

        if (carouselNextBtn) {
            carouselNextBtn.addEventListener('click', () => {
                // In RTL, moving to "next" items scrolls towards the left (negative delta)
                carouselTrack.scrollBy({ left: -getScrollDistance(), behavior: 'smooth' });
            });
        }

        // Mouse Drag / Swipe to Scroll
        let isDown = false;
        let startX = 0;
        let scrollLeftVal = 0;

        carouselTrack.addEventListener('mousedown', (e) => {
            // Avoid triggering drag when clicking on buttons or links directly
            if (e.target.closest('button') || e.target.closest('a')) return;
            isDown = true;
            carouselTrack.classList.add('grabbing');
            startX = e.pageX - carouselTrack.offsetLeft;
            scrollLeftVal = carouselTrack.scrollLeft;
        });

        carouselTrack.addEventListener('mouseleave', () => {
            isDown = false;
            carouselTrack.classList.remove('grabbing');
        });

        carouselTrack.addEventListener('mouseup', () => {
            isDown = false;
            carouselTrack.classList.remove('grabbing');
        });

        carouselTrack.addEventListener('mousemove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - carouselTrack.offsetLeft;
            const walk = (x - startX) * 1.5;
            carouselTrack.scrollLeft = scrollLeftVal - walk;
        });
    }

    // =====================================================================
    // WISHLIST INTERACTION (Local Persistence & Toast Alert)
    // =====================================================================
    const getWishlist = () => {
        try {
            return JSON.parse(localStorage.getItem('stationery_wishlist') || '[]');
        } catch {
            return [];
        }
    };

    const saveWishlist = (list) => {
        try {
            localStorage.setItem('stationery_wishlist', JSON.stringify(list));
        } catch {}
    };

    // Initialize active wishlist buttons
    const activeWishlist = getWishlist();
    document.querySelectorAll('.btn-wishlist').forEach((btn) => {
        const id = parseInt(btn.getAttribute('data-id'), 10);
        if (activeWishlist.includes(id)) {
            btn.classList.add('active');
        }
    });

    // Wishlist Toggle Click Handler
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-wishlist');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            const pId = parseInt(btn.getAttribute('data-id'), 10);
            let list = getWishlist();

            if (list.includes(pId)) {
                list = list.filter((item) => item !== pId);
                btn.classList.remove('active');
                showToast('از لیست علاقه‌مندی‌ها حذف شد', 'info');
            } else {
                list.push(pId);
                btn.classList.add('active');
                showToast('به لیست علاقه‌مندی‌ها اضافه شد ❤️', 'success');
            }
            saveWishlist(list);
        }
    });
});
