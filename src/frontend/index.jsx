import React from 'react';
import { createRoot } from 'react-dom/client';
import WishlistButton from '../components/WishlistButton';
import WishlistPage from '../components/WishlistPage';
import SharedWishlistView from '../components/SharedWishlistView';
import '../styles/WishlistButton.scss';
import '../styles/WishlistPage.scss';
import '../styles/SharedWishlistView.scss';

const getSetting = (key, fallback) => {
    if (!window.WishCartWishlist || !(key in window.WishCartWishlist)) {
        return fallback;
    }

    return window.WishCartWishlist[key];
};

const normalizeBoolean = (value, fallback = true) => {
    if (value === undefined || value === null) {
        return fallback;
    }

    if (typeof value === 'string') {
        if (value.toLowerCase() === 'false' || value === '0') {
            return false;
        }
        if (value.toLowerCase() === 'true' || value === '1') {
            return true;
        }
    }

    return Boolean(value);
};

const isWishlistEnabled = () => normalizeBoolean(getSetting('enabled', true), true);
const isProductButtonEnabled = () => {
    if (!isWishlistEnabled()) {
        return false;
    }

    return normalizeBoolean(getSetting('showOnProduct', undefined), true);
};
const isShopButtonEnabled = () => {
    if (!isWishlistEnabled()) {
        return false;
    }

    return normalizeBoolean(getSetting('showOnShop', undefined), true);
};

const normalizePosition = (value, fallback = 'bottom') => {
    let candidate = value || fallback || 'bottom';

    switch (candidate) {
        case 'top':
        case 'bottom':
        case 'left':
        case 'right':
            return candidate;
        case 'before':
            return 'top';
        case 'after':
            return 'bottom';
        default:
            return 'bottom';
    }
};

const applyPlacementLayout = (container, position) => {
    if (!container) {
        return;
    }

    container.classList.add(`wishcart-position-${position}`);
    container.dataset.position = position;

    const parent = container.parentElement;
    if (!parent) {
        return;
    }

    parent.classList.add('wishcart-button-wrapper');
    parent.classList.add(`wishcart-button-wrapper--${position}`);

    if (position === 'left' || position === 'right') {
        parent.classList.add('wishcart-button-wrapper--horizontal');
    }
};

const mountWishlistButtonAtContainer = (container) => {
    if (!container || container.dataset.mounted === 'true') {
        return;
    }

    const productId = container.getAttribute('data-product-id');
    if (!productId) {
        return;
    }

    const position = normalizePosition(container.getAttribute('data-position'));
    applyPlacementLayout(container, position);

    const root = createRoot(container);
    root.render(<WishlistButton productId={parseInt(productId, 10)} position={position} />);

    container.dataset.mounted = 'true';
};

const injectFluentCartContainer = () => {
    if (!isProductButtonEnabled()) {
        document
            .querySelectorAll('.fc-product-buttons-wrap .wishcart-wishlist-button-container, .fluent-cart-add-to-cart-button .wishcart-wishlist-button-container')
            .forEach((container) => container.remove());
        return null;
    }

    if (document.querySelector('.wishcart-wishlist-button-container')) {
        return null;
    }

    const addToCartButton = document.querySelector('.fluent-cart-add-to-cart-button[data-product-id]');
    if (!addToCartButton) {
        return null;
    }

    const productId = parseInt(addToCartButton.getAttribute('data-product-id'), 10);
    if (!productId) {
        return null;
    }

    const wrapper = addToCartButton.closest('.fc-product-buttons-wrap') || addToCartButton.parentElement;
    if (!wrapper) {
        return null;
    }

    const position = normalizePosition(null, window.WishCartWishlist?.buttonPosition);

    const container = document.createElement('div');
    container.className = `wishcart-wishlist-button-container wishcart-position-${position}`;
    container.setAttribute('data-product-id', String(productId));
    container.setAttribute('data-position', position);

    if (position === 'top' || position === 'left') {
        wrapper.prepend(container);
    } else {
        wrapper.appendChild(container);
    }

    applyPlacementLayout(container, position);
    return container;
};

