import { __ } from '@wordpress/i18n';
import React, { useState, useEffect } from 'react';
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Toaster } from "@/components/ui/toaster"
import { useToast } from "@/components/hooks/use-toast"
import {
    Heart,
    HelpCircle,
    ExternalLink,
    CheckCircle2,
    XCircle,
    LifeBuoy,
    ShieldCheck,
    Wrench,
    Palette,
    BarChart3,
    Mail
} from 'lucide-react';

import WishlistSettings from './WishlistSettings';
import ButtonCustomizationSettings from './ButtonCustomizationSettings';
import FluentCRMSettings from './FluentCRMSettings';
import {buttonVariants} from "../../../components/ui/button";
import { AnalyticsDashboard } from '../AnalyticsDashboard';


const SettingsApp = () => {
    const { toast } = useToast()
    const [settings, setSettings] = useState({
        wishlist: {
            enabled: true,
            shop_page_button: true,
            product_page_button: true,
            button_position: 'bottom',
            custom_css: '',
            wishlist_page_id: 0,
            shared_wishlist_page_id: 0,
            guest_cookie_expiry: 30,
        },
    });

    const [isSaving, setIsSaving] = useState(false);
    const [saveMessage, setSaveMessage] = useState('');
    const [activeTab, setActiveTab] = useState("settings");

    useEffect(() => {
        // Load settings from WordPress on mount
        loadSettings();
    }, []);

    const loadSettings = async () => {
        try {
            const response = await fetch('/wp-json/wishcart/v1/settings', {
                headers: {
                    'X-WP-Nonce': WishCartSettings.nonce
                }
            });
            const data = await response.json();
            if (data) {
                const normalizedData = { ...data };

                if (normalizedData.wishlist) {
                    normalizedData.wishlist = { ...normalizedData.wishlist };
                    const position = normalizedData.wishlist.button_position || 'bottom';
                    switch (position) {
                        case 'before':
                            normalizedData.wishlist.button_position = 'top';
                            break;
                        case 'after':
                            normalizedData.wishlist.button_position = 'bottom';
                            break;
                        case 'top':
                        case 'bottom':
                        case 'left':
                        case 'right':
                            normalizedData.wishlist.button_position = position;
                            break;
                        default:
                            normalizedData.wishlist.button_position = 'bottom';
                            break;
                    }
                }

                setSettings(prevSettings => ({
                    ...prevSettings,
                    ...normalizedData
                }));
            }
        } catch (error) {
            console.error('Error loading settings:', error);
        }
    };

    // Validate inputs before saving
    const validateBeforeSave = () => {
        // Add validation if needed
        return true;
    };

    const saveSettings = async () => {
        if (!validateBeforeSave()) return;
        setIsSaving(true);
        try {
            const response = await fetch('/wp-json/wishcart/v1/settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': WishCartSettings.nonce
                },
                body: JSON.stringify(settings)
            });

            if (response.ok) {
                toast({
                    title: (
                        <div className="flex items-center gap-2">
                            <CheckCircle2 className="h-4 w-4 text-green-500" />
                            <span>{__('Settings saved successfully!', 'wish-cart')}</span>
                        </div>
                    ),
                    description: __('Your changes have been applied.', 'wish-cart'),
                    className: "bg-green-50 border-green-200"
                });
            } else {
                throw new Error('Failed to save settings');
            }
        } catch (error) {
            toast({
                title: (
                    <div className="flex items-center gap-2">
                        <XCircle className="h-4 w-4 text-red-500" />
                        <span>{__('Failed to save settings', 'wish-cart')}</span>
                    </div>
                ),
                description: __('Please try again or contact support if the problem persists.', 'wish-cart'),
                className: "bg-red-50 border-red-200"
            });
        } finally {
            setIsSaving(false);
        }
    };

    const updateSettings = (section, key, value) => {
        setSettings(prev => ({
            ...prev,
            [section]: {
                ...prev[section],
                [key]: value
            }
        }));
    };

    const pluginLogo = `${WishCartSettings.pluginUrl}assets/images/icons/menu-icon.svg`;

    return (
        <>
            <div className="wishcart-admin-shell min-h-[70vh] bg-slate-50 py-6">
                <div className="mx-auto max-w-6xl px-4 lg:px-6 space-y-6">
                    <header className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/5">
                                <img
                                    src={pluginLogo}
                                    alt={__('WishCart logo', 'wish-cart')}
                                    className="h-6 w-6"
                                />
                            </div>
                            <div>
                                <h1 className="text-xl font-semibold tracking-tight text-slate-900">
                                    {__('WishCart', 'wish-cart')}
                                </h1>
                                <p className="flex items-center gap-2 text-sm text-slate-500">
                                    <span>{__('Wishlist & engagement tools for your store', 'wish-cart')}</span>
                                    <span className="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide text-emerald-700">
                                        {__('Free', 'wish-cart')}
                                    </span>
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <div className="flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
                                <span className="h-2 w-2 rounded-full bg-emerald-500" />
                                <span>{__('You are connected.', 'wish-cart')}</span>
                            </div>
                            <div className="hidden sm:flex items-center gap-2">
                                <a
                                    href="https://wishcart.chat/support"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={buttonVariants({ variant: "outline", size: "sm" })}
                                >
                                    <HelpCircle className="mr-2 h-4 w-4" />
                                    {__('Support', 'wish-cart')}
                                </a>
                                <a
                                    href="https://wishcart.chat/docs"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={buttonVariants({ variant: "ghost", size: "sm" })}
                                >
                                    <ExternalLink className="mr-2 h-4 w-4" />
                                    {__('Docs', 'wish-cart')}
                                </a>
                            </div>
                        </div>
                    </header>

                    {saveMessage && (
                        <Alert className="border-amber-200 bg-amber-50">
                            <AlertDescription>{saveMessage}</AlertDescription>
                        </Alert>
                    )}

                    <Card className="shadow-sm border-slate-200">
                        <CardHeader className="border-b border-slate-100 pb-3">
                            <CardTitle className="text-base font-semibold">
                                {__('WishCart dashboard', 'wish-cart')}
                            </CardTitle>
                            <CardDescription>
                                {__('Manage wishlist behavior, tools, and plugin information.', 'wish-cart')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="pt-4">
                            <Tabs
                                value={activeTab}
                                onValueChange={setActiveTab}
                                className="w-full"
                            >
                                <TabsList className="mb-4 bg-slate-50 flex flex-wrap">
                                    <TabsTrigger value="settings" className="flex items-center gap-2">
                                        <Heart className="w-4 h-4" />
                                        {__('Settings', 'wish-cart')}
                                    </TabsTrigger>
                                    <TabsTrigger value="button-customization" className="flex items-center gap-2">
                                        <Palette className="w-4 h-4" />
                                        {__('Button Customization', 'wish-cart')}
                                    </TabsTrigger>
                                    <TabsTrigger value="analytics" className="flex items-center gap-2">
                                        <BarChart3 className="w-4 h-4" />
                                        {__('Analytics', 'wish-cart')}
                                    </TabsTrigger>
                                    <TabsTrigger value="fluentcrm" className="flex items-center gap-2">
                                        <Mail className="w-4 h-4" />
                                        {__('FluentCRM', 'wish-cart')}
                                    </TabsTrigger>
                                    <TabsTrigger value="tools" className="flex items-center gap-2">
                                        <Wrench className="w-4 h-4" />
                                        {__('Tools', 'wish-cart')}
                                    </TabsTrigger>
                                    <TabsTrigger value="support" className="flex items-center gap-2">
                                        <LifeBuoy className="w-4 h-4" />
                                        {__('Support', 'wish-cart')}
                                    </TabsTrigger>
                                    <TabsTrigger value="license" className="flex items-center gap-2">
                                        <ShieldCheck className="w-4 h-4" />
                                        {__('License', 'wish-cart')}
                                    </TabsTrigger>
                                </TabsList>

                                <TabsContent value="settings" className="space-y-6">
                                    <WishlistSettings
                                        settings={settings}
                                        updateSettings={updateSettings}
                                    />
                                </TabsContent>

                                <TabsContent value="button-customization" className="space-y-6">
                                    <ButtonCustomizationSettings
                                        settings={settings}
                                        updateSettings={updateSettings}
                                    />
                                </TabsContent>

                                <TabsContent value="analytics" className="space-y-6">
                                    <AnalyticsDashboard />
                                </TabsContent>

                                <TabsContent value="fluentcrm" className="space-y-6">
                                    <FluentCRMSettings />
                                </TabsContent>

                                <TabsContent value="tools" className="space-y-4">
                                    <p className="text-sm text-muted-foreground">
                                        {__('Quick links to wishlist-related tools and pages.', 'wish-cart')}
                                    </p>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <Card className="border-dashed">
                                            <CardHeader>
                                                <CardTitle className="text-sm">
                                                    {__('Wishlist page', 'wish-cart')}
                                                </CardTitle>
                                                <CardDescription>
                                                    {__('Preview the public wishlist page in a new tab.', 'wish-cart')}
                                                </CardDescription>
                                            </CardHeader>
                                            <CardContent>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={WishCartSettings.pluginUrl}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        <ExternalLink className="mr-2 h-4 w-4" />
                                                        {__('Open wishlist page', 'wish-cart')}
                                                    </a>
                                                </Button>
                                            </CardContent>
                                        </Card>
                                    </div>
                                </TabsContent>

                                <TabsContent value="support" className="space-y-4">
                                    <p className="text-sm text-muted-foreground">
                                        {__('Need help? Get in touch with our team or browse documentation.', 'wish-cart')}
                                    </p>
                                    <div className="flex flex-wrap gap-3">
                                        <Button
                                            asChild
                                        >
                                            <a
                                                href="https://wishcart.chat/support"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <LifeBuoy className="mr-2 h-4 w-4" />
                                                {__('Contact support', 'wish-cart')}
                                            </a>
                                        </Button>
                                        <Button
                                            variant="outline"
                                            asChild
                                        >
                                            <a
                                                href="https://wishcart.chat/docs"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <ExternalLink className="mr-2 h-4 w-4" />
                                                {__('View docs', 'wish-cart')}
                                            </a>
                                        </Button>
                                    </div>
                                </TabsContent>

                                <TabsContent value="license" className="space-y-4">
                                    <p className="text-sm text-muted-foreground">
                                        {__('You are currently using the free version of WishCart. All core wishlist features are included.', 'wish-cart')}
                                    </p>
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-sm">
                                                {__('Upgrade options', 'wish-cart')}
                                            </CardTitle>
                                            <CardDescription>
                                                {__('Unlock advanced analytics and automation when available.', 'wish-cart')}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <Button
                                                variant="outline"
                                                asChild
                                            >
                                                <a
                                                    href="https://wishcart.chat"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    {__('Visit website', 'wish-cart')}
                                                </a>
                                            </Button>
                                        </CardContent>
                                    </Card>
                                </TabsContent>
                            </Tabs>

                            <div className="mt-6 flex justify-end border-t border-slate-100 pt-4">
                                <Button
                                    onClick={saveSettings}
                                    disabled={isSaving}
                                >
                                    {isSaving ? __('Saving...', 'wish-cart') : __('Save Settings', 'wish-cart')}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
            <Toaster />
        </>
    );
};

export default SettingsApp;