import React from 'react';
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
                    {/* Colors Section */}
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <Label className="text-base font-semibold">{__('Colors', 'wish-cart')}</Label>
                        </div>

                        {/* Background Color */}
                        <div className="space-y-2">
                            <Label htmlFor="color_background">{__('Background Color', 'wish-cart')}</Label>
                            <div className="flex items-start gap-4">
                                <Sketch
                                    style={{ maxWidth: '250px' }}
                                    color={colors.background || '#ffffff'}
                                    onChange={(color) => updateButtonCustomization('colors', 'background', color.hex)}
                                    presetColors={[
                                        '#ffffff', '#f3f4f6', '#e5e7eb', '#d1d5db', '#9ca3af',
                                        '#6b7280', '#374151', '#1f2937', '#111827', '#000000',
                                    ]}
                                />
                                <div className="space-y-2">
                                    <Input
                                        type="text"
                                        value={colors.background || '#ffffff'}
                                        onChange={(e) => updateButtonCustomization('colors', 'background', e.target.value)}
                                        placeholder="#ffffff"
                                        className="w-32"
                                    />
                                    <div
                                        className="w-32 h-8 rounded border"
                                        style={{ backgroundColor: colors.background || '#ffffff' }}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Text Color */}
                        <div className="space-y-2">
                            <Label htmlFor="color_text">{__('Text Color', 'wish-cart')}</Label>
                            <div className="flex items-start gap-4">
                                <Sketch
                                    style={{ maxWidth: '250px' }}
                                    color={colors.text || '#374151'}
                                    onChange={(color) => updateButtonCustomization('colors', 'text', color.hex)}
                                    presetColors={[
                                        '#ffffff', '#f3f4f6', '#e5e7eb', '#d1d5db', '#9ca3af',
                                        '#6b7280', '#374151', '#1f2937', '#111827', '#000000',
                                    ]}
                                />
                                <div className="space-y-2">
                                    <Input
                                        type="text"
                                        value={colors.text || '#374151'}
                                        onChange={(e) => updateButtonCustomization('colors', 'text', e.target.value)}
                                        placeholder="#374151"
                                        className="w-32"
                                    />
                                    <div
                                        className="w-32 h-8 rounded border"
                                        style={{ backgroundColor: colors.text || '#374151' }}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Border Color */}
                        <div className="space-y-2">
                            <Label htmlFor="color_border">{__('Border Color', 'wish-cart')}</Label>
                            <div className="flex items-start gap-4">
                                <Sketch
                                    style={{ maxWidth: '250px' }}
                                    color={colors.border || 'rgba(107, 114, 128, 0.3)'}
                                    onChange={(color) => updateButtonCustomization('colors', 'border', color.hex)}
                                    presetColors={[
                                        '#ffffff', '#f3f4f6', '#e5e7eb', '#d1d5db', '#9ca3af',
                                        '#6b7280', '#374151', '#1f2937', '#111827', '#000000',
                                    ]}
                                />
                                <div className="space-y-2">
                                    <Input
                                        type="text"
                                        value={colors.border || 'rgba(107, 114, 128, 0.3)'}
                                        onChange={(e) => updateButtonCustomization('colors', 'border', e.target.value)}
                                        placeholder="rgba(107, 114, 128, 0.3)"
                                        className="w-32"
                                    />
                                    <div
                                        className="w-32 h-8 rounded border"
                                        style={{ backgroundColor: colors.border || 'rgba(107, 114, 128, 0.3)' }}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Hover Background Color */}
                        <div className="space-y-2">
                            <Label htmlFor="color_hover_background">{__('Hover Background Color', 'wish-cart')}</Label>
                            <div className="flex items-start gap-4">
                                <Sketch
                                    style={{ maxWidth: '250px' }}
                                    color={colors.hoverBackground || '#f3f4f6'}
                                    onChange={(color) => updateButtonCustomization('colors', 'hoverBackground', color.hex)}
                                    presetColors={[
                                        '#ffffff', '#f3f4f6', '#e5e7eb', '#d1d5db', '#9ca3af',
                                        '#6b7280', '#374151', '#1f2937', '#111827', '#000000',
                                    ]}
                                />
                                <div className="space-y-2">
                                    <Input
                                        type="text"
                                        value={colors.hoverBackground || '#f3f4f6'}
                                        onChange={(e) => updateButtonCustomization('colors', 'hoverBackground', e.target.value)}
                                        placeholder="#f3f4f6"
                                        className="w-32"
                                    />
                                    <div
                                        className="w-32 h-8 rounded border"
                                        style={{ backgroundColor: colors.hoverBackground || '#f3f4f6' }}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Hover Text Color */}
                        <div className="space-y-2">
                            <Label htmlFor="color_hover_text">{__('Hover Text Color', 'wish-cart')}</Label>
                            <div className="flex items-start gap-4">
                                <Sketch
                                    style={{ maxWidth: '250px' }}
                                    color={colors.hoverText || '#374151'}
                                    onChange={(color) => updateButtonCustomization('colors', 'hoverText', color.hex)}
                                    presetColors={[
                                        '#ffffff', '#f3f4f6', '#e5e7eb', '#d1d5db', '#9ca3af',
                                        '#6b7280', '#374151', '#1f2937', '#111827', '#000000',
                                    ]}
                                />
                                <div className="space-y-2">
                                    <Input
                                        type="text"
                                        value={colors.hoverText || '#374151'}
                                        onChange={(e) => updateButtonCustomization('colors', 'hoverText', e.target.value)}
                                        placeholder="#374151"
                                        className="w-32"
                                    />
                                    <div
                                        className="w-32 h-8 rounded border"
                                        style={{ backgroundColor: colors.hoverText || '#374151' }}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Active Background Color */}
                        <div className="space-y-2">
                            <Label htmlFor="color_active_background">{__('Active Background Color', 'wish-cart')}</Label>
                            <div className="flex items-start gap-4">
                                <Sketch
                                    style={{ maxWidth: '250px' }}
                                    color={colors.activeBackground || '#fef2f2'}
                                    onChange={(color) => updateButtonCustomization('colors', 'activeBackground', color.hex)}
                                    presetColors={[
                                        '#fef2f2', '#fee2e2', '#fecaca', '#fca5a5', '#ef4444',
                                        '#dc2626', '#b91c1c', '#991b1b', '#7f1d1d', '#ffffff',
                                    ]}
                                />
                                <div className="space-y-2">
                                    <Input
                                        type="text"
                                        value={colors.activeBackground || '#fef2f2'}
                                        onChange={(e) => updateButtonCustomization('colors', 'activeBackground', e.target.value)}
                                        placeholder="#fef2f2"
                                        className="w-32"
                                    />
                                    <div
                                        className="w-32 h-8 rounded border"
                                        style={{ backgroundColor: colors.activeBackground || '#fef2f2' }}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Active Text Color */}
                        <div className="space-y-2">
                            <Label htmlFor="color_active_text">{__('Active Text Color', 'wish-cart')}</Label>
                            <div className="flex items-start gap-4">
                                <Sketch
                                    style={{ maxWidth: '250px' }}
                                    color={colors.activeText || '#991b1b'}
                                    onChange={(color) => updateButtonCustomization('colors', 'activeText', color.hex)}
                                    presetColors={[
                                        '#fef2f2', '#fee2e2', '#fecaca', '#fca5a5', '#ef4444',
                                        '#dc2626', '#b91c1c', '#991b1b', '#7f1d1d', '#ffffff',
                                    ]}
                                />
                                <div className="space-y-2">
                                    <Input
                                        type="text"
                                        value={colors.activeText || '#991b1b'}
                                        onChange={(e) => updateButtonCustomization('colors', 'activeText', e.target.value)}
                                        placeholder="#991b1b"
                                        className="w-32"
                                    />
                                    <div
                                        className="w-32 h-8 rounded border"
                                        style={{ backgroundColor: colors.activeText || '#991b1b' }}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Active Border Color */}
                        <div className="space-y-2">
                            <Label htmlFor="color_active_border">{__('Active Border Color', 'wish-cart')}</Label>
                            <div className="flex items-start gap-4">
                                <Sketch
                                    style={{ maxWidth: '250px' }}
                                    color={colors.activeBorder || 'rgba(220, 38, 38, 0.4)'}
                                    onChange={(color) => updateButtonCustomization('colors', 'activeBorder', color.hex)}
                                    presetColors={[
                                        '#fef2f2', '#fee2e2', '#fecaca', '#fca5a5', '#ef4444',
                                        '#dc2626', '#b91c1c', '#991b1b', '#7f1d1d', '#ffffff',
                                    ]}
                                />
                                <div className="space-y-2">
                                    <Input
                                        type="text"
                                        value={colors.activeBorder || 'rgba(220, 38, 38, 0.4)'}
                                        onChange={(e) => updateButtonCustomization('colors', 'activeBorder', e.target.value)}
                                        placeholder="rgba(220, 38, 38, 0.4)"
                                        className="w-32"
                                    />
                                    <div
                                        className="w-32 h-8 rounded border"
                                        style={{ backgroundColor: colors.activeBorder || 'rgba(220, 38, 38, 0.4)' }}
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Focus Border Color */}
                        <div className="space-y-2">
                            <Label htmlFor="color_focus_border">{__('Focus Border Color', 'wish-cart')}</Label>
                            <div className="flex items-start gap-4">
                                <Sketch
                                    style={{ maxWidth: '250px' }}
                                    color={colors.focusBorder || '#3b82f6'}
                                    onChange={(color) => updateButtonCustomization('colors', 'focusBorder', color.hex)}
                                    presetColors={[
                                        '#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6',
                                        '#2563eb', '#1d4ed8', '#1e40af', '#1e3a8a', '#172554',
                                    ]}
                                />
                                <div className="space-y-2">
                                    <Input
                                        type="text"
                                        value={colors.focusBorder || '#3b82f6'}
                                        onChange={(e) => updateButtonCustomization('colors', 'focusBorder', e.target.value)}
                                        placeholder="#3b82f6"
                                        className="w-32"
                                    />
                                    <div
                                        className="w-32 h-8 rounded border"
                                        style={{ backgroundColor: colors.focusBorder || '#3b82f6' }}
                                    />
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