const extractProductId = (element) => {
    if (!element) {
        return null;
    }

    const direct = element.getAttribute('data-product-id') || element.dataset?.productId;
    if (direct) {
        const parsed = parseInt(direct, 10);
        if (!Number.isNaN(parsed)) {
            return parsed;
        }
    }

    const nested = element.querySelector('[data-product-id]');
    if (nested) {
        const nestedValue = nested.getAttribute('data-product-id') || nested.dataset?.productId;
        if (nestedValue) {
            const parsed = parseInt(nestedValue, 10);
            if (!Number.isNaN(parsed)) {
                return parsed;
            }
        }
    }

    return null;
};

const injectWishlistIntoProductCards = () => {
    if (!isShopButtonEnabled()) {
        document
            .querySelectorAll('.wishcart-wishlist-button-container.wishcart-card-container')
            .forEach((container) => container.remove());
        return;
    }

    const cards = document.querySelectorAll(
        '.fc-product-card, [data-fluent-cart-shop-app-single-product], [data-fluent-cart-product-entry], [data-fluent-cart-product-row], .wp-block-post.type-fluent-products'
    );

    cards.forEach((card) => {
        if (!card || card.querySelector('.wishcart-wishlist-button-container')) {
            return;
        }

        const productId = extractProductId(card);
        if (!productId) {
            return;
        }

        const position = normalizePosition(window.WishCartWishlist?.buttonPosition);
        const container = document.createElement('div');
        container.className = `wishcart-wishlist-button-container wishcart-position-${position} wishcart-card-container`;
        container.setAttribute('data-product-id', String(productId));
        container.setAttribute('data-position', position);

        const content =
            card.querySelector('.fc-product-card-content') ||
            card.querySelector('[data-fluent-cart-product-content]') ||
            card.querySelector('.wp-block-post-content') ||
            card;
        if (position === 'top' || position === 'left') {
            content.prepend(container);
        } else {
            content.appendChild(container);
        }

        applyPlacementLayout(container, position);
        mountWishlistButtonAtContainer(container);
    });
};

const injectWishlistIntoArchiveEntries = () => {
    if (!isShopButtonEnabled()) {
        document
            .querySelectorAll('.wishcart-wishlist-button-container.wishcart-archive-container')
            .forEach((container) => container.remove());
        return;
    }

    const archiveEntries = document.querySelectorAll(
        '.fc-product-archive-entry[data-product-id], [data-fluent-cart-archive-entry], .fc-product-list-item, .wp-block-post.type-fluent-products'
    );

    archiveEntries.forEach((entry) => {
        if (!entry || entry.querySelector('.wishcart-wishlist-button-container')) {
            return;
        }

        const productId =
            extractProductId(entry) ||
            parseInt(entry.getAttribute('data-product-id'), 10) ||
            parseInt(entry.dataset?.productId || '', 10) ||
            parseInt(entry.dataset?.wpPostId || '', 10);
        if (!productId) {
            return;
        }

        const position = normalizePosition(window.WishCartWishlist?.buttonPosition);
        const container = document.createElement('div');
        container.className = `wishcart-wishlist-button-container wishcart-position-${position} wishcart-archive-container`;
        container.setAttribute('data-product-id', String(productId));
        container.setAttribute('data-position', position);

        const content =
            entry.querySelector('.fc-product-archive-content') ||
            entry.querySelector('.fc-product-card-content') ||
            entry.querySelector('.wp-block-post-content') ||
            entry;
        if (position === 'top' || position === 'left') {
            content.prepend(container);
        } else {
            content.appendChild(container);
        }

        applyPlacementLayout(container, position);
        mountWishlistButtonAtContainer(container);
    });
};

