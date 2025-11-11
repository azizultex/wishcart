import React, { useState, useEffect } from 'react';
import { Heart } from 'lucide-react';
import { __ } from '@wordpress/i18n';
import { cn } from '../lib/utils';

const WishlistButton = ({ productId, className, customStyles, position = 'bottom' }) => {
    const [isInWishlist, setIsInWishlist] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [isAdding, setIsAdding] = useState(false);

    // Get session ID from cookie or create one
    const getSessionId = () => {
        if (window.WishCartWishlist?.isLoggedIn) {
            return null; // Logged in users don't need session ID
        }
        
        // Check cookie
        const cookies = document.cookie.split(';');
        for (let cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'wishcart_session_id') {
                return value;
            }
        }
        
        // Create new session ID if not exists
        const sessionId = 'wc_' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
        const expiryDays = 30;
        const expiryDate = new Date();
        expiryDate.setTime(expiryDate.getTime() + (expiryDays * 24 * 60 * 60 * 1000));
        document.cookie = `wishcart_session_id=${sessionId};expires=${expiryDate.toUTCString()};path=/;SameSite=Lax`;
        
        return sessionId;
    };

    // Check if product is in wishlist
    useEffect(() => {
        const checkWishlist = async () => {
            if (!productId || !window.WishCartWishlist) {
                setIsLoading(false);
                return;
            }

            try {
                const sessionId = getSessionId();
                const url = `${window.WishCartWishlist.apiUrl}wishlist/check/${productId}${sessionId ? `?session_id=${sessionId}` : ''}`;
                
                const response = await fetch(url, {
                    headers: {
                        'X-WP-Nonce': window.WishCartWishlist.nonce,
                    },
                });

                if (response.ok) {
                    const data = await response.json();
                    setIsInWishlist(data.in_wishlist || false);
                }
            } catch (error) {
                console.error('Error checking wishlist:', error);
            } finally {
                setIsLoading(false);
            }
        };

        checkWishlist();
    }, [productId]);

    // Toggle wishlist
    const toggleWishlist = async () => {
        if (isAdding || !productId || !window.WishCartWishlist) {
            return;
        }

        setIsAdding(true);

        try {
            const sessionId = getSessionId();
            const endpoint = isInWishlist ? 'wishlist/remove' : 'wishlist/add';
            const url = `${window.WishCartWishlist.apiUrl}${endpoint}`;
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.WishCartWishlist.nonce,
                },
                body: JSON.stringify({
                    product_id: productId,
                    session_id: sessionId,
                }),
            });

            if (response.ok) {
                setIsInWishlist(!isInWishlist);
            } else {
                const error = await response.json();
                console.error('Error toggling wishlist:', error);
            }
        } catch (error) {
            console.error('Error toggling wishlist:', error);
        } finally {
            setIsAdding(false);
        }
    };

    const buttonLabel = isInWishlist ? __('Saved to Wishlist', 'wish-cart') : __('Add to Wishlist', 'wish-cart');
    const srLabel = isInWishlist ? __('Remove from wishlist', 'wish-cart') : __('Add to wishlist', 'wish-cart');

    if (isLoading) {
        return (
            <div className={cn("wishcart-wishlist-button-loading", className)} style={customStyles}>
                <Heart className="w-5 h-5 animate-pulse" />
            </div>
        );
    }

    return (
        <button
            type="button"
            onClick={toggleWishlist}
            disabled={isAdding}
            className={cn(
                "wishcart-wishlist-button",
                "inline-flex items-center justify-center gap-2",
                "px-3 py-2 rounded-md",
                "text-sm font-medium",
                "transition-colors",
                "hover:bg-gray-100",
                "focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500",
                "disabled:opacity-50 disabled:cursor-not-allowed",
                isInWishlist && "text-red-600",
                !isInWishlist && "text-gray-600",
                position && `wishcart-placement-${position}`,
                className
            )}
            style={customStyles}
            data-position={position}
            aria-label={srLabel}
        >
            {isAdding ? (
                <Heart className="w-5 h-5 animate-pulse" />
            ) : (
                <Heart className={cn("w-5 h-5", isInWishlist && "fill-current")} />
            )}
            <span className="wishcart-wishlist-button__label">{buttonLabel}</span>
        </button>
    );
};

export default WishlistButton;

