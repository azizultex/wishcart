import React, { useState, useEffect } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { __ } from '@wordpress/i18n';
import { HexColorPicker } from "react-colorful";
import { Sketch } from '@uiw/react-color';

const ChatWidgetSettings = ({ settings, updateSettings }) => {
    const handleMediaUpload = (field) => {
        // Create WordPress media uploader
        const mediaUploader = wp.media({
            title: __('Select Image', 'aisk-ai-chat-for-fluentcart'),
            button: {
                text: __('Use this image', 'aisk-ai-chat-for-fluentcart')
            },
            multiple: false
        });

        mediaUploader.on('select', function () {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            updateSettings('chatwidget', field, attachment.url);
        });

        mediaUploader.open();
    };

    // Local state for rolling messages textarea
    const [rollingMessagesInput, setRollingMessagesInput] = useState(settings.chatwidget.rolling_messages?.join('\n') || '');

    // Keep local state in sync if settings change externally
    useEffect(() => {
        setRollingMessagesInput(settings.chatwidget.rolling_messages?.join('\n') || '');
    }, [settings.chatwidget.rolling_messages]);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__('Chat Widget Settings', 'aisk-ai-chat-for-fluentcart')}</CardTitle>
                <CardDescription>
                    {__('Customize the appearance and behavior of your chat widget', 'aisk-ai-chat-for-fluentcart')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">

                <div className="space-y-4">
                    <Label>{__('Chat Bubble Style', 'aisk-ai-chat-for-fluentcart')}</Label>
                    <RadioGroup
                        value={settings.chatwidget.bubble_type || 'default'}
                        onValueChange={(value) => updateSettings('chatwidget', 'bubble_type', value)}
                        className="grid grid-cols-2 gap-4"
                    >
                        <div className="flex items-center space-x-2">
                            <RadioGroupItem value="default" id="default" />
                            <Label htmlFor="default" className="cursor-pointer">
                                <div className="space-y-2">
                                    <div className="font-medium">{__('Default Bubble', 'aisk-ai-chat-for-fluentcart')}</div>
                                    <div className="text-sm text-gray-500">
                                        {__('Single message with animated emoji', 'aisk-ai-chat-for-fluentcart')}
                                    </div>
                                </div>
                            </Label>
                        </div>

                        <div className="flex items-center space-x-2">
                            <RadioGroupItem value="rolling" id="rolling" />
                            <Label htmlFor="rolling" className="cursor-pointer">
                                <div className="space-y-2">
                                    <div className="font-medium">{__('Rolling Messages', 'aisk-ai-chat-for-fluentcart')}</div>
                                    <div className="text-sm text-gray-500">
                                        {__('Multiple rotating messages', 'aisk-ai-chat-for-fluentcart')}
                                    </div>
                                </div>
                            </Label>
                        </div>
                    </RadioGroup>
                </div>

                {/* Message Settings based on type */}
                {settings.chatwidget.bubble_type === 'rolling' ? (
                    <div className="space-y-2">
                        <Label htmlFor="rolling_messages">{__('Rolling Messages', 'aisk-ai-chat-for-fluentcart')}</Label>
                        <Textarea
                            id="rolling_messages"
                            value={rollingMessagesInput}
                            onChange={(e) => setRollingMessagesInput(e.target.value)}
                            onBlur={() => updateSettings('chatwidget', 'rolling_messages', rollingMessagesInput.split('\n').filter(msg => msg.trim()))}
                            placeholder={__('👋 Need help?\n💬 Chat with us!\n🛍️ Find products', 'aisk-ai-chat-for-fluentcart')}
                            rows={4}
                        />
                        <p className="text-sm text-gray-500">
                            {__('Enter one message per line. Each message will be shown in rotation.', 'aisk-ai-chat-for-fluentcart')}
                        </p>
                    </div>
                ) : (
                    <div className="space-y-2">
                        <Label htmlFor="default_message">{__('Bubble Message', 'aisk-ai-chat-for-fluentcart')}</Label>
                        <Input
                            id="default_message"
                            value={settings.chatwidget.default_message || ''}
                            onChange={(e) => updateSettings('chatwidget', 'default_message', e.target.value)}
                            placeholder={__('Hey, need help? 👋', 'aisk-ai-chat-for-fluentcart')}
                        />
                        <p className="text-sm text-gray-500">
                            {__('Add an emoji to make your message more engaging!', 'aisk-ai-chat-for-fluentcart')}
                        </p>
                    </div>
                )}

                {/* Chat Icon */}
                <div className="space-y-2">
                    <Label>{__('Chat Bubble Icon', 'aisk-ai-chat-for-fluentcart')}</Label>
                    <div className="flex items-center gap-4">
                        {settings.chatwidget.chat_icon && (
                            <img
                                src={settings.chatwidget.chat_icon}
                                alt={__('Chat Icon', 'aisk-ai-chat-for-fluentcart')}
                                className="w-12 h-12 rounded"
                            />
                        )}
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={() => handleMediaUpload('chat_icon')}
                            >
                                {settings.chatwidget.chat_icon ? __('Change Icon', 'aisk-ai-chat-for-fluentcart') : __('Upload Icon', 'aisk-ai-chat-for-fluentcart')}
                            </Button>
                            {settings.chatwidget.chat_icon && (
                                <Button
                                    variant="outline"
                                    onClick={() => updateSettings('chatwidget', 'chat_icon', '')}
                                    className="text-red-600 hover:text-red-700 hover:bg-red-50"
                                >
                                    {__('Remove Icon', 'aisk-ai-chat-for-fluentcart')}
                                </Button>
                            )}
                        </div>
                    </div>
                </div>

                {/* Widget Logo */}
                <div className="space-y-2">
                    <Label>{__('Widget Header Logo', 'aisk-ai-chat-for-fluentcart')}</Label>
                    <div className="flex items-center gap-4">
                        {settings.chatwidget.widget_logo && (
                            <div className="max-w-[150px] rounded overflow-hidden flex items-center justify-center">
                                <img
                                    src={settings.chatwidget.widget_logo}
                                    alt={__('Widget Logo', 'aisk-ai-chat-for-fluentcart')}
                                    className="w-full h-auto object-contain"
                                />
                            </div>
                        )}
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={() => handleMediaUpload('widget_logo')}
                            >
                                {settings.chatwidget.widget_logo ? __('Change Logo', 'aisk-ai-chat-for-fluentcart') : __('Upload Logo', 'aisk-ai-chat-for-fluentcart')}
                            </Button>
                            {settings.chatwidget.widget_logo && (
                                <Button
                                    variant="outline"
                                    onClick={() => updateSettings('chatwidget', 'widget_logo', '')}
                                    className="text-red-600 hover:text-red-700 hover:bg-red-50"
                                >
                                    {__('Remove Logo', 'aisk-ai-chat-for-fluentcart')}
                                </Button>
                            )}
                        </div>
                    </div>
                </div>

                {/* Widget Text */}
                <div className="space-y-2">
                    <Label htmlFor="widget_text">{__('Widget Header Text', 'aisk-ai-chat-for-fluentcart')}</Label>
                    <Input
                        id="widget_text"
                        value={settings.chatwidget.widget_text}
                        onChange={(e) => updateSettings('chatwidget', 'widget_text', e.target.value)}
                        placeholder={__('Enter header text', 'aisk-ai-chat-for-fluentcart')}
                    />
                </div>

                {/* Widget Color */}
                {/* <div className="space-y-2">
                    <Label htmlFor="widget_color">{__('Widget Theme Color', 'aisk-ai-chat-for-fluentcart')}</Label>
                    <div className="flex items-center gap-4">
                        <Input
                            id="widget_color"
                            type="color"
                            value={settings.chatwidget.widget_color}
                            onChange={(e) => updateSettings('chatwidget', 'widget_color', e.target.value)}
                            className="w-20 h-10"
                        />
                        <Input
                            type="text"
                            value={settings.chatwidget.widget_color}
                            onChange={(e) => updateSettings('chatwidget', 'widget_color', e.target.value)}
                            placeholder="#000000"
                            className="w-32"
                        />
                    </div>
                </div> */}

                {/* <div className="space-y-2">
                    <Label htmlFor="widget_color">{__('Widget Theme Color', 'aisk-ai-chat-for-fluentcart')}</Label>
                    <div className="flex items-start gap-4">
                        <div className="relative">
                            <HexColorPicker 
                                color={settings.chatwidget.widget_color} 
                                onChange={(color) => updateSettings('chatwidget', 'widget_color', color)}
                                style={{ width: '200px', height: '200px' }}
                            />
                        </div>
                        <div className="space-y-2">
                            <Input
                                type="text"
                                value={settings.chatwidget.widget_color}
                                onChange={(e) => updateSettings('chatwidget', 'widget_color', e.target.value)}
                                placeholder="#000000"
                                className="w-32"
                            />
                            <div 
                                className="w-32 h-8 rounded border"
                                style={{ backgroundColor: settings.chatwidget.widget_color }}
                            />
                        </div>
                    </div>
                </div> */}
                <div className="space-y-2">
                    <Label htmlFor="widget_color">{__('Widget Theme Color', 'aisk-ai-chat-for-fluentcart')}</Label>
                    <div className="flex items-start gap-4">
                        <Sketch
                            style={{ maxWidth: '250px' }}
                            color={settings.chatwidget.widget_color}
                            onChange={(color) => updateSettings('chatwidget', 'widget_color', color.hex)}
                            presetColors={[
                                '#D0021B', '#F5A623', '#F8E71C', '#8B572A', '#7ED321',
                                '#417505', '#BD10E0', '#9013FE', '#4A90E2', '#50E3C2',
                                '#B8E986', '#000000', '#4A4A4A', '#9B9B9B', '#FFFFFF',
                            ]}
                        />
                        <div className="space-y-2">
                            <Input
                                type="text"
                                value={settings.chatwidget.widget_color}
                                onChange={(e) => updateSettings('chatwidget', 'widget_color', e.target.value)}
                                placeholder="#000000"
                                className="w-32"
                            />
                            <div
                                className="w-32 h-8 rounded border"
                                style={{ backgroundColor: settings.chatwidget.widget_color }}
                            />
                        </div>
                    </div>
                </div>
                {/* Greeting Message */}
                <div className="space-y-2">
                    <Label htmlFor="widget_greeting">{__('Greeting Message', 'aisk-ai-chat-for-fluentcart')}</Label>
                    <Textarea
                        id="widget_greeting"
                        value={settings.chatwidget.widget_greeting}
                        onChange={(e) => updateSettings('chatwidget', 'widget_greeting', e.target.value)}
                        placeholder={__('Enter greeting message', 'aisk-ai-chat-for-fluentcart')}
                        rows={3}
                    />
                </div>

                {/* Suggested Questions */}
                <div className="space-y-2">
                    <Label htmlFor="suggested_questions">{__('Suggested Questions', 'aisk-ai-chat-for-fluentcart')}</Label>
                    <Textarea
                        id="suggested_questions"
                        value={settings.chatwidget.suggested_questions.join('\n')}
                        onChange={(e) => updateSettings('chatwidget', 'suggested_questions', e.target.value.split('\n'))}
                        placeholder={__('Enter one question per line', 'aisk-ai-chat-for-fluentcart')}
                        rows={4}
                    />
                </div>

                {/* Input Placeholder */}
                <div className="space-y-2">
                    <Label htmlFor="widget_placeholder">{__('Input Placeholder', 'aisk-ai-chat-for-fluentcart')}</Label>
                    <Input
                        id="widget_placeholder"
                        value={settings.chatwidget.widget_placeholder}
                        onChange={(e) => updateSettings('chatwidget', 'widget_placeholder', e.target.value)}
                        placeholder={__('Type your message...', 'aisk-ai-chat-for-fluentcart')}
                    />
                </div>

                {/* Widget Position */}
                <div className="space-y-2">
                    <Label htmlFor="widget_position">{__('Widget Position', 'aisk-ai-chat-for-fluentcart')}</Label>
                    <Select
                        value={settings.chatwidget.widget_position}
                        onValueChange={(value) => updateSettings('chatwidget', 'widget_position', value)}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder={__('Select position', 'aisk-ai-chat-for-fluentcart')} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="bottom-right">{__('Bottom Right', 'aisk-ai-chat-for-fluentcart')}</SelectItem>
                            <SelectItem value="bottom-left">{__('Bottom Left', 'aisk-ai-chat-for-fluentcart')}</SelectItem>
                            <SelectItem value="top-right">{__('Top Right', 'aisk-ai-chat-for-fluentcart')}</SelectItem>
                            <SelectItem value="top-left">{__('Top Left', 'aisk-ai-chat-for-fluentcart')}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

            </CardContent>
        </Card>
    );
};

export default ChatWidgetSettings;