const injectWishlistNearActionButtons = () => {
    if (!isProductButtonEnabled()) {
        document
            .querySelectorAll('.fc-product-buttons-wrap .wishcart-wishlist-button-container, .fluent-cart-add-to-cart-button .wishcart-wishlist-button-container')
            .forEach((container) => container.remove());
        return;
    }

    const buttons = document.querySelectorAll('.fluent-cart-add-to-cart-button[data-product-id]');

    buttons.forEach((button) => {
        const productId = parseInt(button.getAttribute('data-product-id') || button.dataset?.productId || '', 10);
        if (!productId) {
            return;
        }

        const position = normalizePosition(window.WishCartWishlist?.buttonPosition);
        const wrapper = button.closest('.fc-product-buttons-wrap') || button.parentElement;

        if (!wrapper) {
            return;
        }

        let container = wrapper.querySelector(`.wishcart-wishlist-button-container[data-product-id="${productId}"]`);

        if (!container) {
            container = document.createElement('div');
            container.className = `wishcart-wishlist-button-container wishcart-position-${position}`;
            container.setAttribute('data-product-id', String(productId));
            container.setAttribute('data-position', position);

            if (position === 'top' || position === 'left') {
                wrapper.prepend(container);
            } else {
                wrapper.appendChild(container);
            }
        }

        applyPlacementLayout(container, position);
        mountWishlistButtonAtContainer(container);
    });
};

// Initialize session ID cookie management
const initializeSessionId = () => {
    if (window.WishCartWishlist?.isLoggedIn) {
        return; // Logged in users don't need session ID
    }

    // Check if session ID cookie exists
    const cookies = document.cookie.split(';');
    let hasSessionId = false;
    
    for (let cookie of cookies) {
        const [name] = cookie.trim().split('=');
        if (name === 'wishcart_session_id') {
            hasSessionId = true;
            break;
        }
    }

    // Create session ID if it doesn't exist
    if (!hasSessionId && window.WishCartWishlist) {
        const sessionId = 'wc_' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
        const expiryDays = 30;
        const expiryDate = new Date();
        expiryDate.setTime(expiryDate.getTime() + (expiryDays * 24 * 60 * 60 * 1000));
        document.cookie = `wishcart_session_id=${sessionId};expires=${expiryDate.toUTCString()};path=/;SameSite=Lax`;
        
        // Update global object
        if (window.WishCartWishlist) {
            window.WishCartWishlist.sessionId = sessionId;
        }
    }
};

// Mount wishlist buttons
const mountWishlistButtons = () => {
    if (!isWishlistEnabled()) {
        document.querySelectorAll('.wishcart-wishlist-button-container').forEach((container) => container.remove());
        return;
    }

    const showShop = isShopButtonEnabled();
    const showProduct = isProductButtonEnabled();

    if (!showShop) {
        document
            .querySelectorAll('.wishcart-wishlist-button-container.wishcart-card-container, .wishcart-wishlist-button-container.wishcart-archive-container')
            .forEach((container) => container.remove());
    }

    if (!showProduct) {
        document
            .querySelectorAll('.fc-product-buttons-wrap .wishcart-wishlist-button-container, .fluent-cart-add-to-cart-button .wishcart-wishlist-button-container')
            .forEach((container) => container.remove());
    }

    if (!showShop && !showProduct) {
        return;
    }

    injectWishlistIntoProductCards();
    injectWishlistIntoArchiveEntries();
    injectWishlistNearActionButtons();

    let containers = document.querySelectorAll('.wishcart-wishlist-button-container');

    if (!containers.length) {
        const fallbackContainer = injectFluentCartContainer();
        if (fallbackContainer) {
            containers = document.querySelectorAll('.wishcart-wishlist-button-container');
        }
    }
    
    containers.forEach((container) => {
        const position = normalizePosition(
            container.getAttribute('data-position'),
            window.WishCartWishlist?.buttonPosition
        );
        container.setAttribute('data-position', position);
        mountWishlistButtonAtContainer(container);
    });
};

