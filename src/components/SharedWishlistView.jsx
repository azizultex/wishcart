import React, { useState, useEffect } from 'react';
import { Heart, ShoppingCart, AlertCircle, Loader } from 'lucide-react';
import { Card, CardContent } from './ui/card';
import { Button } from './ui/button';
import '../styles/SharedWishlistView.scss';

/**
 * SharedWishlistView Component
 * Displays a publicly shared wishlist for guests and logged-in users
 */
const SharedWishlistView = ({ shareToken }) => {
    const [wishlist, setWishlist] = useState(null);
    const [products, setProducts] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState(null);
    const [addingToCartIds, setAddingToCartIds] = useState(new Set());

    const apiUrl = window.WishCartShared?.apiUrl || '/wp-json/wishcart/v1/';
    const siteUrl = window.WishCartShared?.siteUrl || '';
    const isUserLoggedIn = window.WishCartShared?.isUserLoggedIn || false;

    useEffect(() => {
        fetchSharedWishlist();
    }, [shareToken]);

    const fetchSharedWishlist = async () => {
        if (!shareToken) {
            setError('Invalid share link');
            setIsLoading(false);
            return;
        }

        setIsLoading(true);
        setError(null);

        try {
            const response = await fetch(`${apiUrl}share/${shareToken}/view`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                },
            });

            if (response.ok) {
                const data = await response.json();
                setWishlist(data.wishlist);
                setProducts(data.products || []);
            } else {
                const errorData = await response.json();
                setError(errorData.message || 'Failed to load wishlist');
            }
        } catch (err) {
            console.error('Error fetching shared wishlist:', err);
            setError('Unable to load wishlist. Please try again later.');
        } finally {
            setIsLoading(false);
        }
    };

    const handleAddToCart = async (product) => {
        setAddingToCartIds(prev => new Set(prev).add(product.id));

        try {
            // Track the add to cart event for analytics
            const trackUrl = `${apiUrl}wishlist/track-cart`;
            const trackBody = {
                product_id: product.id,
                variation_id: product.variation_id || 0,
            };
            
            try {
                await fetch(trackUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(trackBody),
                });
            } catch (trackError) {
                // Don't block cart addition if tracking fails
                console.error('Error tracking cart event:', trackError);
            }

            // WooCommerce/FluentCart add to cart
            const formData = new FormData();
            formData.append('product_id', product.id);
            formData.append('quantity', product.quantity || 1);
            
            if (product.variation_id && product.variation_id > 0) {
                formData.append('variation_id', product.variation_id);
            }

            await fetch('/?wc-ajax=add_to_cart', {
                method: 'POST',
                body: formData,
            });

            // Show success feedback
            alert(`${product.name} added to cart!`);
        } catch (err) {
            console.error('Error adding to cart:', err);
            alert('Failed to add product to cart. Please try again.');
        } finally {
            setAddingToCartIds(prev => {
                const newSet = new Set(prev);
                newSet.delete(product.id);
                return newSet;
            });
        }
    };

    const formatPrice = (price) => {
        if (!price) return '';
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(price);
    };

    const formatDate = (dateString) => {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        });
    };

    if (isLoading) {
        return (
            <div className="shared-wishlist-view">
                <div className="shared-container">
                    <div className="loading-state">
                        <Loader size={48} className="spinner" />
                        <p>Loading wishlist...</p>
                    </div>
                </div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="shared-wishlist-view">
                <div className="shared-container">
                    <div className="error-state">
                        <AlertCircle size={48} className="error-icon" />
                        <h2>Unable to Load Wishlist</h2>
                        <p>{error}</p>
                        <Button onClick={() => window.location.href = siteUrl}>
                            Go to Home
                        </Button>
                    </div>
                </div>
            </div>
        );
    }

    if (!wishlist) {
        return (
            <div className="shared-wishlist-view">
                <div className="shared-container">
                    <div className="error-state">
                        <AlertCircle size={48} className="error-icon" />
                        <h2>Wishlist Not Found</h2>
                        <p>This wishlist may have been removed or is no longer available.</p>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="shared-wishlist-view">
            <div className="shared-container">
                {/* Header */}
                <div className="shared-header">
                    <div className="header-content">
                        <div className="wishlist-icon">
                            <Heart size={32} />
                        </div>
                        <div className="header-text">
                            <h1>{wishlist.name || 'Shared Wishlist'}</h1>
                            {wishlist.owner_name && (
                                <p className="owner-name">
                                    Shared by <strong>{wishlist.owner_name}</strong>
                                </p>
                            )}
                            {wishlist.description && (
                                <p className="wishlist-description">{wishlist.description}</p>
                            )}
                            <p className="wishlist-meta">
                                {products.length} {products.length === 1 ? 'item' : 'items'}
                                {wishlist.date_created && ` • Created ${formatDate(wishlist.date_created)}`}
                            </p>
                        </div>
                    </div>
                    {!isUserLoggedIn && (
                        <div className="login-prompt">
                            <p>
                                <a href={`${siteUrl}/wp-login.php`}>Sign in</a> to save this wishlist to your account
                            </p>
                        </div>
                    )}
                </div>

                {/* Products Grid */}
                {products.length === 0 ? (
                    <div className="empty-state">
                        <Heart size={48} className="empty-icon" />
                        <h2>No Items Yet</h2>
                        <p>This wishlist doesn't have any items at the moment.</p>
                    </div>
                ) : (
                    <div className="products-grid">
                        {products.map((product) => (
                            <Card key={product.id} className="product-card">
                                {product.image_url && (
                                    <div className="product-image">
                                        <img src={product.image_url} alt={product.name} />
                                        {product.is_on_sale && (
                                            <span className="sale-badge">Sale</span>
                                        )}
                                    </div>
                                )}

                                <CardContent className="product-content">
                                    <h3 className="product-name">
                                        <a href={product.permalink} target="_blank" rel="noopener noreferrer">
                                            {product.name}
                                        </a>
                                    </h3>

                                    {/* Variation info */}
                                    {product.variation_attributes && Object.keys(product.variation_attributes).length > 0 && (
                                        <div className="variation-info">
                                            {Object.entries(product.variation_attributes).map(([key, value]) => (
                                                <span key={key} className="variation-attribute">
                                                    {key}: {value}
                                                </span>
                                            ))}
                                        </div>
                                    )}

                                    <div className="product-price">
                                        {product.is_on_sale ? (
                                            <>
                                                <span className="sale-price">{formatPrice(product.sale_price)}</span>
                                                <span className="regular-price">{formatPrice(product.regular_price)}</span>
                                            </>
                                        ) : (
                                            <span className="price">{formatPrice(product.price)}</span>
                                        )}
                                    </div>

                                    {product.notes && (
                                        <div className="product-notes">
                                            <p>{product.notes}</p>
                                        </div>
                                    )}

                                    {product.stock_status && (
                                        <div className={`stock-status ${product.stock_status}`}>
                                            {product.stock_status === 'instock' ? 'In Stock' : 'Out of Stock'}
                                        </div>
                                    )}

                                    <div className="product-actions">
                                        <Button
                                            onClick={() => handleAddToCart(product)}
                                            disabled={
                                                addingToCartIds.has(product.id) || 
                                                product.stock_status !== 'instock'
                                            }
                                            className="add-to-cart-button"
                                        >
                                            <ShoppingCart size={16} />
                                            {addingToCartIds.has(product.id) ? 'Adding...' : 'Add to Cart'}
                                        </Button>
                                        
                                        <a
                                            href={product.permalink}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="view-details-link"
                                        >
                                            View Details
                                        </a>
                                    </div>

                                    {product.date_added && (
                                        <div className="product-meta">
                                            <span className="date-added">Added {formatDate(product.date_added)}</span>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {/* Footer */}
                <div className="shared-footer">
                    <p>
                        Want to create your own wishlist? 
                        <a href={siteUrl}> Visit our store</a>
                    </p>
                </div>
            </div>
        </div>
    );
};

export default SharedWishlistView;
