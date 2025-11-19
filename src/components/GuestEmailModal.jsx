import React, { useState } from 'react';
import { X, Mail } from 'lucide-react';
import { Button } from './ui/button';
import '../styles/WishlistSelectorModal.scss';

const GuestEmailModal = ({ isOpen, onClose, onEmailSubmitted }) => {
    const [email, setEmail] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState(null);

    // Validate email format
    const validateEmail = (email) => {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError(null);

        // Validate email
        if (!email.trim()) {
            setError('Please enter your email address');
            return;
        }

        if (!validateEmail(email.trim())) {
            setError('Please enter a valid email address');
            return;
        }

        setIsSubmitting(true);

        try {
            // Get session ID from cookie
            const getSessionId = () => {
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

            const sessionId = getSessionId();
            
            // Save email to guest user record
            const url = `${window.WishCartWishlist.apiUrl}guest/update-email`;
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.WishCartWishlist.nonce,
                },
                body: JSON.stringify({
                    email: email.trim(),
                    session_id: sessionId,
                }),
            });

            if (response.ok) {
                // Store email in localStorage to avoid asking again in this session
                localStorage.setItem('wishcart_guest_email', email.trim());
                
                // Call success callback
                if (onEmailSubmitted) {
                    onEmailSubmitted(email.trim());
                }
                onClose();
            } else {
                const errorData = await response.json();
                setError(errorData.message || 'Failed to save email address');
            }
        } catch (err) {
            console.error('Error saving email:', err);
            setError('Failed to save email address. Please try again.');
        } finally {
            setIsSubmitting(false);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="wishcart-modal-overlay" onClick={onClose}>
            <div className="wishcart-modal" onClick={(e) => e.stopPropagation()}>
                <div className="wishcart-modal-header">
                    <h2>Get Notified About Your Wishlist</h2>
                    <button 
                        className="wishcart-modal-close" 
                        onClick={onClose}
                        aria-label="Close"
                    >
                        <X size={20} />
                    </button>
                </div>

                <div className="wishcart-modal-body">
                    <div style={{ marginBottom: '1rem', color: '#6b7280', fontSize: '0.875rem' }}>
                        Enter your email to receive notifications about price drops, back-in-stock alerts, and more for items in your wishlist.
                    </div>

                    {error && (
                        <div className="wishcart-modal-error">
                            {error}
                        </div>
                    )}

                    <form onSubmit={handleSubmit}>
                        <div className="wishcart-create-new-form">
                            <label htmlFor="guest-email">
                                <Mail size={16} style={{ display: 'inline-block', marginRight: '0.5rem', verticalAlign: 'middle' }} />
                                Email Address
                            </label>
                            <input
                                id="guest-email"
                                type="email"
                                value={email}
                                onChange={(e) => {
                                    setEmail(e.target.value);
                                    setError(null);
                                }}
                                placeholder="your.email@example.com"
                                autoFocus
                                required
                                disabled={isSubmitting}
                                style={{ marginBottom: '0' }}
                            />
                        </div>
                    </form>
                </div>

                <div className="wishcart-modal-footer">
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={isSubmitting}
                        type="button"
                    >
                        Skip
                    </Button>
                    <Button
                        onClick={handleSubmit}
                        disabled={isSubmitting || !email.trim()}
                        type="button"
                    >
                        {isSubmitting ? 'Saving...' : 'Continue'}
                    </Button>
                </div>
            </div>
        </div>
    );
};

export default GuestEmailModal;

