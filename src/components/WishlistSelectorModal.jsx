import React, { useState, useEffect } from 'react';
import { X, Plus, Check } from 'lucide-react';
import { Button } from './ui/button';
import '../styles/WishlistSelectorModal.scss';

const WishlistSelectorModal = ({ isOpen, onClose, productId, onSuccess }) => {
    const [wishlists, setWishlists] = useState([]);
    const [selectedWishlistId, setSelectedWishlistId] = useState(null);
    const [isLoading, setIsLoading] = useState(false);
    const [isCreatingNew, setIsCreatingNew] = useState(false);
    const [newWishlistName, setNewWishlistName] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState(null);

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

    // Load wishlists when modal opens
    useEffect(() => {
        if (isOpen) {
            loadWishlists();
            setIsCreatingNew(false);
            setNewWishlistName('');
            setError(null);
        }
    }, [isOpen]);

    const loadWishlists = async () => {
        setIsLoading(true);
        setError(null);
        
        try {
            const sessionId = getSessionId();
            const url = `${window.WishCartWishlist.apiUrl}wishlists${sessionId ? `?session_id=${sessionId}` : ''}`;
            
            const response = await fetch(url, {
                headers: {
                    'X-WP-Nonce': window.WishCartWishlist.nonce,
                },
            });

            if (response.ok) {
                const data = await response.json();
                const wishlistsData = data.wishlists || [];
                setWishlists(wishlistsData);
                
                // Auto-select default wishlist
                const defaultWishlist = wishlistsData.find(w => w.is_default === 1 || w.is_default === '1');
                if (defaultWishlist) {
                    setSelectedWishlistId(defaultWishlist.id);
                } else if (wishlistsData.length > 0) {
                    setSelectedWishlistId(wishlistsData[0].id);
                }
            } else {
                setError('Failed to load wishlists');
            }
        } catch (err) {
            console.error('Error loading wishlists:', err);
            setError('Failed to load wishlists');
        } finally {
            setIsLoading(false);
        }
    };

    const handleCreateNewWishlist = async () => {
        if (!newWishlistName.trim()) {
            setError('Please enter a wishlist name');
            return;
        }

        setIsSubmitting(true);
        setError(null);

        try {
            const sessionId = getSessionId();
            const url = `${window.WishCartWishlist.apiUrl}wishlists`;
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.WishCartWishlist.nonce,
                },
                body: JSON.stringify({
                    name: newWishlistName.trim(),
                    is_default: false,
                    session_id: sessionId,
                }),
            });

            if (response.ok) {
                const data = await response.json();
                if (data.wishlist) {
                    // Add product to the new wishlist
                    await addToWishlist(data.wishlist.id);
                }
            } else {
                const errorData = await response.json();
                setError(errorData.message || 'Failed to create wishlist');
                setIsSubmitting(false);
            }
        } catch (err) {
            console.error('Error creating wishlist:', err);
            setError('Failed to create wishlist');
            setIsSubmitting(false);
        }
    };

    const addToWishlist = async (wishlistId) => {
        setIsSubmitting(true);
        setError(null);

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
                    wishlist_id: wishlistId,
                    session_id: sessionId,
                }),
            });

            if (response.ok) {
                const data = await response.json();
                if (onSuccess) {
                    onSuccess(data);
                }
                onClose();
            } else {
                const errorData = await response.json();
                setError(errorData.message || 'Failed to add product to wishlist');
            }
        } catch (err) {
            console.error('Error adding to wishlist:', err);
            setError('Failed to add product to wishlist');
        } finally {
            setIsSubmitting(false);
        }
    };

    const handleSubmit = () => {
        if (isCreatingNew) {
            handleCreateNewWishlist();
        } else if (selectedWishlistId) {
            addToWishlist(selectedWishlistId);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="wishcart-modal-overlay" onClick={onClose}>
            <div className="wishcart-modal" onClick={(e) => e.stopPropagation()}>
                <div className="wishcart-modal-header">
                    <h2>Add to Wishlist</h2>
                    <button 
                        className="wishcart-modal-close" 
                        onClick={onClose}
                        aria-label="Close"
                    >
                        <X size={20} />
                    </button>
                </div>

                <div className="wishcart-modal-body">
                    {error && (
                        <div className="wishcart-modal-error">
                            {error}
                        </div>
                    )}

                    {isLoading ? (
                        <div className="wishcart-modal-loading">
                            Loading wishlists...
                        </div>
                    ) : (
                        <>
                            {!isCreatingNew ? (
                                <>
                                    <div className="wishcart-wishlists-list">
                                        {wishlists.map((wishlist) => (
                                            <div
                                                key={wishlist.id}
                                                className={`wishcart-wishlist-item ${
                                                    selectedWishlistId === wishlist.id ? 'selected' : ''
                                                }`}
                                                onClick={() => setSelectedWishlistId(wishlist.id)}
                                            >
                                                <div className="wishcart-wishlist-radio">
                                                    {selectedWishlistId === wishlist.id && (
                                                        <Check size={16} />
                                                    )}
                                                </div>
                                                <div className="wishcart-wishlist-info">
                                                    <div className="wishcart-wishlist-name">
                                                        {wishlist.wishlist_name}
                                                        {(wishlist.is_default === 1 || wishlist.is_default === '1') && (
                                                            <span className="wishcart-default-badge">Default</span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>

                                    <button
                                        className="wishcart-create-new-button"
                                        onClick={() => setIsCreatingNew(true)}
                                    >
                                        <Plus size={16} />
                                        Create New Wishlist
                                    </button>
                                </>
                            ) : (
                                <div className="wishcart-create-new-form">
                                    <label htmlFor="new-wishlist-name">Wishlist Name</label>
                                    <input
                                        id="new-wishlist-name"
                                        type="text"
                                        value={newWishlistName}
                                        onChange={(e) => setNewWishlistName(e.target.value)}
                                        placeholder="e.g., My Birthday Wishlist"
                                        autoFocus
                                        onKeyPress={(e) => {
                                            if (e.key === 'Enter') {
                                                handleSubmit();
                                            }
                                        }}
                                    />
                                    <button
                                        className="wishcart-back-button"
                                        onClick={() => {
                                            setIsCreatingNew(false);
                                            setNewWishlistName('');
                                            setError(null);
                                        }}
                                    >
                                        Back to Wishlists
                                    </button>
                                </div>
                            )}
                        </>
                    )}
                </div>

                <div className="wishcart-modal-footer">
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={isSubmitting}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={handleSubmit}
                        disabled={isSubmitting || (!selectedWishlistId && !isCreatingNew) || (isCreatingNew && !newWishlistName.trim())}
                    >
                        {isSubmitting ? 'Adding...' : isCreatingNew ? 'Create & Add' : 'Add to Wishlist'}
                    </Button>
                </div>
            </div>
        </div>
    );
};

export default WishlistSelectorModal;

