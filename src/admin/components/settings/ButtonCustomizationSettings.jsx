import React, { useState, useEffect, useRef } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { __ } from '@wordpress/i18n';
import { Heart, Star, Bookmark, ShoppingCart, X } from 'lucide-react';
import { Sketch } from '@uiw/react-color';

const ButtonCustomizationSettings = ({ settings, updateSettings }) => {
    const wishlistSettings = settings.wishlist || {};
    const buttonCustomization = wishlistSettings.button_customization || {};
    
    // New structure: general, product_page, product_listing
    const general = buttonCustomization.general || { textColor: '', font: 'default', fontSize: '12px' };
    const productPage = buttonCustomization.product_page || {
        backgroundColor: '#ebe9eb',
        backgroundHoverColor: '#dad8da',
        buttonTextColor: '#515151',
        buttonTextHoverColor: '#686868',
        textColor: '#007acc',
        textHoverColor: '#686868',
        font: 'default',
        fontSize: '16px',
        iconSize: '16px',
        borderRadius: '3px'
    };
    const productListing = buttonCustomization.product_listing || {
        backgroundColor: '#ebe9eb',
        backgroundHoverColor: '#dad8da',
        buttonTextColor: '#515151',
        buttonTextHoverColor: '#515151',
        textColor: '#007acc',
        textHoverColor: '#686868',
        font: 'default',
        fontSize: '16px',
        iconSize: '16px',
        borderRadius: '3px'
    };
    
    // Keep existing icon and labels
    const icon = buttonCustomization.icon || { type: 'predefined', value: 'heart', customUrl: '' };
    const labels = buttonCustomization.labels || { add: '', saved: '' };

    // State for color pickers
    const [selectedColorPicker, setSelectedColorPicker] = useState(null);

    // Font options
    const fontOptions = [
        { value: 'default', label: __('Use Default Font', 'wish-cart') },
        { value: 'Arial', label: 'Arial' },
        { value: 'Helvetica', label: 'Helvetica' },
        { value: 'Times New Roman', label: 'Times New Roman' },
        { value: 'Georgia', label: 'Georgia' },
        { value: 'Verdana', label: 'Verdana' },
        { value: 'Courier New', label: 'Courier New' },
        { value: 'Tahoma', label: 'Tahoma' },
        { value: 'Trebuchet MS', label: 'Trebuchet MS' },
        { value: 'Comic Sans MS', label: 'Comic Sans MS' },
        { value: 'Impact', label: 'Impact' },
        { value: 'Lucida Console', label: 'Lucida Console' },
    ];

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

    // Color input component with picker
    const ColorInput = ({ label, value, onChange, colorPickerId }) => {
        const isPickerOpen = selectedColorPicker === colorPickerId;
        const currentColor = value || '#ffffff';
        const pickerContainerRef = useRef(null);

        // Close picker when clicking outside
        useEffect(() => {
            const handleClickOutside = (event) => {
                if (pickerContainerRef.current && !pickerContainerRef.current.contains(event.target)) {
                    if (isPickerOpen) {
                        setSelectedColorPicker(null);
                    }
                }
            };

            if (isPickerOpen) {
                document.addEventListener('mousedown', handleClickOutside);
            }

            return () => {
                document.removeEventListener('mousedown', handleClickOutside);
            };
        }, [isPickerOpen]);

        return (
            <div className="space-y-2">
                <Label className="text-sm">{label}</Label>
                <div className="flex items-center gap-2">
                    <div className="relative" ref={pickerContainerRef}>
                        <div
                            className="w-10 h-10 rounded border-2 border-gray-200 cursor-pointer"
                            style={{ backgroundColor: currentColor }}
                            onClick={() => setSelectedColorPicker(isPickerOpen ? null : colorPickerId)}
                        />
                        {isPickerOpen && (
                            <div className="absolute z-50 mt-2">
                                <Sketch
                                    color={currentColor}
                                    onChange={(color) => {
                                        onChange(color.hex);
                                    }}
                                />
                            </div>
                        )}
                    </div>
                    <Input
                        type="text"
                        value={currentColor}
                        onChange={(e) => onChange(e.target.value)}
                        placeholder="#ffffff"
                        className="flex-1 font-mono text-sm"
                    />
                </div>
            </div>
        );
    };

    // Button section component (reusable for product_page and product_listing)
    const ButtonSection = ({ title, sectionKey, settings: sectionSettings }) => {
        return (
            <div className="space-y-4 border-t pt-6">
                <Label className="text-base font-semibold">{title}</Label>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <ColorInput
                        label={__('Background Color', 'wish-cart')}
                        value={sectionSettings.backgroundColor}
                        onChange={(value) => updateButtonCustomization(sectionKey, 'backgroundColor', value)}
                        colorPickerId={`${sectionKey}-bg`}
                    />
                    <ColorInput
                        label={__('Background Hover Color', 'wish-cart')}
                        value={sectionSettings.backgroundHoverColor}
                        onChange={(value) => updateButtonCustomization(sectionKey, 'backgroundHoverColor', value)}
                        colorPickerId={`${sectionKey}-bg-hover`}
                    />
                    <ColorInput
                        label={__('Button Text Color', 'wish-cart')}
                        value={sectionSettings.buttonTextColor}
                        onChange={(value) => updateButtonCustomization(sectionKey, 'buttonTextColor', value)}
                        colorPickerId={`${sectionKey}-btn-text`}
                    />
                    <ColorInput
                        label={__('Button Text Hover Color', 'wish-cart')}
                        value={sectionSettings.buttonTextHoverColor}
                        onChange={(value) => updateButtonCustomization(sectionKey, 'buttonTextHoverColor', value)}
                        colorPickerId={`${sectionKey}-btn-text-hover`}
                    />
                    <ColorInput
                        label={__('Text Color', 'wish-cart')}
                        value={sectionSettings.textColor}
                        onChange={(value) => updateButtonCustomization(sectionKey, 'textColor', value)}
                        colorPickerId={`${sectionKey}-text`}
                    />
                    <ColorInput
                        label={__('Text Hover Color', 'wish-cart')}
                        value={sectionSettings.textHoverColor}
                        onChange={(value) => updateButtonCustomization(sectionKey, 'textHoverColor', value)}
                        colorPickerId={`${sectionKey}-text-hover`}
                    />
                    <div className="space-y-2">
                        <Label className="text-sm">{__('Font', 'wish-cart')}</Label>
                        <Select
                            value={sectionSettings.font || 'default'}
                            onValueChange={(value) => updateButtonCustomization(sectionKey, 'font', value)}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder={__('Select font', 'wish-cart')} />
                            </SelectTrigger>
                            <SelectContent>
                                {fontOptions.map((font) => (
                                    <SelectItem key={font.value} value={font.value}>
                                        {font.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-2">
                        <Label className="text-sm">{__('Font Size', 'wish-cart')}</Label>
                        <Input
                            type="text"
                            value={sectionSettings.fontSize || ''}
                            onChange={(e) => updateButtonCustomization(sectionKey, 'fontSize', e.target.value)}
                            placeholder="16px"
                        />
                    </div>
                    <div className="space-y-2">
                        <Label className="text-sm">{__('Icon Size', 'wish-cart')}</Label>
                        <Input
                            type="text"
                            value={sectionSettings.iconSize || ''}
                            onChange={(e) => updateButtonCustomization(sectionKey, 'iconSize', e.target.value)}
                            placeholder="16px"
                        />
                    </div>
                    <div className="space-y-2">
                        <Label className="text-sm">{__('Border Radius', 'wish-cart')}</Label>
                        <Input
                            type="text"
                            value={sectionSettings.borderRadius || ''}
                            onChange={(e) => updateButtonCustomization(sectionKey, 'borderRadius', e.target.value)}
                            placeholder="3px"
                        />
                    </div>
                </div>
            </div>
        );
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
                    {/* General Settings Section */}
                            <div className="space-y-4">
                        <Label className="text-base font-semibold">{__('General Settings', 'wish-cart')}</Label>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <ColorInput
                                label={__('Text Color', 'wish-cart')}
                                value={general.textColor}
                                onChange={(value) => updateButtonCustomization('general', 'textColor', value)}
                                colorPickerId="general-text"
                            />
                                <div className="space-y-2">
                                <Label className="text-sm">{__('Font', 'wish-cart')}</Label>
                                <Select
                                    value={general.font || 'default'}
                                    onValueChange={(value) => updateButtonCustomization('general', 'font', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder={__('Select font', 'wish-cart')} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {fontOptions.map((font) => (
                                            <SelectItem key={font.value} value={font.value}>
                                                {font.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                                    </div>
                            <div className="space-y-2">
                                <Label className="text-sm">{__('Select Font Size', 'wish-cart')}</Label>
                                                    <Input
                                                        type="text"
                                    value={general.fontSize || ''}
                                    onChange={(e) => updateButtonCustomization('general', 'fontSize', e.target.value)}
                                    placeholder="12px"
                                />
                            </div>
                        </div>
                    </div>

                    {/* Product Page Button Section */}
                    <ButtonSection
                        title={__('"Add To Wishlist" Product Page Button', 'wish-cart')}
                        sectionKey="product_page"
                        settings={productPage}
                    />

                    {/* Product Listing Button Section */}
                    <ButtonSection
                        title={__('"Add To Wishlist" Product Listing Button', 'wish-cart')}
                        sectionKey="product_listing"
                        settings={productListing}
                    />

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
