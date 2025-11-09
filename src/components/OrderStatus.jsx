import React, { useState } from 'react';
import '../styles/OrderStatus.scss';
import {
    Truck,
    FileText,
    HelpCircle,
    MessageSquare,
    Calendar,
    Download
} from 'lucide-react';

const OrderStatus = ({ order, onAction, conversationId }) => {
    if (!order || typeof order !== 'object') {
        return null;
    }

    const [noteText, setNoteText] = useState('');
    const [noteError, setNoteError] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [showNotesForm, setShowNotesForm] = useState(false);
    const [successMessage, setSuccessMessage] = useState('');

    const {
        order_number = '',
        status = 'unknown',
        date_created,
        total,
        shipping_method,
        shipping_address,
        tracking_number,
        items = []
    } = order;

    const formatDate = (dateString) => {
        if (!dateString) return '';
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    };

    const getStatusEmoji = (status) => {
        const emojis = {
            'pending': '⏳',
            'processing': '🏭',
            'on-hold': '⏸️',
            'completed': '✅',
            'cancelled': '❌',
            'refunded': '💰',
            'failed': '⚠️',
            'shipping': '🚚'
        };
        return emojis[status?.toLowerCase()] || '📦';
    };

    const getAvailableActions = () => {
        const actions = [];

        actions.push({
            id: 'download_invoice',
            label: 'Download Invoice',
            icon: Download,
            description: 'Get a copy of your order invoice'
        });

        //if (tracking_number) {
        actions.push({
            id: 'track_order',
            label: 'Track Order',
            icon: Truck,
            description: 'View shipping status and location'
        });
        //}

        return actions;
    };

    const handleAction = (actionId) => {
        if (onAction) {
            onAction({
                type: actionId,
                order_number: order_number
            });
        }
    };

    const handleNoteSubmit = async () => {
        if (!noteText.trim()) {
            setNoteError('Please enter your question or issue');
            setSuccessMessage('');
            return;
        }

        setIsSubmitting(true);
        try {
            const response = await fetch(`${AiskData.apiUrl}/submit-inquiry`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce
                },
                body: JSON.stringify({
                    order_number: order_number,
                    conversation_id: conversationId,
                    note: noteText
                })
            });

            if (response.ok) {
                setNoteText('');
                setNoteError('');
                setSuccessMessage('✅ Inquiry submitted successfully!');
                setTimeout(() => {
                    setShowNotesForm(false);
                    setSuccessMessage(''); // Clear success message
                }, 3000);
                onAction({ type: 'inquiry_submitted' });
            } else {
                throw new Error('Failed to submit inquiry');
            }
        } catch (error) {
            setNoteError('Failed to submit inquiry. Please try again.');
            setSuccessMessage('');
        } finally {
            setIsSubmitting(false);
        }
    };

    const actions = getAvailableActions();

    return (
        <div className="order-status-card">
            {/* Order Header */}
            <div className="order-header">
                <h3>Order #{order_number}</h3>
                <span className={`status-badge ${status.toLowerCase()}`}>
                    {getStatusEmoji(status)} {status.toString().toUpperCase()}
                </span>
            </div>

            {/* Action Buttons */}
            {/* <div className="order-actions">
                <div className="action-buttons">
                    {actions.map((action) => (
                        <button
                            key={action.id}
                            onClick={() => handleAction(action.id)}
                            className="action-button default"
                            title={action.description}
                        >
                            <action.icon className="w-4 h-4" />
                            <span>{action.label}</span>
                        </button>
                    ))}
                </div>
            </div> */}

            {/* Order Details */}
            <div className="order-details">
                <div className="detail-row">
                    <span className="label">📅 Date:</span>
                    <span className="value">{formatDate(date_created)}</span>
                </div>
                <div className="detail-row">
                    <span className="label">💰 Total:</span>
                    <span className="value">{total}</span>
                </div>
                {shipping_method && (
                    <div className="detail-row">
                        <span className="label">🚚 Shipping:</span>
                        <span className="value">{shipping_method}</span>
                    </div>
                )}
                {shipping_address && (
                    <div className="detail-row">
                        <span className="label">📍 Address:</span>
                        <span className="value">{shipping_address}</span>
                    </div>
                )}
                {tracking_number && (
                    <div className="detail-row">
                        <span className="label">📦 Tracking:</span>
                        <span className="value">{tracking_number}</span>
                    </div>
                )}
            </div>

            {/* Order Items */}
            {Array.isArray(items) && items.length > 0 && (
                <div className="order-items">
                    <h4>🛍️ Order Items</h4>
                    <div className="order-items-grid">
                        {items.map((item, index) => (
                            <div key={index} className="item-card">
                                {item.image && (
                                    <div className="item-image">
                                        <img
                                            src={item.image}
                                            alt={item.name}
                                            className="product-thumbnail"
                                        />
                                    </div>
                                )}
                                <div className="item-details">
                                    <span className="item-name">{item.name || 'Unknown Item'}</span>
                                    <div className="item-meta">
                                        <span className="item-qty">x{item.quantity || 1}</span>
                                        <span className="item-total">{item.total || ''}</span>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Support Section */}
            <div className="order-support-section">
                {!showNotesForm ? (
                    <div className="text-center">
                        <button
                            onClick={() => setShowNotesForm(true)}
                            className="support-button"
                        >
                            <MessageSquare className="w-4 h-4" />
                            Need help with this order?
                        </button>
                    </div>
                ) : (
                    <div className="notes-form">
                        <div className="notes-header">
                            <h4>How can we help you?</h4>
                            <button
                                onClick={() => {
                                    setShowNotesForm(false);
                                    setNoteText('');
                                    setNoteError('');
                                }}
                                className="close-button"
                            >
                                ×
                            </button>
                        </div>
                        <textarea
                            placeholder="Describe your issue or question..."
                            value={noteText}
                            onChange={(e) => setNoteText(e.target.value)}
                            className="notes-input"
                            rows={3}
                        />
                        {noteError && <p className="error-text">{noteError}</p>}
                        {successMessage && <p className="success-text">{successMessage}</p>}
                        <button
                            onClick={handleNoteSubmit}
                            className="support-button"
                            disabled={isSubmitting}
                        >
                            {isSubmitting ? (
                                <span>Submitting...</span>
                            ) : (
                                <>
                                    <MessageSquare className="w-4 h-4" />
                                    Submit Inquiry
                                </>
                            )}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
};

export default OrderStatus;