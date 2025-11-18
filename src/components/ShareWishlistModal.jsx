import React, { useState, useEffect } from 'react';
import { X, Copy, Check, Facebook, Twitter, Mail, MessageCircle, Link as LinkIcon } from 'lucide-react';
import { Button } from './ui/button';
import { useSharing } from '../hooks/useSharing';
import '../styles/ShareModal.scss';

export const ShareWishlistModal = ({ wishlist, isOpen, onClose, onPrivacyChange }) => {
    const { createShare, getShareUrl, copyToClipboard, isSharing } = useSharing();
    const [shareLink, setShareLink] = useState('');
    const [copied, setCopied] = useState(false);
    const [emailRecipient, setEmailRecipient] = useState('');
    const [emailMessage, setEmailMessage] = useState('');
    const [sendingEmail, setSendingEmail] = useState(false);
    const [error, setError] = useState('');
    const [isUpdatingPrivacy, setIsUpdatingPrivacy] = useState(false);

    useEffect(() => {
        if (isOpen && wishlist) {
            // Reset states
            setError('');
            setShareLink('');
            
            // Check privacy status
            if (wishlist.privacy_status === 'private') {
                setError('This wishlist is currently private. Please change privacy to "Shared" or "Public" to generate a share link.');
            } else {
                // Generate share link
                generateShareLink();
            }
        }
    }, [isOpen, wishlist]);

    const generateShareLink = async () => {
        if (!wishlist) return;
        
        setError('');

        const result = await createShare(wishlist.id, 'link');
        if (result.success && result.data.share_url) {
            setShareLink(result.data.share_url);
        } else {
            setError(result.error || 'Failed to create share link. Please try again.');
        }
    };
    
    const handleMakeShareable = async () => {
        if (!wishlist || !onPrivacyChange) return;
        
        setIsUpdatingPrivacy(true);
        try {
            // Update wishlist privacy to 'shared'
            await onPrivacyChange('shared');
            
            // Wait a moment for the update to complete
            setTimeout(() => {
                setError('');
                generateShareLink();
                setIsUpdatingPrivacy(false);
            }, 500);
        } catch (err) {
            console.error('Error updating privacy:', err);
            setError('Failed to update privacy settings. Please try again.');
            setIsUpdatingPrivacy(false);
        }
    };

    const handleCopyLink = async () => {
        const result = await copyToClipboard(shareLink);
        if (result.success) {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    };

    const handleSocialShare = async (platform) => {
        if (!shareLink) return;

        const url = getShareUrl(shareLink, platform);
        window.open(url, '_blank', 'width=600,height=400');
        
        // Track the share
        await createShare(wishlist.id, platform);
    };

    const handleEmailShare = async (e) => {
        e.preventDefault();
        if (!emailRecipient) return;

        setSendingEmail(true);
        try {
            const result = await createShare(wishlist.id, 'email', {
                shared_with_email: emailRecipient,
                share_message: emailMessage,
            });

            if (result.success) {
                alert('Share link sent via email!');
                setEmailRecipient('');
                setEmailMessage('');
            }
        } catch (err) {
            console.error('Error sending email:', err);
            alert('Failed to send email. Please try again.');
        } finally {
            setSendingEmail(false);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="share-modal-overlay" onClick={onClose}>
            <div className="share-modal-content" onClick={(e) => e.stopPropagation()}>
                <div className="share-modal-header">
                    <h2>Share Wishlist</h2>
                    <button className="close-button" onClick={onClose}>
                        <X size={20} />
                    </button>
                </div>

                <div className="share-modal-body">
                    {/* Error Message */}
                    {error && (
                        <div className="error-message">
                            <p>{error}</p>
                            {wishlist?.privacy_status === 'private' && onPrivacyChange && (
                                <Button
                                    onClick={handleMakeShareable}
                                    disabled={isUpdatingPrivacy}
                                    className="make-shareable-button"
                                >
                                    {isUpdatingPrivacy ? 'Updating...' : 'Make Shareable'}
                                </Button>
                            )}
                        </div>
                    )}

                    {/* Share Link */}
                    {!error && (
                        <div className="share-section">
                            <label>Share Link</label>
                            <div className="share-link-container">
                                <input
                                    type="text"
                                    value={shareLink}
                                    readOnly
                                    className="share-link-input"
                                    placeholder="Generating share link..."
                                />
                                <Button
                                    onClick={handleCopyLink}
                                    className="copy-button"
                                    disabled={!shareLink}
                                >
                                    {copied ? (
                                        <>
                                            <Check size={16} />
                                            Copied!
                                        </>
                                    ) : (
                                        <>
                                            <Copy size={16} />
                                            Copy
                                        </>
                                    )}
                                </Button>
                            </div>
                        </div>
                    )}

                    {/* Social Media Share Buttons */}
                    <div className="share-section">
                        <label>Share on Social Media</label>
                        <div className="social-buttons">
                            <button
                                className="social-button facebook"
                                onClick={() => handleSocialShare('facebook')}
                                disabled={!shareLink}
                            >
                                <Facebook size={20} />
                                <span>Facebook</span>
                            </button>
                            <button
                                className="social-button twitter"
                                onClick={() => handleSocialShare('twitter')}
                                disabled={!shareLink}
                            >
                                <Twitter size={20} />
                                <span>Twitter</span>
                            </button>
                            <button
                                className="social-button whatsapp"
                                onClick={() => handleSocialShare('whatsapp')}
                                disabled={!shareLink}
                            >
                                <MessageCircle size={20} />
                                <span>WhatsApp</span>
                            </button>
                            <button
                                className="social-button email-social"
                                onClick={() => handleSocialShare('email')}
                                disabled={!shareLink}
                            >
                                <Mail size={20} />
                                <span>Email</span>
                            </button>
                        </div>
                    </div>

                    {/* Email Share Form */}
                    <div className="share-section">
                        <label>Send via Email</label>
                        <form onSubmit={handleEmailShare} className="email-form">
                            <input
                                type="email"
                                placeholder="Recipient's email"
                                value={emailRecipient}
                                onChange={(e) => setEmailRecipient(e.target.value)}
                                className="email-input"
                                required
                            />
                            <textarea
                                placeholder="Add a personal message (optional)"
                                value={emailMessage}
                                onChange={(e) => setEmailMessage(e.target.value)}
                                className="email-message"
                                rows="3"
                            />
                            <Button
                                type="submit"
                                disabled={!emailRecipient || sendingEmail}
                                className="send-email-button"
                            >
                                {sendingEmail ? 'Sending...' : 'Send Email'}
                            </Button>
                        </form>
                    </div>

                    {/* Privacy Notice */}
                    <div className="share-notice">
                        <LinkIcon size={16} />
                        <p>Anyone with this link will be able to view your wishlist.</p>
                    </div>
                </div>
            </div>
        </div>
    );
};

