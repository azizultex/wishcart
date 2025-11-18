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
    });
} else {
    initializeSessionId();
    mountWishlistButtons();
    mountWishlistPage();
    mountSharedWishlistView();
}

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

