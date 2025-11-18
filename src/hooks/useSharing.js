import { useState, useCallback } from 'react';

/**
 * Custom hook for wishlist sharing operations
 */
export const useSharing = () => {
    const [isSharing, setIsSharing] = useState(false);
    const [shareData, setShareData] = useState(null);
    const [error, setError] = useState(null);

    const apiUrl = window.WishCartWishlist?.apiUrl || '/wp-json/wishcart/v1/';
    const nonce = window.WishCartWishlist?.nonce;

    // Create share link
    const createShare = useCallback(async (wishlistId, shareType = 'link', options = {}) => {
        setIsSharing(true);
        setError(null);

        try {
            const response = await fetch(`${apiUrl}share/create`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({
                    wishlist_id: wishlistId,
                    share_type: shareType,
                    ...options,
                }),
            });

            if (response.ok) {
                const data = await response.json();
                setShareData(data);
                return { success: true, data };
            } else {
                const data = await response.json();
                throw new Error(data.message || 'Failed to create share link');
            }
        } catch (err) {
            console.error('Error creating share:', err);
            setError(err.message);
            return { success: false, error: err.message };
        } finally {
            setIsSharing(false);
        }
    }, [apiUrl, nonce]);

    // Get share statistics
    const getShareStats = useCallback(async (shareToken) => {
        try {
            const response = await fetch(`${apiUrl}share/${shareToken}/stats`, {
                headers: {
                    'X-WP-Nonce': nonce,
                },
            });

            if (response.ok) {
                const data = await response.json();
                return { success: true, data };
            } else {
                const data = await response.json();
                throw new Error(data.message || 'Failed to get stats');
            }
        } catch (err) {
            console.error('Error getting share stats:', err);
            return { success: false, error: err.message };
        }
    }, [apiUrl, nonce]);

    // Track share click
    const trackClick = useCallback(async (shareToken) => {
        try {
            await fetch(`${apiUrl}share/${shareToken}/click`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': nonce,
                },
            });
        } catch (err) {
            console.error('Error tracking click:', err);
        }
    }, [apiUrl, nonce]);

    // Generate platform-specific share URLs
    const getShareUrl = useCallback((shareUrl, platform) => {
        const encodedUrl = encodeURIComponent(shareUrl);
        
        switch (platform) {
            case 'facebook':
                return `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`;
            case 'twitter':
                const text = encodeURIComponent('Check out my wishlist!');
                return `https://twitter.com/intent/tweet?url=${encodedUrl}&text=${text}`;
            case 'whatsapp':
                const message = encodeURIComponent(`Check out my wishlist: ${shareUrl}`);
                return `https://wa.me/?text=${message}`;
            case 'pinterest':
                const description = encodeURIComponent('My Wishlist');
                return `https://pinterest.com/pin/create/button/?url=${encodedUrl}&description=${description}`;
            case 'email':
                const subject = encodeURIComponent('Check out my wishlist');
                const body = encodeURIComponent(`I wanted to share my wishlist with you:\n\n${shareUrl}`);
                return `mailto:?subject=${subject}&body=${body}`;
            default:
                return shareUrl;
        }
    }, []);

    // Copy to clipboard
    const copyToClipboard = useCallback(async (text) => {
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                return { success: true };
            } else {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                
                try {
                    document.execCommand('copy');
                    textArea.remove();
                    return { success: true };
                } catch (err) {
                    textArea.remove();
                    throw err;
                }
            }
        } catch (err) {
            console.error('Error copying to clipboard:', err);
            return { success: false, error: err.message };
        }
    }, []);

    return {
        isSharing,
        shareData,
        error,
        createShare,
        getShareStats,
        trackClick,
        getShareUrl,
        copyToClipboard,
    };
};

