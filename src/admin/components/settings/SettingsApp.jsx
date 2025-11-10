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
    XCircle
} from 'lucide-react';

import WishlistSettings from './WishlistSettings';
import {buttonVariants} from "../../../components/ui/button";


const SettingsApp = () => {
    const { toast } = useToast()
    const [settings, setSettings] = useState({
        wishlist: {
            enabled: true,
            shop_page_button: true,
            product_page_button: true,
            button_position: 'after',
            custom_css: '',
            wishlist_page_id: 0,
            guest_cookie_expiry: 30,
        },
    });

    const [isSaving, setIsSaving] = useState(false);
    const [saveMessage, setSaveMessage] = useState('');
    const [activeTab, setActiveTab] = useState("wishlist");

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
                setSettings(prevSettings => ({
                    ...prevSettings,
                    ...data
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

    return (
        <>
            <div className="container mx-auto p-6">
                <Card className="mb-6">
                    <CardHeader>
                        <div className="flex justify-between items-start">
                            <div>
                                <CardTitle className="text-2xl">{__('WishCart Settings', 'wish-cart')}</CardTitle>
                                <CardDescription>
                                    {__('Configure your wishlist settings', 'wish-cart')}
                                </CardDescription>
                            </div>
                            <div className="space-x-3">
                                <a href="https://wishcart.chat/support"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   className={buttonVariants({ variant: "default" })}
                                >
                                    <HelpCircle className="w-4 h-4 mr-2" />
                                    {__('Need Help?', 'wish-cart')}
                                </a>

                                <a href="https://wishcart.chat/docs"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   className={buttonVariants({ variant: "outline" })}
                                >
                                    <ExternalLink className="w-4 h-4 mr-2" />
                                    {__('Documentation', 'wish-cart')}
                                </a>
                            </div>
                        </div>
                    </CardHeader>
                </Card>
                {saveMessage && (
                    <Alert className="mb-6">
                        <AlertDescription>{saveMessage}</AlertDescription>
                    </Alert>
                )}

                <Tabs defaultValue="wishlist" className="w-full">
                    <TabsList className="mb-4">
                        <TabsTrigger value="wishlist" className="flex items-center gap-2">
                            <Heart className="w-4 h-4" />
                            {__('Wishlist', 'wish-cart')}
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="wishlist">
                        <WishlistSettings
                            settings={settings}
                            updateSettings={updateSettings}
                        />
                    </TabsContent>

                </Tabs>

                <div className="mt-6 flex justify-end">
                    <Button
                        onClick={saveSettings}
                        disabled={isSaving}
                    >
                        {isSaving ? __('Saving...', 'wish-cart') : __('Save Settings', 'wish-cart')}
                    </Button>
                </div>
            </div>
            <Toaster />
        </>
    );
};

export default SettingsApp;