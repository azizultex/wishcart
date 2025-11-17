import React, { useState, useEffect } from 'react';
import { Heart, Trash2, ShoppingCart, Check, X } from 'lucide-react';
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

    // Load wishlist products
    useEffect(() => {
        const loadWishlist = async () => {
            if (!window.WishCartWishlist) {
                setIsLoading(false);
                return;
            }

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
                }
            } catch (error) {
                console.error('Error loading wishlist:', error);
            } finally {
                setIsLoading(false);
            }
        };

        loadWishlist();
    }, []);

    // Remove product from wishlist
    const removeProduct = async (productId) => {
        if (removingIds.has(productId) || !window.WishCartWishlist) {
            return;
        }

        setRemovingIds(prev => new Set(prev).add(productId));

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

    return (
        <div className="wishcart-wishlist-page">
            <div className="wishlist-header">
                <h1>Wishlist</h1>
                <p>Default wishlist</p>
            </div>

            <div className="wishlist-table-wrapper">
                <table className="wishlist-table">
                    <thead>
                        <tr>
                            <th className="checkbox-col">
                                <Checkbox
                                    checked={allSelected}
                                    onCheckedChange={toggleSelectAll}
                                />
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
                                    <Checkbox
                                        checked={selectedIds.has(product.id)}
                                        onCheckedChange={(checked) => toggleSelection(product.id, checked)}
                                    />
                                </td>
                                <td className="product-col">
                                    <button
                                        onClick={() => removeProduct(product.id)}
                                        className="remove-btn"
                                        disabled={removingIds.has(product.id)}
                                        aria-label="Remove from wishlist"
                                    >
                                        <X className="w-3 h-3" />
                                    </button>
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
        </div>
    );
};

export default WishlistPage;
