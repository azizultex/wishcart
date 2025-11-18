import React, { useState, useEffect, useRef } from 'react';
import { Heart, Trash2, ShoppingCart, Check, X, Twitter, Mail, MessageCircle, Link2 } from 'lucide-react';
import { Card, CardContent } from './ui/card';
import { Button } from './ui/button';
import { Checkbox } from './ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import { cn } from '../lib/utils';
import '../styles/WishlistPage.scss';

const WishlistPage = () => {
    const [products, setProducts] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [removingIds, setRemovingIds] = useState(new Set());
    const [selectedIds, setSelectedIds] = useState(new Set());
    const [addingToCartIds, setAddingToCartIds] = useState(new Set());
    const [bulkAction, setBulkAction] = useState('');
    const [linkCopied, setLinkCopied] = useState(false);
    const [wishlists, setWishlists] = useState([]);
    const [currentWishlist, setCurrentWishlist] = useState(null);
    const [isLoadingWishlists, setIsLoadingWishlists] = useState(false);
    
    // Track if wishlist has been loaded to prevent infinite loops
    const hasLoadedRef = useRef(false);
    const loadedWishlistIdRef = useRef(null);

    // Get session ID from cookie
    const getSessionId = () => {
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

        if (window.WishCartWishlist?.sessionId) {
            return window.WishCartWishlist.sessionId;
        }

        return null;
    };

    // Load wishlists
    useEffect(() => {
        const loadWishlists = async () => {
            if (!window.WishCartWishlist) {
                return;
            }

            const shareCode = window.WishCartWishlist?.shareCode;
            
            // If viewing a shared wishlist, load it directly
            if (shareCode) {
                setIsLoadingWishlists(true);
                try {
                    const url = `${window.WishCartWishlist.apiUrl}wishlist/share/${shareCode}`;
                    const response = await fetch(url, {
                        headers: {
                            'X-WP-Nonce': window.WishCartWishlist.nonce,
                        },
                    });

                    if (response.ok) {
                        const data = await response.json();
                        if (data.wishlist) {
                            setCurrentWishlist(data.wishlist);
                            setProducts(data.products || []);
                            // Mark as loaded to prevent second useEffect from running
                            hasLoadedRef.current = true;
                            loadedWishlistIdRef.current = data.wishlist.id || data.wishlist.share_code;
                        }
                    }
                } catch (error) {
                    console.error('Error loading shared wishlist:', error);
                } finally {
                    setIsLoadingWishlists(false);
                    setIsLoading(false);
                }
                return;
            }

            // Load user's own wishlists
            setIsLoadingWishlists(true);
            try {
                const url = `${window.WishCartWishlist.apiUrl}wishlists`;
                const response = await fetch(url, {
                    headers: {
                        'X-WP-Nonce': window.WishCartWishlist.nonce,
                    },
                });

                if (response.ok) {
                    const data = await response.json();
                    setWishlists(data.wishlists || []);
                    
                    // Set default wishlist
                    const defaultWishlist = data.wishlists?.find(w => w.is_default === 1 || w.is_default === '1');
                    if (defaultWishlist) {
                        setCurrentWishlist(defaultWishlist);
                    } else if (data.wishlists?.length > 0) {
                        setCurrentWishlist(data.wishlists[0]);
                    }
                }
            } catch (error) {
                console.error('Error loading wishlists:', error);
            } finally {
                setIsLoadingWishlists(false);
            }
        };

        loadWishlists();
    }, []);

    // Load wishlist products
    useEffect(() => {
        const loadWishlist = async () => {
            if (!window.WishCartWishlist) {
                setIsLoading(false);
                return;
            }

            // Skip if viewing shared wishlist (already loaded in first effect)
            const shareCode = window.WishCartWishlist?.shareCode;
            if (shareCode && hasLoadedRef.current) {
                return;
            }

            // If no current wishlist but we have wishlists, wait for wishlists to load
            if (!currentWishlist && wishlists.length === 0 && isLoadingWishlists) {
                return;
            }

            // If no wishlists exist, try to load using old method for backward compatibility
            if (!currentWishlist && wishlists.length === 0) {
                setIsLoading(true);
                try {
                    const sessionId = getSessionId();
                    const url = `${window.WishCartWishlist.apiUrl}wishlist${sessionId ? `?session_id=${sessionId}` : ''}`;
                    
                    const response = await fetch(url, {
                        headers: {
                            'X-WP-Nonce': window.WishCartWishlist.nonce,
                        },
                    });

                    if (response.ok) {
                        const data = await response.json();
                        setProducts(data.products || []);
                        hasLoadedRef.current = true;
                    }
                } catch (error) {
                    console.error('Error loading wishlist:', error);
                } finally {
                    setIsLoading(false);
                }
                return;
            }

            if (!currentWishlist) {
                setIsLoading(false);
                return;
            }

            // Check if wishlist ID has changed - if not, skip API call
            const currentWishlistId = currentWishlist.id || currentWishlist.share_code;
            if (hasLoadedRef.current && loadedWishlistIdRef.current === currentWishlistId) {
                return;
            }

            setIsLoading(true);
            try {
                const sessionId = getSessionId();
                let url = `${window.WishCartWishlist.apiUrl}wishlist`;
                const params = new URLSearchParams();
                
                // Use share_code if available, otherwise use wishlist_id
                if (currentWishlist.share_code) {
                    params.append('share_code', currentWishlist.share_code);
                } else if (currentWishlist.id) {
                    params.append('wishlist_id', currentWishlist.id);
                } else if (sessionId) {
                    params.append('session_id', sessionId);
                }
                
                if (params.toString()) {
                    url += `?${params.toString()}`;
                }
                
                const response = await fetch(url, {
                    headers: {
                        'X-WP-Nonce': window.WishCartWishlist.nonce,
                    },
                });

                if (response.ok) {
                    const data = await response.json();
                    setProducts(data.products || []);
                    if (data.wishlist) {
                        // Only update currentWishlist if the wishlist ID actually changed
                        const newWishlistId = data.wishlist.id || data.wishlist.share_code;
                        if (newWishlistId !== currentWishlistId) {
                            setCurrentWishlist(data.wishlist);
                            loadedWishlistIdRef.current = newWishlistId;
                        } else {
                            // Same wishlist, just update the ref to mark as loaded
                            loadedWishlistIdRef.current = newWishlistId;
                        }
                    }
                    hasLoadedRef.current = true;
                }
            } catch (error) {
                console.error('Error loading wishlist:', error);
            } finally {
                setIsLoading(false);
            }
        };

        loadWishlist();
    }, [currentWishlist, wishlists, isLoadingWishlists]);

    // Remove product from wishlist
    const removeProduct = async (productId) => {
        // Check if viewing a shared wishlist (not owned by current user)
        const shareCode = window.WishCartWishlist?.shareCode;
        const isViewingShared = shareCode && currentWishlist && 
            (currentWishlist.user_id !== window.WishCartWishlist?.userId || 
             (currentWishlist.user_id && !window.WishCartWishlist?.isLoggedIn));
        
        if (isViewingShared) {
            alert('You can only remove items from your own wishlist');
            return;
        }

        if (removingIds.has(productId) || !window.WishCartWishlist) {
            return;
        }

        setRemovingIds(prev => new Set(prev).add(productId));

        try {
            const sessionId = getSessionId();
            const url = `${window.WishCartWishlist.apiUrl}wishlist/remove`;
            
            const body = {
                product_id: productId,
                session_id: sessionId,
            };
            
            if (currentWishlist && currentWishlist.id) {
                body.wishlist_id = currentWishlist.id;
            }
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.WishCartWishlist.nonce,
                },
                body: JSON.stringify(body),
            });

            if (response.ok) {
                setProducts(prev => prev.filter(p => p.id !== productId));
                setSelectedIds(prev => {
                    const next = new Set(prev);
                    next.delete(productId);
                    return next;
                });
            } else {
                const error = await response.json();
                console.error('Error removing product:', error);
                alert('Failed to remove product from wishlist');
            }
        } catch (error) {
            console.error('Error removing product:', error);
            alert('Failed to remove product from wishlist');
        } finally {
            setRemovingIds(prev => {
                const next = new Set(prev);
                next.delete(productId);
                return next;
            });
        }
    };

    // Remove multiple products
    const removeSelectedProducts = async () => {
        if (selectedIds.size === 0) {
            return;
        }

        const idsToRemove = Array.from(selectedIds);
        for (const productId of idsToRemove) {
            await removeProduct(productId);
        }
        setSelectedIds(new Set());
        setBulkAction('');
    };

    // Add product to cart
    const addToCart = async (productId) => {
        if (addingToCartIds.has(productId)) {
            return;
        }

        setAddingToCartIds(prev => new Set(prev).add(productId));

        try {
            // Navigate to product page - FluentCart will handle adding to cart
            window.location.href = products.find(p => p.id === productId)?.permalink || '#';
        } catch (error) {
            console.error('Error adding to cart:', error);
            alert('Failed to add product to cart');
        } finally {
            setAddingToCartIds(prev => {
                const next = new Set(prev);
                next.delete(productId);
                return next;
            });
        }
    };

    // Add selected products to cart
    const addSelectedToCart = async () => {
        if (selectedIds.size === 0) {
            return;
        }

        const selectedProducts = products.filter(p => selectedIds.has(p.id));
        if (selectedProducts.length > 0) {
            // Navigate to first product page
            window.location.href = selectedProducts[0].permalink;
        }
    };

    // Add all products to cart
    const addAllToCart = async () => {
        if (products.length === 0) {
            return;
        }

        // Navigate to first product page
        window.location.href = products[0].permalink;
    };

    // Handle bulk action
    const handleBulkAction = () => {
        if (bulkAction === 'remove') {
            removeSelectedProducts();
        }
    };

    // Toggle select all
    const toggleSelectAll = (checked) => {
        if (checked) {
            setSelectedIds(new Set(products.map(p => p.id)));
        } else {
            setSelectedIds(new Set());
        }
    };

    // Toggle individual selection
    const toggleSelection = (productId, checked) => {
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (checked) {
                next.add(productId);
            } else {
                next.delete(productId);
            }
            return next;
        });
    };

    // Format price
    const formatPrice = (price, regularPrice, isOnSale) => {
        if (!price && price !== 0) return '';
        
        const currency = 'USD'; // You might want to get this from settings
        const formattedPrice = new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency,
        }).format(price);

        if (isOnSale && regularPrice && regularPrice > price) {
            const formattedRegular = new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: currency,
            }).format(regularPrice);
            
            return (
                <div className="flex flex-col">
                    <span className="price-current">{formattedPrice}</span>
                    <span className="price-regular">{formattedRegular}</span>
                </div>
            );
        }

        return <span className="font-normal text-black">{formattedPrice}</span>;
    };

    // Get stock status icon
    const getStockStatusIcon = (status) => {
        if (status === 'In stock' || status === 'Available on backorder') {
            return <Check className="w-4 h-4 text-black" />;
        }
        return null;
    };

    // Get wishlist share URL
    const getWishlistShareUrl = () => {
        if (currentWishlist && currentWishlist.share_code) {
            const pageUrl = window.location.origin;
            // Get base path (remove share code if present)
            let basePath = window.location.pathname;
            if (basePath.includes('/')) {
                const parts = basePath.split('/').filter(p => p);
                // Remove the last part if it looks like a share code (alphanumeric, 6 chars)
                if (parts.length > 0 && /^[a-z0-9]{6}$/i.test(parts[parts.length - 1])) {
                    parts.pop();
                }
                basePath = '/' + parts.join('/');
            }
            if (basePath === '/' || !basePath.includes('wishlist')) {
                basePath = '/wishlist';
            }
            return `${pageUrl}${basePath}/${currentWishlist.share_code}/`;
        }
        return window.location.href;
    };

    // Get wishlist share text
    const getWishlistShareText = () => {
        return 'Check out my wishlist!';
    };

    // Share on Facebook
    const shareOnFacebook = () => {
        const url = encodeURIComponent(getWishlistShareUrl());
        const shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${url}`;
        window.open(shareUrl, '_blank', 'width=600,height=400');
    };

    // Share on Twitter
    const shareOnTwitter = () => {
        const url = encodeURIComponent(getWishlistShareUrl());
        const text = encodeURIComponent(getWishlistShareText());
        const shareUrl = `https://twitter.com/intent/tweet?url=${url}&text=${text}`;
        window.open(shareUrl, '_blank', 'width=600,height=400');
    };

    // Share on Pinterest
    const shareOnPinterest = () => {
        const url = encodeURIComponent(getWishlistShareUrl());
        const description = encodeURIComponent(getWishlistShareText());
        const shareUrl = `https://pinterest.com/pin/create/button/?url=${url}&description=${description}`;
        window.open(shareUrl, '_blank', 'width=600,height=400');
    };

    // Share on WhatsApp
    const shareOnWhatsApp = () => {
        const url = encodeURIComponent(getWishlistShareUrl());
        const text = encodeURIComponent(`${getWishlistShareText()} ${getWishlistShareUrl()}`);
        const shareUrl = `https://wa.me/?text=${text}`;
        window.open(shareUrl, '_blank');
    };

    // Share via Email
    const shareViaEmail = () => {
        const url = getWishlistShareUrl();
        const subject = encodeURIComponent('My Wishlist');
        const body = encodeURIComponent(`${getWishlistShareText()}\n\n${url}`);
        const mailtoUrl = `mailto:?subject=${subject}&body=${body}`;
        window.location.href = mailtoUrl;
    };

    // Copy wishlist link
    const copyWishlistLink = async () => {
        try {
            const url = getWishlistShareUrl();
            await navigator.clipboard.writeText(url);
            setLinkCopied(true);
            setTimeout(() => {
                setLinkCopied(false);
            }, 2000);
        } catch (error) {
            console.error('Failed to copy link:', error);
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = getWishlistShareUrl();
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                setLinkCopied(true);
                setTimeout(() => {
                    setLinkCopied(false);
                }, 2000);
            } catch (err) {
                console.error('Fallback copy failed:', err);
            }
            document.body.removeChild(textArea);
        }
    };

    if (isLoading) {
        return (
            <div className="wishcart-wishlist-page container mx-auto px-4 py-8">
                <div className="flex items-center justify-center min-h-[400px]">
                    <div className="text-center">
                        <Heart className="w-12 h-12 mx-auto mb-4 animate-pulse text-gray-400" />
                        <p className="text-gray-600">Loading wishlist...</p>
                    </div>
                </div>
            </div>
        );
    }

    if (products.length === 0) {
        return (
            <div className="wishcart-wishlist-page container mx-auto px-4 py-8">
                <Card>
                    <CardContent className="flex flex-col items-center justify-center min-h-[400px] py-12">
                        <Heart className="w-16 h-16 mb-4 text-gray-300" />
                        <h1 className="text-2xl font-bold mb-2">Your wishlist is empty</h1>
                        <p className="text-gray-600 mb-6">Start adding products to your wishlist!</p>
                        <Button onClick={() => window.location.href = '/'}>
                            Continue Shopping
                        </Button>
                    </CardContent>
                </Card>
            </div>
        );
    }

    const allSelected = products.length > 0 && selectedIds.size === products.length;

    // Handle wishlist selection
    const handleWishlistSelect = (wishlistId) => {
        const wishlist = wishlists.find(w => w.id.toString() === wishlistId.toString());
        if (wishlist) {
            // Reset refs when switching to a different wishlist
            const newWishlistId = wishlist.id || wishlist.share_code;
            if (loadedWishlistIdRef.current !== newWishlistId) {
                hasLoadedRef.current = false;
                loadedWishlistIdRef.current = null;
            }
            setCurrentWishlist(wishlist);
        }
    };

    // Create new wishlist
    const createNewWishlist = async () => {
        if (!window.WishCartWishlist) {
            return;
        }

        const name = prompt('Enter wishlist name:', 'New Wishlist');
        if (!name) {
            return;
        }

        try {
            const response = await fetch(`${window.WishCartWishlist.apiUrl}wishlists`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.WishCartWishlist.nonce,
                },
                body: JSON.stringify({
                    name: name,
                    is_default: false,
                }),
            });

            if (response.ok) {
                const data = await response.json();
                // Reload wishlists
                const wishlistsResponse = await fetch(`${window.WishCartWishlist.apiUrl}wishlists`, {
                    headers: {
                        'X-WP-Nonce': window.WishCartWishlist.nonce,
                    },
                });
                if (wishlistsResponse.ok) {
                    const wishlistsData = await wishlistsResponse.json();
                    setWishlists(wishlistsData.wishlists || []);
                    if (data.wishlist) {
                        // Reset refs for new wishlist
                        hasLoadedRef.current = false;
                        loadedWishlistIdRef.current = null;
                        setCurrentWishlist(data.wishlist);
                    }
                }
            }
        } catch (error) {
            console.error('Error creating wishlist:', error);
            alert('Failed to create wishlist');
        }
    };

    // Check if viewing shared wishlist
    const shareCode = window.WishCartWishlist?.shareCode;
    const isViewingShared = shareCode && currentWishlist && 
        (currentWishlist.user_id !== window.WishCartWishlist?.userId || 
         (currentWishlist.user_id && !window.WishCartWishlist?.isLoggedIn));

    return (
        <div className="wishcart-wishlist-page">
            {wishlists.length > 0 && !isViewingShared && (
                <div className="wishlist-selector">
                    <Select 
                        value={currentWishlist ? currentWishlist.id.toString() : ''} 
                        onValueChange={handleWishlistSelect}
                    >
                        <SelectTrigger className="wishlist-select-trigger">
                            <SelectValue placeholder="Select Wishlist" />
                        </SelectTrigger>
                        <SelectContent>
                            {wishlists.map((wishlist) => (
                                <SelectItem key={wishlist.id} value={wishlist.id.toString()}>
                                    {wishlist.name} {wishlist.is_default ? '(Default)' : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        onClick={createNewWishlist}
                        className="create-wishlist-btn"
                        variant="outline"
                    >
                        Create New
                    </Button>
                </div>
            )}
            
            <div className="wishlist-header">
                <h1>Wishlist</h1>
                <p>{currentWishlist ? currentWishlist.name : 'Default wishlist'}</p>
            </div>

            <div className="wishlist-table-wrapper">
                <table className="wishlist-table">
                    <thead>
                        <tr>
                            <th className="checkbox-col">
                                {!isViewingShared && (
                                    <Checkbox
                                        checked={allSelected}
                                        onCheckedChange={toggleSelectAll}
                                    />
                                )}
                            </th>
                            <th>Product Name</th>
                            <th>Unit Price</th>
                            <th>Date Added</th>
                            <th>Stock Status</th>
                            <th className="action-col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {products.map((product) => (
                            <tr key={product.id}>
                                <td className="checkbox-col">
                                    {!isViewingShared && (
                                        <Checkbox
                                            checked={selectedIds.has(product.id)}
                                            onCheckedChange={(checked) => toggleSelection(product.id, checked)}
                                        />
                                    )}
                                </td>
                                <td className="product-col">
                                    {!isViewingShared && (
                                        <button
                                            onClick={() => removeProduct(product.id)}
                                            className="remove-btn"
                                            disabled={removingIds.has(product.id)}
                                            aria-label="Remove from wishlist"
                                        >
                                            <X className="w-3 h-3" />
                                        </button>
                                    )}
                                    {isViewingShared && (
                                        <div className="remove-btn-placeholder"></div>
                                    )}
                                    {product.image_url ? (
                                        <img
                                            src={product.image_url}
                                            alt={product.name}
                                            className="product-image"
                                        />
                                    ) : (
                                        <div className="product-image-placeholder">
                                            <ShoppingCart className="w-6 h-6" />
                                        </div>
                                    )}
                                    <a
                                        href={product.permalink}
                                        className="product-name"
                                    >
                                        {product.name}
                                    </a>
                                </td>
                                <td className="price-col">
                                    {formatPrice(product.price, product.regular_price, product.is_on_sale)}
                                </td>
                                <td className="date-col">
                                    {product.date_added || 'N/A'}
                                </td>
                                <td className="stock-col">
                                    <div className="stock-status">
                                        {getStockStatusIcon(product.stock_status)}
                                        <span>{product.stock_status || 'In stock'}</span>
                                    </div>
                                </td>
                                <td className="action-col">
                                    <Button
                                        size="sm"
                                        onClick={() => addToCart(product.id)}
                                        disabled={addingToCartIds.has(product.id)}
                                        className="add-to-cart-btn"
                                        variant="outline"
                                    >
                                        {addingToCartIds.has(product.id) ? (
                                            'Adding...'
                                        ) : (
                                            'Add to Cart'
                                        )}
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {!isViewingShared && (
                <div className="bulk-actions">
                    <div className="bulk-actions-left">
                        <Select value={bulkAction} onValueChange={setBulkAction}>
                            <SelectTrigger className="actions-select">
                                <SelectValue placeholder="Actions" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="remove">Remove selected</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button
                            onClick={handleBulkAction}
                            disabled={!bulkAction || selectedIds.size === 0}
                            className="bulk-action-btn"
                        >
                            Apply Action
                        </Button>
                    </div>
                    <div className="bulk-actions-right">
                        <Button
                            onClick={addSelectedToCart}
                            disabled={selectedIds.size === 0}
                            className="bulk-action-btn"
                        >
                            Add Selected to Cart
                        </Button>
                        <Button
                            onClick={addAllToCart}
                            disabled={products.length === 0}
                            className="bulk-action-btn"
                        >
                            Add All to Cart
                        </Button>
                    </div>
                </div>
            )}

            {!isViewingShared && (
                <div className="share-section">
                    <span className="share-label">Share on</span>
                    <div className="share-icons">
                    <button
                        onClick={shareOnFacebook}
                        className="share-icon"
                        aria-label="Share on Facebook"
                        title="Share on Facebook"
                    >
                        <span className="share-icon-text">f</span>
                    </button>
                    <button
                        onClick={shareOnTwitter}
                        className="share-icon"
                        aria-label="Share on Twitter"
                        title="Share on Twitter"
                    >
                        <Twitter className="share-icon-svg" />
                    </button>
                    <button
                        onClick={shareOnPinterest}
                        className="share-icon"
                        aria-label="Share on Pinterest"
                        title="Share on Pinterest"
                    >
                        <span className="share-icon-text">P</span>
                    </button>
                    <button
                        onClick={shareOnWhatsApp}
                        className="share-icon"
                        aria-label="Share on WhatsApp"
                        title="Share on WhatsApp"
                    >
                        <MessageCircle className="share-icon-svg" />
                    </button>
                    <button
                        onClick={copyWishlistLink}
                        className="share-icon"
                        aria-label="Copy link"
                        title="Copy link"
                    >
                        {linkCopied ? (
                            <Check className="share-icon-svg" />
                        ) : (
                            <Link2 className="share-icon-svg" />
                        )}
                    </button>
                    <button
                        onClick={shareViaEmail}
                        className="share-icon"
                        aria-label="Share via Email"
                        title="Share via Email"
                    >
                        <Mail className="share-icon-svg" />
                    </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default WishlistPage;
