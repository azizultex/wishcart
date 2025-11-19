import React, { useState, useEffect } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { CheckCircle2, XCircle, AlertCircle, Loader2 } from 'lucide-react';
import { __ } from '@wordpress/i18n';

const FluentCRMSettings = () => {
    const [settings, setSettings] = useState({
        enabled: false,
        auto_create_contacts: true,
        send_welcome_email: true,
        default_tags: [],
        default_lists: [],
        price_drop_enabled: true,
        back_in_stock_enabled: true,
        time_based_enabled: true,
        progressive_discounts: true,
        discount_code_prefix: 'WISHLIST',
    });
    const [isAvailable, setIsAvailable] = useState(false);
    const [tags, setTags] = useState([]);
    const [lists, setLists] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [saveMessage, setSaveMessage] = useState('');

    useEffect(() => {
        loadSettings();
    }, []);

    const loadSettings = async () => {
        try {
            const response = await fetch(`${WishCartSettings.apiUrl}fluentcrm/settings`, {
                headers: {
                    'X-WP-Nonce': WishCartSettings.nonce
                }
            });

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    setSettings(data.settings);
                    setIsAvailable(data.is_available);
                }
            }

            // Load tags and lists
            await loadTags();
            await loadLists();
        } catch (error) {
            console.error('Error loading FluentCRM settings:', error);
        } finally {
            setLoading(false);
        }
    };

    const loadTags = async () => {
        try {
            const response = await fetch(`${WishCartSettings.apiUrl}fluentcrm/tags`, {
                headers: {
                    'X-WP-Nonce': WishCartSettings.nonce
                }
            });

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    setTags(data.tags);
                }
            }
        } catch (error) {
            console.error('Error loading tags:', error);
        }
    };

    const loadLists = async () => {
        try {
            const response = await fetch(`${WishCartSettings.apiUrl}fluentcrm/lists`, {
                headers: {
                    'X-WP-Nonce': WishCartSettings.nonce
                }
            });

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    setLists(data.lists);
                }
            }
        } catch (error) {
            console.error('Error loading lists:', error);
        }
    };

    const updateSetting = (key, value) => {
        setSettings(prev => ({
            ...prev,
            [key]: value
        }));
    };

    const saveSettings = async () => {
        setSaving(true);
        setSaveMessage('');

        try {
            const response = await fetch(`${WishCartSettings.apiUrl}fluentcrm/settings`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': WishCartSettings.nonce
                },
                body: JSON.stringify(settings)
            });

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    setSaveMessage('success');
                    setTimeout(() => setSaveMessage(''), 3000);
                } else {
                    setSaveMessage('error');
                }
            } else {
                setSaveMessage('error');
            }
        } catch (error) {
            console.error('Error saving settings:', error);
            setSaveMessage('error');
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <Card>
                <CardContent className="flex items-center justify-center py-8">
                    <Loader2 className="w-6 h-6 animate-spin" />
                </CardContent>
            </Card>
        );
    }

    if (!isAvailable) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{__('FluentCRM Integration', 'wish-cart')}</CardTitle>
                    <CardDescription>{__('Connect WishCart with FluentCRM for automated marketing campaigns', 'wish-cart')}</CardDescription>
                </CardHeader>
                <CardContent>
                    <Alert>
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>
                            {__('FluentCRM plugin is not installed or activated. Please install FluentCRM to use this integration.', 'wish-cart')}
                        </AlertDescription>
                    </Alert>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__('FluentCRM Integration', 'wish-cart')}</CardTitle>
                <CardDescription>{__('Configure FluentCRM integration for automated marketing campaigns', 'wish-cart')}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                {saveMessage === 'success' && (
                    <Alert className="bg-green-50 border-green-200">
                        <CheckCircle2 className="h-4 w-4 text-green-600" />
                        <AlertDescription className="text-green-800">
                            {__('Settings saved successfully!', 'wish-cart')}
                        </AlertDescription>
                    </Alert>
                )}

                {saveMessage === 'error' && (
                    <Alert className="bg-red-50 border-red-200">
                        <XCircle className="h-4 w-4 text-red-600" />
                        <AlertDescription className="text-red-800">
                            {__('Failed to save settings. Please try again.', 'wish-cart')}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                        <Label>{__('Enable FluentCRM Integration', 'wish-cart')}</Label>
                        <p className="text-sm text-gray-500">{__('Activate FluentCRM integration for automated campaigns', 'wish-cart')}</p>
                    </div>
                    <Switch
                        checked={settings.enabled}
                        onCheckedChange={(checked) => updateSetting('enabled', checked)}
                    />
                </div>

                <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                        <Label>{__('Auto-create Contacts', 'wish-cart')}</Label>
                        <p className="text-sm text-gray-500">{__('Automatically create FluentCRM contacts for wishlist users', 'wish-cart')}</p>
                    </div>
                    <Switch
                        checked={settings.auto_create_contacts}
                        onCheckedChange={(checked) => updateSetting('auto_create_contacts', checked)}
                        disabled={!settings.enabled}
                    />
                </div>

                <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                        <Label>{__('Send Welcome Email', 'wish-cart')}</Label>
                        <p className="text-sm text-gray-500">{__('Send welcome email when a product is added to wishlist', 'wish-cart')}</p>
                    </div>
                    <Switch
                        checked={settings.send_welcome_email !== false}
                        onCheckedChange={(checked) => updateSetting('send_welcome_email', checked)}
                        disabled={!settings.enabled}
                    />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="discount_code_prefix">{__('Discount Code Prefix', 'wish-cart')}</Label>
                    <Input
                        id="discount_code_prefix"
                        value={settings.discount_code_prefix}
                        onChange={(e) => updateSetting('discount_code_prefix', e.target.value)}
                        disabled={!settings.enabled}
                        placeholder="WISHLIST"
                    />
                    <p className="text-sm text-gray-500">{__('Prefix for auto-generated discount codes', 'wish-cart')}</p>
                </div>

                <div className="space-y-4">
                    <Label>{__('Campaign Features', 'wish-cart')}</Label>
                    
                    <div className="flex items-center justify-between">
                        <Label className="font-normal">{__('Price Drop Alerts', 'wish-cart')}</Label>
                        <Switch
                            checked={settings.price_drop_enabled}
                            onCheckedChange={(checked) => updateSetting('price_drop_enabled', checked)}
                            disabled={!settings.enabled}
                        />
                    </div>

                    <div className="flex items-center justify-between">
                        <Label className="font-normal">{__('Back in Stock Alerts', 'wish-cart')}</Label>
                        <Switch
                            checked={settings.back_in_stock_enabled}
                            onCheckedChange={(checked) => updateSetting('back_in_stock_enabled', checked)}
                            disabled={!settings.enabled}
                        />
                    </div>

                    <div className="flex items-center justify-between">
                        <Label className="font-normal">{__('Time-based Reminders', 'wish-cart')}</Label>
                        <Switch
                            checked={settings.time_based_enabled}
                            onCheckedChange={(checked) => updateSetting('time_based_enabled', checked)}
                            disabled={!settings.enabled}
                        />
                    </div>

                    <div className="flex items-center justify-between">
                        <Label className="font-normal">{__('Progressive Discounts', 'wish-cart')}</Label>
                        <Switch
                            checked={settings.progressive_discounts}
                            onCheckedChange={(checked) => updateSetting('progressive_discounts', checked)}
                            disabled={!settings.enabled}
                        />
                    </div>
                </div>

                <div className="pt-4 border-t">
                    <Button
                        onClick={saveSettings}
                        disabled={saving || !settings.enabled}
                        className="w-full"
                    >
                        {saving ? (
                            <>
                                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                                {__('Saving...', 'wish-cart')}
                            </>
                        ) : (
                            __('Save Settings', 'wish-cart')
                        )}
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
};

export default FluentCRMSettings;

