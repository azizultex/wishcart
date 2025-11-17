import React, { useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Button } from "@/components/ui/button";
import { __ } from '@wordpress/i18n';
import { Heart, Star, Bookmark, ShoppingCart, X } from 'lucide-react';
import { Sketch } from '@uiw/react-color';

const ButtonCustomizationSettings = ({ settings, updateSettings }) => {
    const wishlistSettings = settings.wishlist || {};
    const buttonCustomization = wishlistSettings.button_customization || {};
    const colors = buttonCustomization.colors || {};
    const icon = buttonCustomization.icon || { type: 'predefined', value: 'heart', customUrl: '' };
    const labels = buttonCustomization.labels || { add: '', saved: '' };

    // State to track which color option is currently being edited
    const [selectedColorKey, setSelectedColorKey] = useState('background');

    const updateButtonCustomization = (section, key, value) => {
        const currentCustomization = buttonCustomization || {};
        const currentSection = currentCustomization[section] || {};
        
        updateSettings('wishlist', 'button_customization', {
            ...currentCustomization,
            [section]: {
                ...currentSection,
                [key]: value,
            },
        });
    };

    const handleMediaUpload = (field) => {
        const mediaUploader = wp.media({
            title: __('Select Icon', 'wish-cart'),
            button: {
                text: __('Use this icon', 'wish-cart')
            },
            multiple: false
        });

        mediaUploader.on('select', function () {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            updateButtonCustomization('icon', 'customUrl', attachment.url);
            updateButtonCustomization('icon', 'type', 'custom');
        });

        mediaUploader.open();
    };

    const iconOptions = [
        { value: 'heart', label: __('Heart', 'wish-cart'), component: Heart },
        { value: 'star', label: __('Star', 'wish-cart'), component: Star },
        { value: 'bookmark', label: __('Bookmark', 'wish-cart'), component: Bookmark },
    ];

    // Define all color options with their labels and default values
    const colorOptions = [
        { key: 'background', label: __('Background Color', 'wish-cart'), default: '#ffffff', group: 'basic' },
        { key: 'text', label: __('Text Color', 'wish-cart'), default: '#374151', group: 'basic' },
        { key: 'border', label: __('Border Color', 'wish-cart'), default: 'rgba(107, 114, 128, 0.3)', group: 'basic' },
        { key: 'hoverBackground', label: __('Hover Background Color', 'wish-cart'), default: '#f3f4f6', group: 'hover' },
        { key: 'hoverText', label: __('Hover Text Color', 'wish-cart'), default: '#374151', group: 'hover' },
        { key: 'activeBackground', label: __('Active Background Color', 'wish-cart'), default: '#fef2f2', group: 'active' },
        { key: 'activeText', label: __('Active Text Color', 'wish-cart'), default: '#991b1b', group: 'active' },
        { key: 'activeBorder', label: __('Active Border Color', 'wish-cart'), default: 'rgba(220, 38, 38, 0.4)', group: 'active' },
        { key: 'focusBorder', label: __('Focus Border Color', 'wish-cart'), default: '#3b82f6', group: 'focus' },
    ];

    const getCurrentColor = (key) => {
        return colors[key] || colorOptions.find(opt => opt.key === key)?.default || '#ffffff';
    };

    const handleColorChange = (color) => {
        updateButtonCustomization('colors', selectedColorKey, color.hex);
    };

    const handleHexInputChange = (key, value) => {
        updateButtonCustomization('colors', key, value);
        if (key === selectedColorKey) {
            // Update selected color if this is the currently selected one
        }
    };

    const groupedColors = {
        basic: colorOptions.filter(opt => opt.group === 'basic'),
        hover: colorOptions.filter(opt => opt.group === 'hover'),
        active: colorOptions.filter(opt => opt.group === 'active'),
        focus: colorOptions.filter(opt => opt.group === 'focus'),
    };

    return (
        <div className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <ShoppingCart className="w-5 h-5" />
                        {__('Button Customization', 'wish-cart')}
                    </CardTitle>
                    <CardDescription>
                        {__('Customize the appearance of the wishlist button', 'wish-cart')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                    {/* Colors Section - Two Column Layout */}
                    <div className="space-y-6">
                        <div className="flex items-center justify-between">
                            <Label className="text-base font-semibold">{__('Colors', 'wish-cart')}</Label>
                        </div>

                        <div className="space-y-4 max-w-2xl">
                            {/* Color Options List */}
                            <div className="space-y-4">
                                {/* Basic Colors */}
                                <div className="space-y-2">
                                    <Label className="text-sm font-medium text-muted-foreground">{__('Basic Colors', 'wish-cart')}</Label>
                                    {groupedColors.basic.map((option) => {
                                        const currentColor = getCurrentColor(option.key);
                                        const isSelected = selectedColorKey === option.key;
                                        return (
                                            <div
                                                key={option.key}
                                                onClick={() => setSelectedColorKey(option.key)}
                                                className={`p-3 rounded-lg border-2 cursor-pointer transition-all ${
                                                    isSelected
                                                        ? 'border-primary bg-primary/5 shadow-sm'
                                                        : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between gap-3">
                                                    <div className="flex items-center gap-3 flex-1 min-w-0">
                                                        <div
                                                            className="w-8 h-8 rounded border-2 border-gray-200 flex-shrink-0"
                                                            style={{ backgroundColor: currentColor }}
                                                        />
                                                        <Label className="text-sm font-medium cursor-pointer flex-shrink-0">
                                                            {option.label}
                                                        </Label>
                                                    </div>
                                                </div>
                                                <div className="mt-2">
                                                    <Input
                                                        type="text"
                                                        value={currentColor}
                                                        onChange={(e) => handleHexInputChange(option.key, e.target.value)}
                                                        onClick={(e) => e.stopPropagation()}
                                                        placeholder={option.default}
                                                        className="h-8 text-xs font-mono"
                                                    />
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                {/* Hover Colors */}
                                <div className="space-y-2 pt-2 border-t">
                                    <Label className="text-sm font-medium text-muted-foreground">{__('Hover Colors', 'wish-cart')}</Label>
                                    {groupedColors.hover.map((option) => {
                                        const currentColor = getCurrentColor(option.key);
                                        const isSelected = selectedColorKey === option.key;
                                        return (
                                            <div
                                                key={option.key}
                                                onClick={() => setSelectedColorKey(option.key)}
                                                className={`p-3 rounded-lg border-2 cursor-pointer transition-all ${
                                                    isSelected
                                                        ? 'border-primary bg-primary/5 shadow-sm'
                                                        : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between gap-3">
                                                    <div className="flex items-center gap-3 flex-1 min-w-0">
                                                        <div
                                                            className="w-8 h-8 rounded border-2 border-gray-200 flex-shrink-0"
                                                            style={{ backgroundColor: currentColor }}
                                                        />
                                                        <Label className="text-sm font-medium cursor-pointer flex-shrink-0">
                                                            {option.label}
                                                        </Label>
                                                    </div>
                                                </div>
                                                <div className="mt-2">
                                                    <Input
                                                        type="text"
                                                        value={currentColor}
                                                        onChange={(e) => handleHexInputChange(option.key, e.target.value)}
                                                        onClick={(e) => e.stopPropagation()}
                                                        placeholder={option.default}
                                                        className="h-8 text-xs font-mono"
                                                    />
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                {/* Active Colors */}
                                <div className="space-y-2 pt-2 border-t">
                                    <Label className="text-sm font-medium text-muted-foreground">{__('Active Colors', 'wish-cart')}</Label>
                                    {groupedColors.active.map((option) => {
                                        const currentColor = getCurrentColor(option.key);
                                        const isSelected = selectedColorKey === option.key;
                                        return (
                                            <div
                                                key={option.key}
                                                onClick={() => setSelectedColorKey(option.key)}
                                                className={`p-3 rounded-lg border-2 cursor-pointer transition-all ${
                                                    isSelected
                                                        ? 'border-primary bg-primary/5 shadow-sm'
                                                        : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between gap-3">
                                                    <div className="flex items-center gap-3 flex-1 min-w-0">
                                                        <div
                                                            className="w-8 h-8 rounded border-2 border-gray-200 flex-shrink-0"
                                                            style={{ backgroundColor: currentColor }}
                                                        />
                                                        <Label className="text-sm font-medium cursor-pointer flex-shrink-0">
                                                            {option.label}
                                                        </Label>
                                                    </div>
                                                </div>
                                                <div className="mt-2">
                                                    <Input
                                                        type="text"
                                                        value={currentColor}
                                                        onChange={(e) => handleHexInputChange(option.key, e.target.value)}
                                                        onClick={(e) => e.stopPropagation()}
                                                        placeholder={option.default}
                                                        className="h-8 text-xs font-mono"
                                                    />
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                {/* Focus Colors */}
                                <div className="space-y-2 pt-2 border-t">
                                    <Label className="text-sm font-medium text-muted-foreground">{__('Focus Colors', 'wish-cart')}</Label>
                                    {groupedColors.focus.map((option) => {
                                        const currentColor = getCurrentColor(option.key);
                                        const isSelected = selectedColorKey === option.key;
                                        return (
                                            <div
                                                key={option.key}
                                                onClick={() => setSelectedColorKey(option.key)}
                                                className={`p-3 rounded-lg border-2 cursor-pointer transition-all ${
                                                    isSelected
                                                        ? 'border-primary bg-primary/5 shadow-sm'
                                                        : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between gap-3">
                                                    <div className="flex items-center gap-3 flex-1 min-w-0">
                                                        <div
                                                            className="w-8 h-8 rounded border-2 border-gray-200 flex-shrink-0"
                                                            style={{ backgroundColor: currentColor }}
                                                        />
                                                        <Label className="text-sm font-medium cursor-pointer flex-shrink-0">
                                                            {option.label}
                                                        </Label>
                                                    </div>
                                                </div>
                                                <div className="mt-2">
                                                    <Input
                                                        type="text"
                                                        value={currentColor}
                                                        onChange={(e) => handleHexInputChange(option.key, e.target.value)}
                                                        onClick={(e) => e.stopPropagation()}
                                                        placeholder={option.default}
                                                        className="h-8 text-xs font-mono"
                                                    />
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Icon Section */}
                    <div className="space-y-4 border-t pt-4">
                        <Label className="text-base font-semibold">{__('Icon', 'wish-cart')}</Label>
                        
                        <RadioGroup
                            value={icon.type || 'predefined'}
                            onValueChange={(value) => updateButtonCustomization('icon', 'type', value)}
                        >
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="predefined" id="icon_predefined" />
                                <Label htmlFor="icon_predefined" className="cursor-pointer">
                                    {__('Predefined Icon', 'wish-cart')}
                                </Label>
                            </div>
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="custom" id="icon_custom" />
                                <Label htmlFor="icon_custom" className="cursor-pointer">
                                    {__('Custom Icon', 'wish-cart')}
                                </Label>
                            </div>
                        </RadioGroup>

                        {icon.type === 'predefined' ? (
                            <div className="space-y-2">
                                <Label>{__('Select Icon', 'wish-cart')}</Label>
                                <div className="flex gap-4">
                                    {iconOptions.map((option) => {
                                        const IconComponent = option.component;
                                        return (
                                            <button
                                                key={option.value}
                                                type="button"
                                                onClick={() => updateButtonCustomization('icon', 'value', option.value)}
                                                className={`p-3 border-2 rounded-lg transition-colors ${
                                                    icon.value === option.value
                                                        ? 'border-primary bg-primary/10'
                                                        : 'border-gray-200 hover:border-gray-300'
                                                }`}
                                            >
                                                <IconComponent className="w-6 h-6" />
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <Label>{__('Custom Icon', 'wish-cart')}</Label>
                                <div className="flex items-center gap-4">
                                    {icon.customUrl && (
                                        <div className="relative">
                                            <img
                                                src={icon.customUrl}
                                                alt={__('Custom icon', 'wish-cart')}
                                                className="w-12 h-12 object-contain border rounded"
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="absolute -top-2 -right-2 h-6 w-6 p-0"
                                                onClick={() => updateButtonCustomization('icon', 'customUrl', '')}
                                            >
                                                <X className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    )}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => handleMediaUpload('icon')}
                                    >
                                        {icon.customUrl ? __('Change Icon', 'wish-cart') : __('Upload Icon', 'wish-cart')}
                                    </Button>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {__('Upload a custom icon image (SVG, PNG, or JPG recommended)', 'wish-cart')}
                                </p>
                            </div>
                        )}
                    </div>

                    {/* Labels Section */}
                    <div className="space-y-4 border-t pt-4">
                        <Label className="text-base font-semibold">{__('Button Labels', 'wish-cart')}</Label>
                        
                        <div className="space-y-2">
                            <Label htmlFor="label_add">{__('"Add to Wishlist" Text', 'wish-cart')}</Label>
                            <Input
                                id="label_add"
                                type="text"
                                value={labels.add || ''}
                                onChange={(e) => updateButtonCustomization('labels', 'add', e.target.value)}
                                placeholder={__('Add to Wishlist', 'wish-cart')}
                            />
                            <p className="text-sm text-muted-foreground">
                                {__('Text displayed when product is not in wishlist', 'wish-cart')}
                            </p>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="label_saved">{__('"Saved to Wishlist" Text', 'wish-cart')}</Label>
                            <Input
                                id="label_saved"
                                type="text"
                                value={labels.saved || ''}
                                onChange={(e) => updateButtonCustomization('labels', 'saved', e.target.value)}
                                placeholder={__('Saved to Wishlist', 'wish-cart')}
                            />
                            <p className="text-sm text-muted-foreground">
                                {__('Text displayed when product is in wishlist', 'wish-cart')}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
};

export default ButtonCustomizationSettings;