// Mount wishlist page
const mountWishlistPage = () => {
    const container = document.getElementById('wishcart-wishlist-page');
    
    if (container) {
        const root = createRoot(container);
        root.render(<WishlistPage />);
    }
};

// Mount shared wishlist view
const mountSharedWishlistView = () => {
    const container = document.getElementById('shared-wishlist-app');
    
    if (container) {
        const shareToken = container.getAttribute('data-share-token') || window.WishCartShared?.shareToken;
        if (shareToken) {
            const root = createRoot(container);
            root.render(<SharedWishlistView shareToken={shareToken} />);
        }
    }
};

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initializeSessionId();
        mountWishlistButtons();
        mountWishlistPage();
        mountSharedWishlistView();
        setupAddToCartTracking();
    });
} else {
    initializeSessionId();
    mountWishlistButtons();
    mountWishlistPage();
    mountSharedWishlistView();
    setupAddToCartTracking();
}

// Wishlist status cache to avoid multiple API calls
const wishlistStatusCache = new Map();

// Get session ID helper
const getSessionIdForTracking = () => {
    if (window.WishCartWishlist?.isLoggedIn) {
        return null;
    }
    
    const cookies = document.cookie.split(';');
    for (let cookie of cookies) {
        const [name, value] = cookie.trim().split('=');
        if (name === 'wishcart_session_id') {
            return value;
        }
    }
    
    return window.WishCartWishlist?.sessionId || null;
};

// Check if product is in wishlist
const checkProductInWishlist = async (productId) => {
    // Check cache first
    if (wishlistStatusCache.has(productId)) {
        return wishlistStatusCache.get(productId);
    }
    
    if (!productId || !window.WishCartWishlist) {
        return false;
    }
    
    try {
        const sessionId = getSessionIdForTracking();
        const url = `${window.WishCartWishlist.apiUrl}wishlist/check/${productId}${sessionId ? `?session_id=${sessionId}` : ''}`;
        
        const response = await fetch(url, {
            headers: {
                'X-WP-Nonce': window.WishCartWishlist.nonce,
            },
        });
        
        if (response.ok) {
            const data = await response.json();
            const inWishlist = data.in_wishlist || false;
            // Cache the result
            wishlistStatusCache.set(productId, inWishlist);
            return inWishlist;
        }
    } catch (error) {
        console.error('Error checking wishlist status:', error);
    }
    
    return false;
};

// Extract product ID and variation ID from add to cart button/form
const extractProductData = (element) => {
    let productId = null;
    let variationId = 0;
    
    // Try to get from button attributes
    productId = element.getAttribute('data-product-id') || 
                element.dataset?.productId ||
                element.closest('[data-product-id]')?.getAttribute('data-product-id');
    
    variationId = element.getAttribute('data-variation-id') || 
                  element.dataset?.variationId ||
                  element.closest('[data-variation-id]')?.getAttribute('data-variation-id');
    
    // Try to get from form
    const form = element.closest('form');
    if (form) {
        // WooCommerce form
        const productIdInput = form.querySelector('input[name="product_id"], input[name="add-to-cart"]');
        if (productIdInput && !productId) {
            productId = productIdInput.value;
        }
        
        const variationIdInput = form.querySelector('input[name="variation_id"]');
        if (variationIdInput && !variationId) {
            variationId = variationIdInput.value;
        }
        
        // FluentCart form
        if (!productId) {
            const fcProductId = form.querySelector('[data-product-id]');
            if (fcProductId) {
                productId = fcProductId.getAttribute('data-product-id');
            }
        }
    }
    
    // Try to get from URL or page context
    if (!productId) {
        const urlParams = new URLSearchParams(window.location.search);
        productId = urlParams.get('product_id') || urlParams.get('add-to-cart');
    }
    
    // Parse to integers
    productId = productId ? parseInt(productId, 10) : null;
    variationId = variationId ? parseInt(variationId, 10) : 0;
    
    return { productId, variationId };
};

