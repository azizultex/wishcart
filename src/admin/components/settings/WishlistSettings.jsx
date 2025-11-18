import React, { useState, useEffect } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { __ } from '@wordpress/i18n';
import { Heart, ShoppingCart } from 'lucide-react';

const WishlistSettings = ({ settings, updateSettings }) => {
    const wishlistSettings = settings.wishlist || {
        enabled: true,
        shop_page_button: true,
        product_page_button: true,
        button_position: 'bottom',
        custom_css: '',
        wishlist_page_id: 0,
        shared_wishlist_page_id: 0,
        guest_cookie_expiry: 30,
        button_customization: {
            colors: {
                background: '#ffffff',
                text: '#374151',
                border: 'rgba(107, 114, 128, 0.3)',
                hoverBackground: '#f3f4f6',
                hoverText: '#374151',
                activeBackground: '#fef2f2',
                activeText: '#991b1b',
                activeBorder: 'rgba(220, 38, 38, 0.4)',
                focusBorder: '#3b82f6',
            },
            icon: {
                type: 'predefined',
                value: 'heart',
                customUrl: '',
            },
            labels: {
                add: __('Add to Wishlist', 'wish-cart'),
                saved: __('Saved to Wishlist', 'wish-cart'),
            },
        },
    };

    const resolveButtonPosition = (value) => {
        switch (value) {
            case 'before':
                return 'top';
            case 'after':
                return 'bottom';
            case 'top':
            case 'bottom':
            case 'left':
            case 'right':
                return value;
            default:
                return 'bottom';
        }
    };

    const buttonPosition = resolveButtonPosition(wishlistSettings.button_position);

    const [wishlistPages, setWishlistPages] = useState([]);
    const [loadingPages, setLoadingPages] = useState(false);

    // Load pages for wishlist page selection
    useEffect(() => {
        const loadPages = async () => {
            setLoadingPages(true);
            try {
                const response = await fetch('/wp-json/wp/v2/pages?per_page=100&status=publish');
                if (response.ok) {
                    const pages = await response.json();
                    setWishlistPages(pages);
                }
            } catch (error) {
                console.error('Error loading pages:', error);
            } finally {
                setLoadingPages(false);
            }
        };
        loadPages();
    }, []);

    const updateWishlistSetting = (key, value) => {
        updateSettings('wishlist', key, value);
    };

    return (
        <div className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Heart className="w-5 h-5" />
                        {__('Wishlist Settings', 'wish-cart')}
                    </CardTitle>
                    <CardDescription>
                        {__('Configure wishlist functionality and button placement', 'wish-cart')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                    {/* Enable Wishlist */}
                    <div className="flex items-center justify-between">
                        <div className="space-y-0.5">
                            <Label htmlFor="wishlist_enabled">{__('Enable Wishlist', 'wish-cart')}</Label>
                            <p className="text-sm text-muted-foreground">
                                {__('Enable or disable wishlist functionality', 'wish-cart')}
                            </p>
                        </div>
                        <Switch
                            id="wishlist_enabled"
                            checked={wishlistSettings.enabled || false}
                            onCheckedChange={(checked) => updateWishlistSetting('enabled', checked)}
                        />
                    </div>

                    {/* Shop Page Button */}
                    <div className="flex items-center justify-between">
                        <div className="space-y-0.5">
                            <Label htmlFor="shop_page_button">{__('Show Button on Shop Page', 'wish-cart')}</Label>
                            <p className="text-sm text-muted-foreground">
                                {__('Display wishlist button on product archive/shop pages', 'wish-cart')}
                            </p>
                        </div>
                        <Switch
                            id="shop_page_button"
                            checked={wishlistSettings.shop_page_button !== false}
                            onCheckedChange={(checked) => updateWishlistSetting('shop_page_button', checked)}
                            disabled={!wishlistSettings.enabled}
                        />
                    </div>

                    {/* Product Page Button */}
                    <div className="flex items-center justify-between">
                        <div className="space-y-0.5">
                            <Label htmlFor="product_page_button">{__('Show Button on Product Page', 'wish-cart')}</Label>
                            <p className="text-sm text-muted-foreground">
                                {__('Display wishlist button on single product pages', 'wish-cart')}
                            </p>
                        </div>
                        <Switch
                            id="product_page_button"
                            checked={wishlistSettings.product_page_button !== false}
                            onCheckedChange={(checked) => updateWishlistSetting('product_page_button', checked)}
                            disabled={!wishlistSettings.enabled}
                        />
                    </div>

                    {/* Button Position */}
                    {wishlistSettings.product_page_button && (
                        <div className="space-y-2">
                            <Label htmlFor="button_position">{__('Button Position', 'wish-cart')}</Label>
                            <Select
                                value={buttonPosition}
                                onValueChange={(value) => updateWishlistSetting('button_position', value)}
                                disabled={!wishlistSettings.enabled}
                            >
                                <SelectTrigger id="button_position">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="top">
                                        {__('Above product actions', 'wish-cart')}
                                    </SelectItem>
                                    <SelectItem value="bottom">
                                        {__('Below product actions', 'wish-cart')}
                                    </SelectItem>
                                    <SelectItem value="left">
                                        {__('Left of Add to Cart button', 'wish-cart')}
                                    </SelectItem>
                                    <SelectItem value="right">
                                        {__('Right of Add to Cart button', 'wish-cart')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-sm text-muted-foreground">
                                {__('Choose where to display the wishlist button relative to the purchase actions.', 'wish-cart')}
                            </p>
                        </div>
                    )}

                    {/* Wishlist Page */}
                    <div className="space-y-2">
                        <Label htmlFor="wishlist_page">{__('Wishlist Page', 'wish-cart')}</Label>
                        <Select
                            value={String(wishlistSettings.wishlist_page_id || 0)}
                            onValueChange={(value) => updateWishlistSetting('wishlist_page_id', parseInt(value, 10))}
                            disabled={!wishlistSettings.enabled || loadingPages}
                        >
                            <SelectTrigger id="wishlist_page">
                                <SelectValue placeholder={__('Select a page', 'wish-cart')} />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="0">{__('-- Select Page --', 'wish-cart')}</SelectItem>
                                {wishlistPages.map((page) => (
                                    <SelectItem key={page.id} value={String(page.id)}>
                                        {page.title.rendered}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                            {__('Select the page where the wishlist will be displayed. Make sure the page contains the [wishcart_wishlist] shortcode.', 'wish-cart')}
                        </p>
                    </div>

                    {/* Shareable Page */}
                    <div className="space-y-2">
                        <Label htmlFor="shared_wishlist_page">{__('Shareable Page', 'wish-cart')}</Label>
                        <Select
                            value={String(wishlistSettings.shared_wishlist_page_id || 0)}
                            onValueChange={(value) => updateWishlistSetting('shared_wishlist_page_id', parseInt(value, 10))}
                            disabled={!wishlistSettings.enabled || loadingPages}
                        >
                            <SelectTrigger id="shared_wishlist_page">
                                <SelectValue placeholder={__('Select a page', 'wish-cart')} />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="0">{__('-- Select Page --', 'wish-cart')}</SelectItem>
                                {wishlistPages.map((page) => (
                                    <SelectItem key={page.id} value={String(page.id)}>
                                        {page.title.rendered}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                            {__('Select the page where shared wishlists will be displayed. Make sure the page contains the [wishcart_shared_wishlist] shortcode.', 'wish-cart')}
                        </p>
                    </div>

                    {/* Guest Cookie Expiry */}
                    <div className="space-y-2">
                        <Label htmlFor="guest_cookie_expiry">{__('Guest Wishlist Expiry (Days)', 'wish-cart')}</Label>
                        <Input
                            id="guest_cookie_expiry"
                            type="number"
                            min="1"
                            max="365"
                            value={wishlistSettings.guest_cookie_expiry || 30}
                            onChange={(e) => updateWishlistSetting('guest_cookie_expiry', parseInt(e.target.value, 10))}
                            disabled={!wishlistSettings.enabled}
                        />
                        <p className="text-sm text-muted-foreground">
                            {__('Number of days guest wishlists are stored in cookies', 'wish-cart')}
                        </p>
                    </div>

                    {/* Custom CSS */}
                    <div className="space-y-2">
                        <Label htmlFor="custom_css">{__('Custom CSS', 'wish-cart')}</Label>
                        <Textarea
                            id="custom_css"
                            rows={8}
                            value={wishlistSettings.custom_css || ''}
                            onChange={(e) => updateWishlistSetting('custom_css', e.target.value)}
                            placeholder={__('Add custom CSS for wishlist button styling...', 'wish-cart')}
                            disabled={!wishlistSettings.enabled}
                            className="font-mono text-sm"
                        />
                        <p className="text-sm text-muted-foreground">
                            {__('Add custom CSS to style the wishlist button. Use selector: .wishcart-wishlist-button', 'wish-cart')}
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
};

export default WishlistSettings;

