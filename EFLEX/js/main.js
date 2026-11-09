// Custom JavaScript for Eflex E-commerce

document.addEventListener('DOMContentLoaded', function() {

    const currencySymbol = document.body.dataset.currencySymbol || '$';
    const cartBadge = document.querySelector('.cart-badge');

    // --- Delegated Event Listeners for product card buttons ---
    document.body.addEventListener('click', function(event) {

        // --- Toggle Wishlist Button ---
        const wishlistBtn = event.target.closest('.btn-wishlist');
        if(wishlistBtn) {
            event.preventDefault();
            const productId = wishlistBtn.dataset.productId;

            fetch('ajax_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle_wishlist', product_id: productId })
            })
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success') {
                    if(data.action === 'added') {
                        wishlistBtn.classList.add('active');
                    } else {
                        wishlistBtn.classList.remove('active');
                    }
                } else if (data.status === 'login_required') {
                    window.location.href = 'login.php';
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => console.error('Error:', error));
            return;
        }

        // --- Toggle Compare Button ---
        const compareBtn = event.target.closest('.btn-compare');
        if(compareBtn) {
            event.preventDefault();
            const productId = compareBtn.dataset.productId;
            const itemIndex = compareItems.indexOf(productId);

            if (itemIndex > -1) {
                compareItems.splice(itemIndex, 1);
                compareBtn.classList.remove('active');
            } else {
                if(compareItems.length >= MAX_COMPARE) {
                    alert('You can only compare up to ' + MAX_COMPARE + ' products.');
                    return;
                }
                compareItems.push(productId);
                compareBtn.classList.add('active');
            }
            sessionStorage.setItem('compareItems', JSON.stringify(compareItems));
            updateCompareBadge();
            return;
        }
    });

    // Live Search
    // header.php renders two search inputs (desktop + mobile) with the ids
    // "header-search-input-desktop" / "-mobile" and matching suggestion containers,
    // so the old unsuffixed getElementById lookups returned null and the feature
    // silently never ran. Wire up every input that has a suggestions box in its form.
    document.querySelectorAll('[id^="header-search-input"]').forEach(function (searchInput) {
        const form = searchInput.closest('form');
        const searchSuggestions = form ? form.querySelector('.search-suggestions') : null;
        if (!searchSuggestions) {
            return;
        }

        searchInput.addEventListener('keyup', function() {
            const query = this.value;
            if (query.length < 2) {
                searchSuggestions.innerHTML = '';
                searchSuggestions.style.display = 'none';
                return;
            }

            fetch('ajax_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'product_search', query: query })
            })
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success' && data.products.length > 0) {
                    let suggestionsHTML = '';
                    data.products.forEach(product => {
                        suggestionsHTML += `
                            <a href="product_detail.php?id=${product.id}" class="suggestion-item">
                                <img src="uploads/${product.image}" alt="">
                                <div>
                                    <div>${product.name}</div>
                                    <div class="text-muted">${product.price}</div>
                                </div>
                            </a>
                        `;
                    });
                    searchSuggestions.innerHTML = suggestionsHTML;
                    searchSuggestions.style.display = 'block';
                } else {
                    searchSuggestions.innerHTML = '';
                    searchSuggestions.style.display = 'none';
                }
            });
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchSuggestions.contains(e.target)) {
                searchSuggestions.style.display = 'none';
            }
        });
    });

    // Product Comparison
    const MAX_COMPARE = 4;
    const compareBadge = document.querySelector('.compare-badge');
    let compareItems = JSON.parse(sessionStorage.getItem('compareItems')) || [];

    function updateCompareBadge() {
        if(compareBadge) {
            compareBadge.textContent = compareItems.length;
        }
    }

    updateCompareBadge();
    document.querySelectorAll('.btn-compare').forEach(button => {
        if(compareItems.includes(button.dataset.productId)){
            button.classList.add('active');
        }
    });

    // --- Mini Cart Logic ---
    const miniCartModal = document.getElementById('miniCartModal');
    if (miniCartModal) {
        const miniCartBody = document.getElementById('mini-cart-body');
        const miniCartSubtotal = document.getElementById('mini-cart-subtotal');

        const updateMiniCart = () => {
            fetch('ajax_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_cart_contents' })
            })
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success') {
                    if (data.cart_items.length > 0) {
                        let cartHTML = '';
                        data.cart_items.forEach(item => {
                            let optionsHTML = '';
                            if(item.is_variant && item.options && item.options.length > 0) {
                                const optionsContent = item.options.map(opt => {
                                    if (opt.display_type === 'color') {
                                        return `<small class="text-muted d-block">${opt.name}:
                                            <span style="display: inline-block; width: 12px; height: 12px; background-color: ${opt.meta}; border-radius: 50%; vertical-align: middle;"></span>
                                            ${opt.value}
                                        </small>`;
                                    }
                                    return `<small class="text-muted d-block">${opt.name}: ${opt.value}</small>`;
                                }).join('');
                                optionsHTML = `<div class="mini-cart-options mt-1">${optionsContent}</div>`;
                            }

                            cartHTML += `
                                <div class="mini-cart-item">
                                    <img src="uploads/${item.image}" alt="${item.name}">
                                    <div class="item-details">
                                        <span class="item-name">${item.name}</span>
                                        ${optionsHTML}
                                        <span class="item-price">${item.quantity} x ${data.currency_symbol}${item.price}</span>
                                    </div>
                                    <button class="btn-close btn-sm remove-from-cart" data-product-id="${item.id}" data-is-variant="${item.is_variant ? '1' : '0'}" aria-label="Remove"></button>
                                </div>
                            `;
                        });
                        miniCartBody.innerHTML = cartHTML;
                    } else {
                        miniCartBody.innerHTML = '<div class="text-center"><p>Your cart is currently empty.</p></div>';
                    }
                    miniCartSubtotal.textContent = `${data.currency_symbol}${data.total_price}`;
                }
            })
            .catch(error => console.error('Error fetching cart contents:', error));
        };

        miniCartModal.addEventListener('show.bs.modal', function () {
            updateMiniCart();
        });

        miniCartBody.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('remove-from-cart')) {
                const productId = e.target.dataset.productId;
                const isVariant = e.target.dataset.isVariant === '1';
                 fetch('ajax_handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'remove_from_cart', product_id: productId, is_variant: isVariant })
                })
                .then(response => response.json())
                .then(data => {
                    if(data.status === 'success') {
                        updateMiniCart();
                        cartBadge.textContent = data.cart_count;
                    }
                });
            }
        });
    }
});