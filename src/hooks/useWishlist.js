import { useState, useEffect, useCallback } from 'react';

/**
 * Custom hook for wishlist operations
 * Integrates with WishCart 7-table backend
 */
export const useWishlist = (wishlistId = null) => {
    const [wishlists, setWishlists] = useState([]);
    const [currentWishlist, setCurrentWishlist] = useState(null);
    const [products, setProducts] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState(null);

    const apiUrl = window.WishCartWishlist?.apiUrl || '/wp-json/wishcart/v1/';
    const nonce = window.WishCartWishlist?.nonce;

    // Fetch all user's wishlists
    const fetchWishlists = useCallback(async () => {
        try {
            const response = await fetch(`${apiUrl}wishlists`, {
                headers: {
                    'X-WP-Nonce': nonce,
                },
            });

            if (response.ok) {
                const data = await response.json();
                setWishlists(data.wishlists || []);
                
                // Set current wishlist if not already set
                if (!currentWishlist && data.wishlists?.length > 0) {
                    const defaultWishlist = data.wishlists.find(w => w.is_default === '1' || w.is_default === 1);
                    setCurrentWishlist(defaultWishlist || data.wishlists[0]);
                }
                
                return data.wishlists;
            }
        } catch (err) {
            console.error('Error fetching wishlists:', err);
            setError(err.message);
        }
    }, [apiUrl, nonce, currentWishlist]);

    // Fetch wishlist products
    const fetchProducts = useCallback(async (wid = null) => {
        const targetWishlistId = wid || wishlistId || currentWishlist?.id;
        if (!targetWishlistId) return;

        setIsLoading(true);
        try {
            const response = await fetch(`${apiUrl}wishlist?wishlist_id=${targetWishlistId}`, {
                headers: {
                    'X-WP-Nonce': nonce,
                },
            });

            if (response.ok) {
                const data = await response.json();
                setProducts(data.products || []);
                if (data.wishlist) {
                    setCurrentWishlist(data.wishlist);
                }
                setError(null);
            }
        } catch (err) {
            console.error('Error fetching products:', err);
            setError(err.message);
        } finally {
            setIsLoading(false);
        }
    }, [apiUrl, nonce, wishlistId, currentWishlist]);

    // Add product to wishlist
    const addProduct = useCallback(async (productId, options = {}) => {
        try {
            const response = await fetch(`${apiUrl}wishlist/add`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({
                    product_id: productId,
                    wishlist_id: options.wishlist_id || currentWishlist?.id,
                    variation_id: options.variation_id || 0,
                    notes: options.notes || '',
                    quantity: options.quantity || 1,
                }),
            });

            if (response.ok) {
                await fetchProducts();
                return { success: true };
            } else {
                const data = await response.json();
                throw new Error(data.message || 'Failed to add product');
            }
        } catch (err) {
            console.error('Error adding product:', err);
            setError(err.message);
            return { success: false, error: err.message };
        }
    }, [apiUrl, nonce, currentWishlist, fetchProducts]);

    // Remove product from wishlist
    const removeProduct = useCallback(async (productId) => {
        try {
            const response = await fetch(`${apiUrl}wishlist/remove`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({
                    product_id: productId,
                    wishlist_id: currentWishlist?.id,
                }),
            });

            if (response.ok) {
                setProducts(prev => prev.filter(p => p.id !== productId));
                return { success: true };
            } else {
                const data = await response.json();
                throw new Error(data.message || 'Failed to remove product');
            }
        } catch (err) {
            console.error('Error removing product:', err);
            setError(err.message);
            return { success: false, error: err.message };
        }
    }, [apiUrl, nonce, currentWishlist]);

    // Create new wishlist
    const createWishlist = useCallback(async (name, isDefault = false) => {
        try {
            const response = await fetch(`${apiUrl}wishlists`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({
                    name,
                    is_default: isDefault,
                }),
            });

            if (response.ok) {
                const data = await response.json();
                await fetchWishlists();
                return { success: true, wishlist: data.wishlist };
            } else {
                const data = await response.json();
                throw new Error(data.message || 'Failed to create wishlist');
            }
        } catch (err) {
            console.error('Error creating wishlist:', err);
            setError(err.message);
            return { success: false, error: err.message };
        }
    }, [apiUrl, nonce, fetchWishlists]);

    // Update wishlist
    const updateWishlist = useCallback(async (wid, updates) => {
        try {
            const response = await fetch(`${apiUrl}wishlists/${wid}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify(updates),
            });

            if (response.ok) {
                const data = await response.json();
                await fetchWishlists();
                if (currentWishlist?.id === wid) {
                    setCurrentWishlist(data.wishlist);
                }
                return { success: true, wishlist: data.wishlist };
            } else {
                const data = await response.json();
                throw new Error(data.message || 'Failed to update wishlist');
            }
        } catch (err) {
            console.error('Error updating wishlist:', err);
            setError(err.message);
            return { success: false, error: err.message };
        }
    }, [apiUrl, nonce, fetchWishlists, currentWishlist]);

    // Delete wishlist
    const deleteWishlist = useCallback(async (wid) => {
        try {
            const response = await fetch(`${apiUrl}wishlists/${wid}`, {
                method: 'DELETE',
                headers: {
                    'X-WP-Nonce': nonce,
                },
            });

            if (response.ok) {
                await fetchWishlists();
                if (currentWishlist?.id === wid) {
                    setCurrentWishlist(null);
                }
                return { success: true };
            } else {
                const data = await response.json();
                throw new Error(data.message || 'Failed to delete wishlist');
            }
        } catch (err) {
            console.error('Error deleting wishlist:', err);
            setError(err.message);
            return { success: false, error: err.message };
        }
    }, [apiUrl, nonce, fetchWishlists, currentWishlist]);

    // Fetch shared wishlist (public, no authentication required)
    const fetchSharedWishlist = useCallback(async (shareToken) => {
        if (!shareToken) {
            setError('Share token is required');
            return { success: false, error: 'Share token is required' };
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
                setCurrentWishlist(data.wishlist || null);
                setProducts(data.products || []);
                return { success: true, wishlist: data.wishlist, products: data.products };
            } else {
                const data = await response.json();
                const errorMessage = data.message || 'Failed to load shared wishlist';
                setError(errorMessage);
                return { success: false, error: errorMessage };
            }
        } catch (err) {
            console.error('Error fetching shared wishlist:', err);
            setError(err.message);
            return { success: false, error: err.message };
        } finally {
            setIsLoading(false);
        }
    }, [apiUrl]);

    // Initial load
    useEffect(() => {
        fetchWishlists();
    }, [fetchWishlists]);

    useEffect(() => {
        if (currentWishlist) {
            fetchProducts();
        }
    }, [currentWishlist, fetchProducts]);

    return {
        wishlists,
        currentWishlist,
        setCurrentWishlist,
        products,
        isLoading,
        error,
        addProduct,
        removeProduct,
        createWishlist,
        updateWishlist,
        deleteWishlist,
        refreshWishlists: fetchWishlists,
        refreshProducts: fetchProducts,
        fetchSharedWishlist,
    };
};