// Track add to cart event if product is in wishlist
const trackAddToCartIfInWishlist = async (productId, variationId = 0) => {
    if (!productId || !window.WishCartWishlist) {
        return;
    }
    
    // Check if product is in wishlist
    const inWishlist = await checkProductInWishlist(productId);
    
    if (!inWishlist) {
        return; // Product not in wishlist, no need to track
    }
    
    // Track the event
    try {
        const sessionId = getSessionIdForTracking();
        const trackUrl = `${window.WishCartWishlist.apiUrl}wishlist/track-cart`;
        const trackBody = {
            product_id: productId,
            variation_id: variationId || 0,
            session_id: sessionId,
        };
        
        await fetch(trackUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.WishCartWishlist.nonce,
            },
            body: JSON.stringify(trackBody),
        });
    } catch (error) {
        // Don't block cart addition if tracking fails
        console.error('Error tracking cart event from product page:', error);
    }
};

// Setup event listeners for add to cart buttons
const setupAddToCartTracking = () => {
    // Use event delegation to catch all add to cart button clicks
    document.addEventListener('click', async (event) => {
        const target = event.target;
        
        // Check if clicked element or its parent is an add to cart button
        const addToCartButton = target.closest(
            '.fluent-cart-add-to-cart-button, ' +
            '.single_add_to_cart_button, ' +
            'button[type="submit"][name="add-to-cart"], ' +
            'button.add_to_cart_button, ' +
            '[data-action="add-to-cart"], ' +
            '.add-to-cart-button, ' +
            'button[class*="add-to-cart"], ' +
            'button[class*="add_to_cart"]'
        );
        
        if (!addToCartButton) {
            return;
        }
        
        // Extract product data
        const { productId, variationId } = extractProductData(addToCartButton);
        
        if (!productId) {
            return;
        }
        
        // Track if product is in wishlist (non-blocking)
        trackAddToCartIfInWishlist(productId, variationId).catch(err => {
            console.error('Error in add to cart tracking:', err);
        });
    }, true); // Use capture phase to catch early
    
    // Also listen for WooCommerce AJAX add to cart events
    if (typeof jQuery !== 'undefined') {
        jQuery(document.body).on('added_to_cart', (event, fragments, cart_hash, $button) => {
            if ($button && $button.length) {
                const { productId, variationId } = extractProductData($button[0]);
                if (productId) {
                    trackAddToCartIfInWishlist(productId, variationId).catch(err => {
                        console.error('Error in add to cart tracking:', err);
                    });
                }
            }
        });
    }
    
    // Listen for FluentCart add to cart events
    document.addEventListener('fluentcart:added_to_cart', (event) => {
        const detail = event.detail || {};
        const productId = detail.productId || detail.product_id;
        const variationId = detail.variationId || detail.variation_id || 0;
        
        if (productId) {
            trackAddToCartIfInWishlist(productId, variationId).catch(err => {
                console.error('Error in add to cart tracking:', err);
            });
        }
    });
};

// Re-mount buttons when new content is loaded (for AJAX-loaded products)
if (typeof MutationObserver !== 'undefined') {
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === 1) { // Element node
                    const containers = node.querySelectorAll ? node.querySelectorAll('.wishcart-wishlist-button-container') : [];
                    if (!containers.length) {
                        const fallbackContainer = injectFluentCartContainer();
                        if (fallbackContainer) {
                            mountWishlistButtonAtContainer(fallbackContainer);
                        }
                        injectWishlistIntoProductCards();
                        injectWishlistIntoArchiveEntries();
                        injectWishlistNearActionButtons();
                    }
                    containers.forEach((container) => {
                        mountWishlistButtonAtContainer(container);
                    });
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
}

