import React, { useState, useMemo } from 'react';
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { __ } from '@wordpress/i18n';
import * as LucideIcons from 'lucide-react';

// Curated list of popular icons from lucide-react (no duplicates)
const iconList = [
    'Heart', 'HeartOff', 'Star', 'Bookmark', 'BookmarkCheck', 'ShoppingCart', 'ShoppingBag',
    'Plus', 'PlusCircle', 'Minus', 'Check', 'CheckCircle', 'X', 'XCircle', 'Trash', 'Trash2',
    'Edit', 'Edit2', 'Save', 'Download', 'Upload', 'Share', 'Share2', 'Copy', 'CopyCheck',
    'Search', 'Filter', 'Settings', 'User', 'Users', 'UserPlus', 'Mail', 'Phone', 'Message',
    'Home', 'Menu', 'MoreVertical', 'MoreHorizontal', 'ArrowRight', 'ArrowLeft', 'ArrowUp', 'ArrowDown',
    'ChevronRight', 'ChevronLeft', 'ChevronUp', 'ChevronDown', 'Bell', 'BellOff', 'Lock', 'Unlock',
    'Eye', 'EyeOff', 'Image', 'File', 'Folder', 'FolderOpen', 'Link', 'ExternalLink', 'Calendar', 'Clock',
    'Tag', 'Tags', 'Flag', 'AlertCircle', 'AlertTriangle', 'Info', 'HelpCircle', 'Zap', 'Sun',
    'Moon', 'Cloud', 'CloudRain', 'Gift', 'Award', 'Trophy', 'Crown', 'Diamond', 'Gem',
    'Music', 'Video', 'Camera', 'Film', 'Play', 'Pause', 'SkipForward', 'SkipBack', 'FastForward',
    'Rewind', 'Volume', 'Volume2', 'VolumeX', 'Mic', 'Headphones', 'Headset', 'Radio', 'Tv',
    'Monitor', 'Laptop', 'Smartphone', 'Tablet', 'Watch', 'Gamepad', 'Puzzle', 'Dice', 'Cards',
    'Palette', 'Brush', 'Pen', 'Pencil', 'Highlighter', 'Eraser', 'Scissors', 'Ruler', 'Compass',
    'Map', 'MapPin', 'Navigation', 'Car', 'Bike', 'Plane', 'Train', 'Ship', 'Rocket',
    'Battery', 'BatteryCharging', 'Plug', 'Wifi', 'WifiOff', 'Bluetooth', 'Signal', 'SignalLow',
    'SignalMedium', 'SignalHigh', 'CreditCard', 'Wallet', 'DollarSign', 'Euro', 'Pound', 'Yen',
    'Bitcoin', 'TrendingUp', 'TrendingDown', 'BarChart', 'LineChart', 'PieChart', 'Activity',
    'Target', 'Crosshair', 'Focus', 'Maximize', 'Minimize', 'Maximize2', 'Minimize2', 'Move',
    'RotateCw', 'RotateCcw', 'RefreshCw', 'RefreshCcw', 'Repeat', 'Shuffle', 'Square', 'Circle',
    'Triangle', 'Hexagon', 'Octagon', 'Pentagon', 'Rectangle', 'Ellipse', 'Line', 'Curve', 'Path',
    'Shape', 'Layers', 'Grid', 'Layout', 'Columns', 'Rows', 'Sidebar', 'Panel', 'Window',
    'Box', 'Package', 'Archive', 'FileText', 'FileCode', 'FileImage', 'FileVideo', 'FileAudio',
    'FileSpreadsheet', 'Database', 'Server', 'HardDrive', 'Cpu', 'MemoryStick', 'Printer', 'Scanner',
    'Fax', 'Mouse', 'Keyboard', 'Webcam', 'Speaker', 'Microphone'
];

// Remove duplicates and filter to only icons that exist in lucide-react
const getAvailableIcons = () => {
    const uniqueIcons = [...new Set(iconList)];
    return uniqueIcons.filter(iconName => {
        // Check if icon exists in LucideIcons
        return LucideIcons[iconName] !== undefined;
    });
};

const IconPicker = ({ selectedIcon, onSelect, label, triggerLabel }) => {
    const [isOpen, setIsOpen] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');

    // Get available icons (filtered to only those that exist)
    const availableIcons = useMemo(() => getAvailableIcons(), []);

    // Filter icons based on search query
    const filteredIcons = useMemo(() => {
        if (!searchQuery) return availableIcons;
        const query = searchQuery.toLowerCase();
        return availableIcons.filter(iconName => 
            iconName.toLowerCase().includes(query)
        );
    }, [searchQuery, availableIcons]);

    const handleIconSelect = (iconName) => {
        onSelect(iconName);
        setIsOpen(false);
        setSearchQuery('');
    };

    const SelectedIconComponent = selectedIcon && LucideIcons[selectedIcon] 
        ? LucideIcons[selectedIcon] 
        : null;

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger asChild>
                <Button type="button" variant="outline" className="w-full justify-start">
                    {SelectedIconComponent ? (
                        <>
                            <SelectedIconComponent className="w-4 h-4 mr-2" />
                            {selectedIcon}
                        </>
                    ) : (
                        triggerLabel || __('Select Icon', 'wish-cart')
                    )}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-4xl max-h-[80vh] overflow-hidden flex flex-col">
                <DialogHeader>
                    <DialogTitle>{label || __('Select Icon', 'wish-cart')}</DialogTitle>
                </DialogHeader>
                <div className="flex flex-col gap-4 flex-1 overflow-hidden">
                    <div className="relative">
                        <Input
                            type="text"
                            placeholder={__('Search icons...', 'wish-cart')}
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full"
                        />
                    </div>
                    <div className="flex-1 overflow-y-auto border rounded-lg p-4">
                        <div className="grid grid-cols-6 sm:grid-cols-8 md:grid-cols-10 gap-3">
                            {filteredIcons.map((iconName) => {
                                const IconComponent = LucideIcons[iconName];
                                if (!IconComponent) return null;

                                const isSelected = selectedIcon === iconName;

                                return (
                                    <button
                                        key={iconName}
                                        type="button"
                                        onClick={() => handleIconSelect(iconName)}
                                        className={`
                                            p-3 border-2 rounded-lg transition-all
                                            flex flex-col items-center justify-center gap-2
                                            hover:border-primary hover:bg-primary/5
                                            ${isSelected 
                                                ? 'border-primary bg-primary/10' 
                                                : 'border-gray-200'
                                            }
                                        `}
                                        title={iconName}
                                    >
                                        <IconComponent className="w-5 h-5" />
                                        <span className="text-xs text-center truncate w-full">
                                            {iconName}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        {filteredIcons.length === 0 && (
                            <div className="text-center py-8 text-muted-foreground">
                                {__('No icons found', 'wish-cart')}
                            </div>
                        )}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
};

export default IconPicker;

