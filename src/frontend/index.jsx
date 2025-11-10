import React from 'react';
import { createRoot } from 'react-dom/client';
import WishlistButton from '../components/WishlistButton';
import WishlistPage from '../components/WishlistPage';
import '../styles/WishlistButton.scss';
import '../styles/WishlistPage.scss';

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
    const containers = document.querySelectorAll('.wishcart-wishlist-button-container');
    
    containers.forEach((container) => {
        const productId = container.getAttribute('data-product-id');
        
        if (productId) {
            const root = createRoot(container);
            root.render(<WishlistButton productId={parseInt(productId, 10)} />);
        }
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

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initializeSessionId();
        mountWishlistButtons();
        mountWishlistPage();
    });
} else {
    initializeSessionId();
    mountWishlistButtons();
    mountWishlistPage();
}

// Re-mount buttons when new content is loaded (for AJAX-loaded products)
if (typeof MutationObserver !== 'undefined') {
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === 1) { // Element node
                    const containers = node.querySelectorAll ? node.querySelectorAll('.wishcart-wishlist-button-container') : [];
                    containers.forEach((container) => {
                        if (!container.hasAttribute('data-mounted')) {
                            container.setAttribute('data-mounted', 'true');
                            const productId = container.getAttribute('data-product-id');
                            if (productId) {
                                const root = createRoot(container);
                                root.render(<WishlistButton productId={parseInt(productId, 10)} />);
                            }
                        }
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

