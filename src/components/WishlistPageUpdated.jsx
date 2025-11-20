import React, { useState, useEffect } from 'react';
import { Heart, Trash2, ShoppingCart, Share2, Lock, Globe, Users, Edit2, Save, X } from 'lucide-react';
import { Card, CardContent } from './ui/card';
import { Button } from './ui/button';
import { Checkbox } from './ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import { ShareWishlistModal } from './ShareWishlistModal';
import { WishlistSelector } from './WishlistSelector';
import { useWishlist } from '../hooks/useWishlist';
import { cn } from '../lib/utils';
import '../styles/WishlistPage.scss';

const WishlistPage = () => {
    const {
        wishlists,
        currentWishlist,
        setCurrentWishlist,
        products,
        isLoading,
        error,
        removeProduct,
        createWishlist,
        updateWishlist,
        refreshProducts,
    } = useWishlist();

    const [selectedIds, setSelectedIds] = useState(new Set());
    const [removingIds, setRemovingIds] = useState(new Set());
    const [addingToCartIds, setAddingToCartIds] = useState(new Set());
    const [bulkAction, setBulkAction] = useState('');
    const [isShareModalOpen, setIsShareModalOpen] = useState(false);
    const [isEditingName, setIsEditingName] = useState(false);
    const [editedName, setEditedName] = useState('');
    const [privacyStatus, setPrivacyStatus] = useState('private');

    // Update privacy status when wishlist changes
    useEffect(() => {
        if (currentWishlist) {
            setPrivacyStatus(currentWishlist.privacy_status || 'private');
            setEditedName(currentWishlist.wishlist_name || currentWishlist.name || '');
        }
    }, [currentWishlist]);

    const handleSelectAll = (checked) => {
        if (checked) {
            setSelectedIds(new Set(products.map(p => p.id)));
        } else {
            setSelectedIds(new Set());
        }
    };

    const handleSelectProduct = (productId, checked) => {
        const newSelected = new Set(selectedIds);
        if (checked) {
            newSelected.add(productId);
        } else {
            newSelected.delete(productId);
        }
        setSelectedIds(newSelected);
    };

    const handleRemoveProduct = async (productId) => {
        setRemovingIds(prev => new Set(prev).add(productId));
        
        try {
            const result = await removeProduct(productId);
            if (result.success) {
                setSelectedIds(prev => {
                    const newSet = new Set(prev);
                    newSet.delete(productId);
                    return newSet;
                });
            }
        } finally {
            setRemovingIds(prev => {
                const newSet = new Set(prev);
                newSet.delete(productId);
                return newSet;
            });
        }
    };

    const handleBulkAction = async () => {
        if (!bulkAction || selectedIds.size === 0) return;

        if (bulkAction === 'delete') {
            const idsToRemove = Array.from(selectedIds);
            for (const id of idsToRemove) {
                await handleRemoveProduct(id);
            }
            setSelectedIds(new Set());
            setBulkAction('');
        }
    };

    const handlePrivacyChange = async (newPrivacy) => {
        if (!currentWishlist) return;

        const result = await updateWishlist(currentWishlist.id, {
            privacy_status: newPrivacy,
        });

        if (result.success) {
            setPrivacyStatus(newPrivacy);
        }
    };

    const handleSaveName = async () => {
        if (!currentWishlist || !editedName.trim()) return;

        const result = await updateWishlist(currentWishlist.id, {
            wishlist_name: editedName.trim(),
        });

        if (result.success) {
            setIsEditingName(false);
        }
    };

    const handleAddToCart = async (product) => {
        setAddingToCartIds(prev => new Set(prev).add(product.id));

        try {
            // Add WooCommerce/FluentCart add to cart logic here
            const formData = new FormData();
            formData.append('product_id', product.id);
            formData.append('quantity', 1);

            // This will depend on your cart implementation
            // Example for WooCommerce:
            await fetch('/?wc-ajax=add_to_cart', {
                method: 'POST',
                body: formData,
            });

            // Optionally remove from wishlist after adding to cart
            // await handleRemoveProduct(product.id);
        } catch (err) {
            console.error('Error adding to cart:', err);
        } finally {
            setAddingToCartIds(prev => {
                const newSet = new Set(prev);
                newSet.delete(product.id);
                return newSet;
            });
        }
    };

    const getPrivacyIcon = () => {
        switch (privacyStatus) {
            case 'public':
                return <Globe size={16} />;
            case 'shared':
                return <Users size={16} />;
            default:
                return <Lock size={16} />;
        }
    };

    const getPrivacyLabel = () => {
        switch (privacyStatus) {
            case 'public':
                return 'Public';
            case 'shared':
                return 'Shared';
            default:
                return 'Private';
        }
    };

    if (isLoading) {
        return (
            <div className="wishlist-page">
                <div className="wishlist-container">
                    <div className="loading-state">
                        <div className="spinner"></div>
                        <p>Loading wishlist...</p>
                    </div>
                </div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="wishlist-page">
                <div className="wishlist-container">
                    <div className="error-state">
                        <p>{error}</p>
                        <Button onClick={refreshProducts}>Retry</Button>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="wishlist-page">
            <div className="wishlist-container">
                {/* Header */}
                <div className="wishlist-header">
                    <div className="wishlist-title-section">
                        {isEditingName ? (
                            <div className="wishlist-name-edit">
                                <input
                                    type="text"
                                    value={editedName}
                                    onChange={(e) => setEditedName(e.target.value)}
                                    className="wishlist-name-input"
                                    autoFocus
                                />
                                <button onClick={handleSaveName} className="save-button">
                                    <Save size={16} />
                                </button>
                                <button onClick={() => setIsEditingName(false)} className="cancel-button">
                                    <X size={16} />
                                </button>
                            </div>
                        ) : (
                            <div className="wishlist-name-display">
                                <h1>{currentWishlist?.wishlist_name || currentWishlist?.name || 'My Wishlist'}</h1>
                                <button onClick={() => setIsEditingName(true)} className="edit-name-button">
                                    <Edit2 size={16} />
                                </button>
                            </div>
                        )}
                        <p className="wishlist-count">{products.length} {products.length === 1 ? 'item' : 'items'}</p>
                    </div>

                    <div className="wishlist-actions">
                        {/* Wishlist Selector - Only show if multiple wishlists are enabled */}
                        {window.WishCartWishlist?.enableMultipleWishlists && (
                            <WishlistSelector
                                wishlists={wishlists}
                                currentWishlist={currentWishlist}
                                onSelect={setCurrentWishlist}
                                onCreate={createWishlist}
                            />
                        )}

                        {/* Privacy Control */}
                        <div className="privacy-control">
                            <select
                                value={privacyStatus}
                                onChange={(e) => handlePrivacyChange(e.target.value)}
                                className="privacy-select"
                            >
                                <option value="private">
                                    🔒 Private
                                </option>
                                <option value="shared">
                                    👥 Shared
                                </option>
                                <option value="public">
                                    🌐 Public
                                </option>
                            </select>
                        </div>

                        {/* Share Button */}
                        <Button
                            onClick={() => setIsShareModalOpen(true)}
                            className="share-button"
                            variant="outline"
                        >
                            <Share2 size={16} />
                            Share
                        </Button>
                    </div>
                </div>

                {/* Bulk Actions */}
                {products.length > 0 && (
                    <div className="bulk-actions-bar">
                        <div className="bulk-select">
                            <Checkbox
                                checked={selectedIds.size === products.length && products.length > 0}
                                onCheckedChange={handleSelectAll}
                            />
                            <span>Select All</span>
                        </div>

                        {selectedIds.size > 0 && (
                            <div className="bulk-actions-controls">
                                <span className="selected-count">{selectedIds.size} selected</span>
                                <Select value={bulkAction} onValueChange={setBulkAction}>
                                    <SelectTrigger className="bulk-action-select">
                                        <SelectValue placeholder="Bulk Actions" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="delete">Delete Selected</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Button onClick={handleBulkAction} size="sm" disabled={!bulkAction}>
                                    Apply
                                </Button>
                            </div>
                        )}
                    </div>
                )}

                {/* Products Grid */}
                {products.length === 0 ? (
                    <div className="empty-state">
                        <Heart size={48} className="empty-icon" />
                        <h2>Your wishlist is empty</h2>
                        <p>Start adding products you love to your wishlist!</p>
                    </div>
                ) : (
                    <div className="products-grid">
                        {products.map((product) => (
                            <Card key={product.id} className="product-card">
                                <div className="product-checkbox">
                                    <Checkbox
                                        checked={selectedIds.has(product.id)}
                                        onCheckedChange={(checked) => handleSelectProduct(product.id, checked)}
                                    />
                                </div>

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
                                        <a href={product.permalink}>{product.name}</a>
                                    </h3>

                                    <div className="product-price">
                                        {product.is_on_sale ? (
                                            <>
                                                <span className="sale-price">${product.sale_price}</span>
                                                <span className="regular-price">${product.regular_price}</span>
                                            </>
                                        ) : (
                                            <span className="price">${product.price}</span>
                                        )}
                                    </div>

                                    {product.date_added && (
                                        <div className="product-meta">
                                            <span className="date-added">Added: {product.date_added}</span>
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
                                            disabled={addingToCartIds.has(product.id) || product.stock_status !== 'instock'}
                                            className="add-to-cart-button"
                                        >
                                            <ShoppingCart size={16} />
                                            {addingToCartIds.has(product.id) ? 'Adding...' : 'Add to Cart'}
                                        </Button>

                                        <button
                                            onClick={() => handleRemoveProduct(product.id)}
                                            disabled={removingIds.has(product.id)}
                                            className="remove-button"
                                            title="Remove from wishlist"
                                        >
                                            <Trash2 size={16} />
                                        </button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            {/* Share Modal */}
            <ShareWishlistModal
                wishlist={currentWishlist}
                isOpen={isShareModalOpen}
                onClose={() => setIsShareModalOpen(false)}
                onPrivacyChange={handlePrivacyChange}
            />
        </div>
    );
};

export default WishlistPage;

