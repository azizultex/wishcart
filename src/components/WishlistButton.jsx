import React, { useState, useEffect } from 'react';
import { Heart } from 'lucide-react';
import { __ } from '@wordpress/i18n';
import { cn } from '../lib/utils';
import WishlistSelectorModal from './WishlistSelectorModal';
import * as LucideIcons from 'lucide-react';

const WishlistButton = ({ productId, className, customStyles, position = 'bottom' }) => {
    const [isInWishlist, setIsInWishlist] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [isAdding, setIsAdding] = useState(false);
    const [isModalOpen, setIsModalOpen] = useState(false);

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

        if (window.WishCartWishlist?.sessionId) {
            return window.WishCartWishlist.sessionId;
        }

        // Create new session ID if not exists
        const sessionId = 'wc_' + Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
        const expiryDays = 30;
        const expiryDate = new Date();
        expiryDate.setTime(expiryDate.getTime() + (expiryDays * 24 * 60 * 60 * 1000));
        document.cookie = `wishcart_session_id=${sessionId};expires=${expiryDate.toUTCString()};path=/;SameSite=Lax`;
        if (window.WishCartWishlist) {
            window.WishCartWishlist.sessionId = sessionId;
        }

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

    // Add product directly to default wishlist (when multiple wishlists disabled)
    const addToDefaultWishlist = async () => {
        setIsAdding(true);
        try {
            const sessionId = getSessionId();
            const url = `${window.WishCartWishlist.apiUrl}wishlist/add`;
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.WishCartWishlist.nonce,
                },
                body: JSON.stringify({
                    product_id: productId,
                    session_id: sessionId,
                    // No wishlist_id means it will use default wishlist
                }),
            });

            if (response.ok) {
                const data = await response.json();
                setIsInWishlist(true);
                if (data && data.message) {
                    console.log(data.message);
                }
            } else {
                const error = await response.json();
                console.error('Error adding to wishlist:', error);
            }
        } catch (error) {
            console.error('Error adding to wishlist:', error);
        } finally {
            setIsAdding(false);
        }
    };

    // Toggle wishlist
    const toggleWishlist = async () => {
        if (isAdding || !productId || !window.WishCartWishlist) {
            return;
        }

        // If product is already in wishlist, remove it
        if (isInWishlist) {
            setIsAdding(true);
            try {
                const sessionId = getSessionId();
                const url = `${window.WishCartWishlist.apiUrl}wishlist/remove`;
                
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
                    setIsInWishlist(false);
                } else {
                    const error = await response.json();
                    console.error('Error removing from wishlist:', error);
                }
            } catch (error) {
                console.error('Error removing from wishlist:', error);
            } finally {
                setIsAdding(false);
            }
        } else {
            // Check if multiple wishlists are enabled
            const enableMultipleWishlists = window.WishCartWishlist?.enableMultipleWishlists || false;
            
            if (enableMultipleWishlists) {
                // If multiple wishlists enabled, open modal to select wishlist
                setIsModalOpen(true);
            } else {
                // If multiple wishlists disabled, add directly to default wishlist
                await addToDefaultWishlist();
            }
        }
    };

    // Handle successful addition from modal
    const handleModalSuccess = (data) => {
        setIsInWishlist(true);
        // Optional: Show a success message
        if (data && data.message) {
            console.log(data.message);
        }
    };

    // Get customization settings
    const customization = window.WishCartWishlist?.buttonCustomization || {};
    const colors = customization.colors || {};
    const iconConfig = customization.icon || {};
    const labels = customization.labels || {};

    // Support both old and new icon structure
    let addToWishlistIcon, savedWishlistIcon;
    
    if (iconConfig.addToWishlist) {
        // New format
        addToWishlistIcon = iconConfig.addToWishlist;
        savedWishlistIcon = iconConfig.savedWishlist || iconConfig.addToWishlist;
    } else if (iconConfig.type || iconConfig.value || iconConfig.customUrl) {
        // Old format - migrate on the fly
        const iconValue = iconConfig.value ? 
            iconConfig.value.charAt(0).toUpperCase() + iconConfig.value.slice(1) : 
            'Heart';
        addToWishlistIcon = {
            type: iconConfig.type || 'predefined',
            value: iconValue,
            customUrl: iconConfig.customUrl || ''
        };
        savedWishlistIcon = {
            type: iconConfig.type || 'predefined',
            value: iconValue,
            customUrl: iconConfig.customUrl || ''
        };
    } else {
        // Default values
        addToWishlistIcon = { type: 'predefined', value: 'Heart', customUrl: '' };
        savedWishlistIcon = { type: 'predefined', value: 'Heart', customUrl: '' };
    }

    // Get button labels
    const defaultAddLabel = __('Add to Wishlist', 'wish-cart');
    const defaultSavedLabel = __('Saved to Wishlist', 'wish-cart');
    const buttonLabel = isInWishlist 
        ? (labels.saved || defaultSavedLabel)
        : (labels.add || defaultAddLabel);
    const srLabel = isInWishlist ? __('Remove from wishlist', 'wish-cart') : __('Add to wishlist', 'wish-cart');

    // Get icon component based on wishlist state
    const getIconComponent = () => {
        const currentIcon = isInWishlist ? savedWishlistIcon : addToWishlistIcon;
        
        if (currentIcon.type === 'custom' && currentIcon.customUrl) {
            return (
                <img
                    src={currentIcon.customUrl}
                    alt=""
                    className={cn("wishcart-wishlist-button__icon", isInWishlist && "wishcart-wishlist-button__icon--filled")}
                    style={{ width: '1.125rem', height: '1.125rem' }}
                />
            );
        }

        // Handle predefined icon
        const iconValue = currentIcon.value || 'Heart';
        const IconComponent = LucideIcons[iconValue] || Heart;
        
        return (
            <IconComponent className={cn("wishcart-wishlist-button__icon", isInWishlist && "wishcart-wishlist-button__icon--filled")} />
        );
    };

    // Build dynamic styles
    const buildButtonStyles = () => {
        const baseStyles = customStyles || {};
        const dynamicStyles = {};

        if (colors.background) {
            dynamicStyles['--wishlist-bg'] = colors.background;
        }
        if (colors.text) {
            dynamicStyles['--wishlist-text'] = colors.text;
        }
        if (colors.border) {
            dynamicStyles['--wishlist-border'] = colors.border;
        }
        if (colors.hoverBackground) {
            dynamicStyles['--wishlist-hover-bg'] = colors.hoverBackground;
        }
        if (colors.hoverText) {
            dynamicStyles['--wishlist-hover-text'] = colors.hoverText;
        }
        if (colors.activeBackground) {
            dynamicStyles['--wishlist-active-bg'] = colors.activeBackground;
        }
        if (colors.activeText) {
            dynamicStyles['--wishlist-active-text'] = colors.activeText;
        }
        if (colors.activeBorder) {
            dynamicStyles['--wishlist-active-border'] = colors.activeBorder;
        }
        if (colors.focusBorder) {
            dynamicStyles['--wishlist-focus-border'] = colors.focusBorder;
        }

        // Apply inline styles for immediate effect
        if (!isInWishlist) {
            if (colors.background) dynamicStyles.backgroundColor = colors.background;
            if (colors.text) dynamicStyles.color = colors.text;
            if (colors.border) dynamicStyles.borderColor = colors.border;
        } else {
            if (colors.activeBackground) dynamicStyles.backgroundColor = colors.activeBackground;
            if (colors.activeText) dynamicStyles.color = colors.activeText;
            if (colors.activeBorder) dynamicStyles.borderColor = colors.activeBorder;
        }

        return { ...baseStyles, ...dynamicStyles };
    };

    if (isLoading) {
        const renderLoadingIcon = () => {
            const currentIcon = addToWishlistIcon; // Use add icon for loading state
            if (currentIcon.type === 'custom' && currentIcon.customUrl) {
                return (
                    <img
                        src={currentIcon.customUrl}
                        alt=""
                        className="wishcart-wishlist-button__icon wishcart-wishlist-button__icon--loading"
                        style={{ width: '1.125rem', height: '1.125rem' }}
                    />
                );
            }
            const iconValue = currentIcon.value || 'Heart';
            const IconComponent = LucideIcons[iconValue] || Heart;
            return <IconComponent className="wishcart-wishlist-button__icon wishcart-wishlist-button__icon--loading" />;
        };

        return (
            <div className={cn("wishcart-wishlist-button-loading", className)} style={buildButtonStyles()}>
                {renderLoadingIcon()}
            </div>
        );
    }

    return (
        <>
            <WishlistSelectorModal
                isOpen={isModalOpen}
                onClose={() => setIsModalOpen(false)}
                productId={productId}
                onSuccess={handleModalSuccess}
            />
            <button
                type="button"
                onClick={toggleWishlist}
                disabled={isAdding}
                className={cn(
                    "wishcart-wishlist-button",
                    isInWishlist && "wishcart-wishlist-button--active",
                    position && `wishcart-placement-${position}`,
                    className
                )}
                style={buildButtonStyles()}
                data-position={position}
                aria-label={srLabel}
            onMouseEnter={(e) => {
                if (isInWishlist) {
                    if (colors.activeBackground) {
                        e.currentTarget.style.backgroundColor = colors.activeBackground;
                    }
                    if (colors.activeText) {
                        e.currentTarget.style.color = colors.activeText;
                    }
                } else {
                    if (colors.hoverBackground) {
                        e.currentTarget.style.backgroundColor = colors.hoverBackground;
                    }
                    if (colors.hoverText) {
                        e.currentTarget.style.color = colors.hoverText;
                    }
                }
            }}
            onMouseLeave={(e) => {
                if (isInWishlist) {
                    if (colors.activeBackground) {
                        e.currentTarget.style.backgroundColor = colors.activeBackground;
                    }
                    if (colors.activeText) {
                        e.currentTarget.style.color = colors.activeText;
                    }
                } else {
                    if (colors.background) {
                        e.currentTarget.style.backgroundColor = colors.background;
                    }
                    if (colors.text) {
                        e.currentTarget.style.color = colors.text;
                    }
                }
            }}
            onFocus={(e) => {
                if (colors.focusBorder) {
                    e.currentTarget.style.borderColor = colors.focusBorder;
                    e.currentTarget.style.boxShadow = `0 0 0 3px ${colors.focusBorder}33`;
                }
            }}
            onBlur={(e) => {
                if (isInWishlist && colors.activeBorder) {
                    e.currentTarget.style.borderColor = colors.activeBorder;
                } else if (colors.border) {
                    e.currentTarget.style.borderColor = colors.border;
                }
                e.currentTarget.style.boxShadow = '';
            }}
        >
            {isAdding ? (
                (() => {
                    const currentIcon = isInWishlist ? savedWishlistIcon : addToWishlistIcon;
                    if (currentIcon.type === 'custom' && currentIcon.customUrl) {
                        return (
                            <img
                                src={currentIcon.customUrl}
                                alt=""
                                className="wishcart-wishlist-button__icon wishcart-wishlist-button__icon--loading"
                                style={{ width: '1.125rem', height: '1.125rem' }}
                            />
                        );
                    }
                    const iconValue = currentIcon.value || 'Heart';
                    const IconComponent = LucideIcons[iconValue] || Heart;
                    return <IconComponent className="wishcart-wishlist-button__icon wishcart-wishlist-button__icon--loading" />;
                })()
            ) : (
                getIconComponent()
            )}
            <span className="wishcart-wishlist-button__label">{buttonLabel}</span>
            </button>
        </>
    );
};

export default WishlistButton;

