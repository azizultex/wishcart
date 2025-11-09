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
    Settings,
    MessageSquare,
    Wand2,
    Share2,
    MoreHorizontal,
    HelpCircle,
    ExternalLink,
    CheckCircle2,
    XCircle
} from 'lucide-react';

import ChatWidgetSettings from './ChatWidgetSettings';
import AiConfigSettings from './AiConfigSettings';
import IntegrationsSettings from './IntegrationsSettings';
import MiscSettings from './MiscSettings';
import {buttonVariants} from "../../../components/ui/button";


const SettingsApp = () => {
    const { toast } = useToast()
    const [settings, setSettings] = useState({
        general: {
            openai_key: '',
        },
        chatwidget: {
            chat_icon: '',
            widget_logo: '',
            widget_text: '',
            widget_color: '#1976d2',
            suggested_questions: [],
            widget_position: 'bottom-right',
            widget_greeting: '',
            widget_placeholder: '',
            widget_title: '',
            widget_subtitle: '',
        },
        ai_config: {
            fluentcart_enabled: false,
            included_post_types: ['post', 'page'],
            excluded_posts: [],
            excluded_pages: [],
            excluded_products: [],
            exclude_categories: [],
            contact_info: '',
            custom_content: '',
            batch_size: 10,
            max_context_length: 2000,
        },
        integrations: {
            whatsapp: {
                enabled: false,
                account_sid: '',
                auth_token: '',
                phone_number: '',
                welcome_message: '',
                enable_template_messages: false,
            },
            telegram: {
                enabled: false,
                bot_token: '',
                bot_username: '',
                welcome_message: '',
            },
            contact_form: {
                enabled: false,
                shortcode: '',
            },
        },
        misc: {
            custom_css: '',
        },
    });

    const [isSaving, setIsSaving] = useState(false);
    const [saveMessage, setSaveMessage] = useState('');
    const [activeTab, setActiveTab] = useState("general");
    const [activeIntegrationTab, setActiveIntegrationTab] = useState("whatsapp");
    const { nonce, apiUrl } = settings;

    useEffect(() => {
        // Load settings from WordPress on mount
        loadSettings();
    }, []);

    const loadSettings = async () => {
        try {
            const response = await fetch('/wp-json/aisk/v1/settings', {
                headers: {
                    'X-WP-Nonce': AiskSettings.nonce
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
        const phone = settings?.integrations?.whatsapp?.phone_number || '';
        if (phone && !/^whatsapp:\+\d{10,15}$/.test(phone)) {
            // Focus the Integrations → WhatsApp tab and show an error toast
            setActiveTab('integrations');
            setActiveIntegrationTab('whatsapp');
            toast({
                title: (
                    <div className="flex items-center gap-2">
                        <XCircle className="h-4 w-4 text-red-500" />
                        <span>{__('Invalid WhatsApp number', 'aisk-ai-chat-for-fluentcart')}</span>
                    </div>
                ),
                description: __('Use format "whatsapp:+1234567890" including country code.', 'aisk-ai-chat-for-fluentcart'),
                className: "bg-red-50 border-red-200"
            });
            return false;
        }
        return true;
    };

    const saveSettings = async () => {
        if (!validateBeforeSave()) return;
        setIsSaving(true);
        try {
            const response = await fetch('/wp-json/aisk/v1/settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskSettings.nonce
                },
                body: JSON.stringify(settings)
            });

            if (response.ok) {
                toast({
                    title: (
                        <div className="flex items-center gap-2">
                            <CheckCircle2 className="h-4 w-4 text-green-500" />
                            <span>{__('Settings saved successfully!', 'aisk-ai-chat-for-fluentcart')}</span>
                        </div>
                    ),
                    description: __('Your changes have been applied.', 'aisk-ai-chat-for-fluentcart'),
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
                        <span>{__('Failed to save settings', 'aisk-ai-chat-for-fluentcart')}</span>
                    </div>
                ),
                description: __('Please try again or contact support if the problem persists.', 'aisk-ai-chat-for-fluentcart'),
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
                                <CardTitle className="text-2xl">{__('Aisk Settings', 'aisk-ai-chat-for-fluentcart')}</CardTitle>
                                <CardDescription>
                                    {__('Configure your chatbot and integration settings', 'aisk-ai-chat-for-fluentcart')}
                                </CardDescription>
                            </div>
                            <div className="space-x-3">
                                <a href="https://aisk.chat/support"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   className={buttonVariants({ variant: "default" })}
                                >
                                    <HelpCircle className="w-4 h-4 mr-2" />
                                    {__('Need Help?', 'aisk-ai-chat-for-fluentcart')}
                                </a>

                                <a href="https://aisk.chat/docs"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   className={buttonVariants({ variant: "outline" })}
                                >
                                    <ExternalLink className="w-4 h-4 mr-2" />
                                    {__('Documentation', 'aisk-ai-chat-for-fluentcart')}
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

                <Tabs defaultValue="general" className="w-full">
                    <TabsList className="mb-4">
                        <TabsTrigger value="general" className="flex items-center gap-2">
                            <Settings className="w-4 h-4" />
                            {__('General', 'aisk-ai-chat-for-fluentcart')}
                        </TabsTrigger>
                        <TabsTrigger value="chatwidget" className="flex items-center gap-2">
                            <MessageSquare className="w-4 h-4" />
                            {__('Chat Widget', 'aisk-ai-chat-for-fluentcart')}
                        </TabsTrigger>
                        <TabsTrigger value="ai_config" className="flex items-center gap-2">
                            <Wand2 className="w-4 h-4" />
                            {__('AI Config', 'aisk-ai-chat-for-fluentcart')}
                        </TabsTrigger>
                        <TabsTrigger value="integrations" className="flex items-center gap-2">
                            <Share2 className="w-4 h-4" />
                            {__('Integrations', 'aisk-ai-chat-for-fluentcart')}
                        </TabsTrigger>
                        <TabsTrigger value="misc" className="flex items-center gap-2">
                            <MoreHorizontal className="w-4 h-4" />
                            {__('Misc', 'aisk-ai-chat-for-fluentcart')}
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="general">
                        <Card>
                            <CardHeader>
                                <CardTitle>{__('General Settings', 'aisk-ai-chat-for-fluentcart')}</CardTitle>
                                <CardDescription>
                                    {__('Configure your API keys and general settings', 'aisk-ai-chat-for-fluentcart')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4 max-w-2xl">
                                

                                <div className="space-y-2">
                                    <Label htmlFor="openai_key">{__('OpenAI API Key', 'aisk-ai-chat-for-fluentcart')}</Label>
                                    <Input
                                        id="openai_key"
                                        value={settings.general.openai_key}
                                        onChange={(e) => updateSettings('general', 'openai_key', e.target.value)}
                                        type="password"
                                        className="max-w-xl"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {__('Generate your API key at', 'aisk-ai-chat-for-fluentcart')}{" "}
                                        <a
                                            href="https://platform.openai.com/api-keys"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-primary hover:text-primary/80 underline"
                                        >
                                            platform.openai.com/api-keys
                                        </a>
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="chatwidget">
                        <ChatWidgetSettings
                            settings={settings}
                            updateSettings={updateSettings}
                        />
                    </TabsContent>

                    <TabsContent value="ai_config">
                        <AiConfigSettings
                            settings={settings}
                            updateSettings={updateSettings}
                        />
                    </TabsContent>

                    <TabsContent value="integrations">
                        <div className="bg-white p-6 rounded-lg shadow">
                            <Tabs value={activeIntegrationTab} onValueChange={setActiveIntegrationTab}>

                                <TabsContent value="whatsapp">
                                    <IntegrationsSettings
                                        type="whatsapp"
                                        settings={settings}
                                        updateSettings={updateSettings}
                                    />
                                </TabsContent>

                                <TabsContent value="telegram">
                                    <IntegrationsSettings
                                        type="telegram"
                                        settings={settings}
                                        updateSettings={updateSettings}
                                    />
                                </TabsContent>

                                <TabsContent value="webhook">
                                    <IntegrationsSettings
                                        type="webhook"
                                        settings={settings}
                                        updateSettings={updateSettings}
                                    />
                                </TabsContent>

                                <TabsContent value="contact_form">
                                    <IntegrationsSettings
                                        type="contact_form"
                                        settings={settings}
                                        updateSettings={updateSettings}
                                    />
                                </TabsContent>
                            </Tabs>
                        </div>
                    </TabsContent>

                    <TabsContent value="misc">
                        <MiscSettings
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
                        {isSaving ? __('Saving...', 'aisk-ai-chat-for-fluentcart') : __('Save Settings', 'aisk-ai-chat-for-fluentcart')}
                    </Button>
                </div>
            </div>
            <Toaster />
        </>
    );
};

export default SettingsApp;