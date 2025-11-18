import React, { useState } from 'react';
import { ChevronDown, Plus, Check } from 'lucide-react';
import { Button } from './ui/button';

export const WishlistSelector = ({ wishlists, currentWishlist, onSelect, onCreate }) => {
    const [isOpen, setIsOpen] = useState(false);
    const [isCreating, setIsCreating] = useState(false);
    const [newName, setNewName] = useState('');

    const handleSelect = (wishlist) => {
        onSelect(wishlist);
        setIsOpen(false);
    };

    const handleCreate = async (e) => {
        e.preventDefault();
        if (!newName.trim()) return;

        setIsCreating(true);
        const result = await onCreate(newName.trim());
        if (result.success) {
            setNewName('');
            setIsOpen(false);
        }
        setIsCreating(false);
    };

    return (
        <div className="wishlist-selector">
            <button
                className="wishlist-selector-trigger"
                onClick={() => setIsOpen(!isOpen)}
            >
                <span className="wishlist-name">
                    {currentWishlist?.wishlist_name || currentWishlist?.name || 'Select Wishlist'}
                </span>
                <ChevronDown size={16} className={`chevron ${isOpen ? 'open' : ''}`} />
            </button>

            {isOpen && (
                <>
                    <div className="wishlist-selector-overlay" onClick={() => setIsOpen(false)} />
                    <div className="wishlist-selector-dropdown">
                        <div className="wishlist-list">
                            {wishlists.map((wishlist) => (
                                <button
                                    key={wishlist.id}
                                    className={`wishlist-item ${currentWishlist?.id === wishlist.id ? 'active' : ''}`}
                                    onClick={() => handleSelect(wishlist)}
                                >
                                    <span className="wishlist-item-name">
                                        {wishlist.wishlist_name || wishlist.name}
                                    </span>
                                    {wishlist.is_default === '1' || wishlist.is_default === 1 ? (
                                        <span className="default-badge">Default</span>
                                    ) : null}
                                    {currentWishlist?.id === wishlist.id && (
                                        <Check size={16} className="check-icon" />
                                    )}
                                </button>
                            ))}
                        </div>

                        <div className="wishlist-create-section">
                            <form onSubmit={handleCreate} className="wishlist-create-form">
                                <input
                                    type="text"
                                    placeholder="New wishlist name..."
                                    value={newName}
                                    onChange={(e) => setNewName(e.target.value)}
                                    className="wishlist-create-input"
                                    disabled={isCreating}
                                />
                                <Button
                                    type="submit"
                                    disabled={!newName.trim() || isCreating}
                                    className="wishlist-create-button"
                                    size="sm"
                                >
                                    <Plus size={16} />
                                    {isCreating ? 'Creating...' : 'Create'}
                                </Button>
                            </form>
                        </div>
                    </div>
                </>
            )}

            <style jsx>{`
                .wishlist-selector {
                    position: relative;
                }

                .wishlist-selector-trigger {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    padding: 10px 16px;
                    background: white;
                    border: 1px solid #d1d5db;
                    border-radius: 8px;
                    font-size: 14px;
                    font-weight: 500;
                    cursor: pointer;
                    transition: all 0.2s;
                }

                .wishlist-selector-trigger:hover {
                    border-color: #9ca3af;
                }

                .wishlist-name {
                    color: #111827;
                }

                .chevron {
                    color: #6b7280;
                    transition: transform 0.2s;
                }

                .chevron.open {
                    transform: rotate(180deg);
                }

                .wishlist-selector-overlay {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    z-index: 10;
                }

                .wishlist-selector-dropdown {
                    position: absolute;
                    top: calc(100% + 8px);
                    left: 0;
                    min-width: 280px;
                    background: white;
                    border: 1px solid #e5e7eb;
                    border-radius: 8px;
                    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
                    z-index: 20;
                    overflow: hidden;
                }

                .wishlist-list {
                    max-height: 300px;
                    overflow-y: auto;
                }

                .wishlist-item {
                    width: 100%;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    padding: 12px 16px;
                    background: white;
                    border: none;
                    text-align: left;
                    font-size: 14px;
                    cursor: pointer;
                    transition: background 0.15s;
                }

                .wishlist-item:hover {
                    background: #f9fafb;
                }

                .wishlist-item.active {
                    background: #eff6ff;
                }

                .wishlist-item-name {
                    flex: 1;
                    color: #111827;
                }

                .default-badge {
                    padding: 2px 8px;
                    background: #dbeafe;
                    color: #1e40af;
                    font-size: 11px;
                    font-weight: 500;
                    border-radius: 12px;
                }

                .check-icon {
                    color: #3b82f6;
                }

                .wishlist-create-section {
                    padding: 12px;
                    border-top: 1px solid #e5e7eb;
                    background: #f9fafb;
                }

                .wishlist-create-form {
                    display: flex;
                    gap: 8px;
                }

                .wishlist-create-input {
                    flex: 1;
                    padding: 8px 12px;
                    border: 1px solid #d1d5db;
                    border-radius: 6px;
                    font-size: 13px;
                }

                .wishlist-create-input:focus {
                    outline: none;
                    border-color: #3b82f6;
                }

                .wishlist-create-button {
                    display: flex;
                    align-items: center;
                    gap: 4px;
                    padding: 8px 12px;
                    background: #3b82f6;
                    color: white;
                    border: none;
                    border-radius: 6px;
                    font-size: 13px;
                    font-weight: 500;
                    cursor: pointer;
                    white-space: nowrap;
                }

                .wishlist-create-button:hover:not(:disabled) {
                    background: #2563eb;
                }

                .wishlist-create-button:disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                }
            `}</style>
        </div>
    );
};

