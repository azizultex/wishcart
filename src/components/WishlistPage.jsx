import React, { useState, useEffect } from 'react';
import { Heart, Trash2, ShoppingCart } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from './ui/card';
import { Button } from './ui/button';
import { cn } from '../lib/utils';

const WishlistPage = () => {
    const [products, setProducts] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [removingIds, setRemovingIds] = useState(new Set());

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
                <div className="flex items-center gap-2">
                    <span className="text-red-600 font-semibold">{formattedPrice}</span>
                    <span className="text-gray-400 line-through text-sm">{formattedRegular}</span>
                </div>
            );
        }

        return <span className="font-semibold">{formattedPrice}</span>;
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
                        <CardTitle className="text-2xl mb-2">Your wishlist is empty</CardTitle>
                        <p className="text-gray-600 mb-6">Start adding products to your wishlist!</p>
                        <Button onClick={() => window.location.href = '/'}>
                            Continue Shopping
                        </Button>
                    </CardContent>
                </Card>
            </div>
        );
    }

    return (
        <div className="wishcart-wishlist-page container mx-auto px-4 py-8">
            <div className="mb-6">
                <h1 className="text-3xl font-bold mb-2">My Wishlist</h1>
                <p className="text-gray-600">{products.length} {products.length === 1 ? 'item' : 'items'}</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                {products.map((product) => (
                    <Card key={product.id} className="overflow-hidden hover:shadow-lg transition-shadow">
                        <div className="relative">
                            {product.image_url ? (
                                <img
                                    src={product.image_url}
                                    alt={product.name}
                                    className="w-full h-48 object-cover"
                                />
                            ) : (
                                <div className="w-full h-48 bg-gray-200 flex items-center justify-center">
                                    <ShoppingCart className="w-12 h-12 text-gray-400" />
                                </div>
                            )}
                            <button
                                onClick={() => removeProduct(product.id)}
                                disabled={removingIds.has(product.id)}
                                className={cn(
                                    "absolute top-2 right-2 p-2 rounded-full bg-white shadow-md",
                                    "hover:bg-red-50 transition-colors",
                                    "disabled:opacity-50 disabled:cursor-not-allowed"
                                )}
                                aria-label="Remove from wishlist"
                            >
                                {removingIds.has(product.id) ? (
                                    <Trash2 className="w-4 h-4 animate-pulse text-gray-400" />
                                ) : (
                                    <Trash2 className="w-4 h-4 text-red-600" />
                                )}
                            </button>
                        </div>
                        <CardHeader>
                            <CardTitle className="text-lg line-clamp-2">
                                <a
                                    href={product.permalink}
                                    className="hover:text-blue-600 transition-colors"
                                >
                                    {product.name}
                                </a>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-4">
                                {formatPrice(product.price, product.regular_price, product.is_on_sale)}
                            </div>
                            <Button
                                className="w-full"
                                onClick={() => window.location.href = product.permalink}
                            >
                                View Product
                            </Button>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </div>
    );
};

export default WishlistPage;

