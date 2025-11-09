import React, { useState, useEffect, useRef } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { Checkbox } from "@/components/ui/checkbox";
import { Textarea } from "@/components/ui/textarea";
import { MultiSelect } from "@/components/ui/multi-select";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { __ } from '@wordpress/i18n';

// Import the separated components
import UrlProcessing from '@/components/aiconfig/UrlProcessing';
import PdfProcessing from '@/components/aiconfig/PdfProcessing';

const AiConfigSettings = ({ settings, updateSettings }) => {

    const [processing, setProcessing] = useState(false);
    const [progress, setProgress] = useState(0);
    const [processMessage, setProcessMessage] = useState('');
    const [unprocessedCount, setUnprocessedCount] = useState(0);
    const [cleanupStatus, setCleanupStatus] = useState('');
    const [posts, setPosts] = useState([]);
    const [pages, setPages] = useState([]);
    const [products, setProducts] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState('');
    const [isFluentCartActive, setIsFluentCartActive] = useState(false);
    const [isCleaningUp, setIsCleaningUp] = useState(false);
    const [successMessage, setSuccessMessage] = useState(null);
    
    // Track original values for contact info and custom content
    const [originalContactInfo, setOriginalContactInfo] = useState('');
    const [originalCustomContent, setOriginalCustomContent] = useState('');
    const [hasSettingsChanges, setHasSettingsChanges] = useState(false);
    
    // Use ref to track if original values have been initialized
    const hasInitialized = useRef(false);

    // Initialize REST URL and nonce
    const restUrl = window?.wpApiSettings?.root || window?.WishCartData?.restUrl || '/wp-json/';
    const nonce = window?.wpApiSettings?.nonce || window?.WishCartData?.nonce;

    const checkFluentCartStatus = async () => {
        try {
            const response = await fetch(`${restUrl}wishcart/v1/check-fluentcart`, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                credentials: 'same-origin'
            });

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    setIsFluentCartActive(!!data.isActive);
                }
            }
        } catch (error) {
            console.error('Error checking FluentCart status:', error);
        }
    };

    useEffect(() => {
        if (!nonce) {
            setError(__('Authentication token missing. Please refresh the page.', 'wish-cart'));
            return;
        }

        if (!restUrl) {
            setError(__('API endpoint not configured. Please refresh the page.', 'wish-cart'));
            return;
        }

        loadUnprocessedCount();
        loadPostsAndPages();

        // Check if FluentCart is active using the global variable
        setIsFluentCartActive(!!window?.WishCartSettings?.isFluentCartActive);
        
        // Also check status from server to ensure it's up to date
        checkFluentCartStatus();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Set original values when settings are loaded (only once)
    useEffect(() => {
        if (!hasInitialized.current && settings.ai_config) {
            console.log('Initializing original values:', {
                contact_info: settings.ai_config.contact_info,
                custom_content: settings.ai_config.custom_content
            });
            setOriginalContactInfo(settings.ai_config.contact_info || '');
            setOriginalCustomContent(settings.ai_config.custom_content || '');
            hasInitialized.current = true;
        }
    }, [settings.ai_config]);

    // Track changes in contact info and custom content
    useEffect(() => {
        if (hasInitialized.current) {
            const contactChanged = settings.ai_config.contact_info !== originalContactInfo;
            const contentChanged = settings.ai_config.custom_content !== originalCustomContent;
            
            console.log('Change detection:', {
                contactChanged,
                contentChanged,
                currentContact: settings.ai_config.contact_info,
                originalContact: originalContactInfo,
                currentContent: settings.ai_config.custom_content,
                originalContent: originalCustomContent
            });
            
            if (contactChanged || contentChanged) {
                setHasSettingsChanges(true);
            } else {
                setHasSettingsChanges(false);
            }
        }
    }, [settings.ai_config.contact_info, settings.ai_config.custom_content, originalContactInfo, originalCustomContent]);

    const loadUnprocessedCount = async () => {

        try {
            if (!nonce) {
                throw new Error(__('Authentication token missing. Please refresh the page.', 'wish-cart'));
            }

            if (!restUrl) {
                throw new Error(__('API endpoint not configured. Please refresh the page.', 'wish-cart'));
            }


            const response = await fetch(`${restUrl}wishcart/v1/get-unprocessed-count`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                    'X-WP-Nonce-Valid': 'true'
                },
                credentials: 'same-origin'
            });

            // Check if we got redirected to login page
            const responseText = await response.text();
            if (responseText.includes('wp-login.php') || responseText.includes('login')) {
                throw new Error(__('Authentication required. Please refresh the page and try again.', 'wish-cart'));
            }

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            // Try to parse as JSON
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                throw new Error(__('Invalid response format from server', 'wish-cart'));
            }

            if (data && typeof data === 'object') {
                if ('success' in data && 'count' in data) {
                    setUnprocessedCount(data.count);
                } else if (data.message === __('No content available to process', 'wish-cart')) {
                    setUnprocessedCount(0);
                } else {
                    throw new Error(__('Invalid response structure from server', 'wish-cart'));
                }
            } else {
                throw new Error(__('Invalid response data from server', 'wish-cart'));
            }
        } catch (error) {
            setError(error.message || __('Failed to load unprocessed count. Please try again.', 'wish-cart'));
            setUnprocessedCount(0);
        }
    };

    const loadPostsAndPages = async () => {
        setIsLoading(true);
        setError('');
        try {
            // Separate promises for posts, pages, and products
            const postsPromise = fetch(`${restUrl}wp/v2/posts?per_page=100`, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                credentials: 'same-origin'
            });

            const pagesPromise = fetch(`${restUrl}wp/v2/pages?per_page=100`, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                credentials: 'same-origin'
            });

            // Handle posts and pages separately from products
            const [postsResponse, pagesResponse] = await Promise.all([postsPromise, pagesPromise]);
            const postsData = await postsResponse.json();
            const pagesData = await pagesResponse.json();

            // Set posts and pages regardless of FluentCart
            setPosts(postsData.map(post => ({
                value: post.id.toString(),
                label: post.title.rendered,
                type: 'post'
            })));

            setPages(pagesData.map(page => ({
                value: page.id.toString(),
                label: page.title.rendered,
                type: 'page'
            })));

                    // Only try to load products if FluentCart is active
                    if (window?.WishCartSettings?.isFluentCartActive) {
                try {
                    const productsResponse = await fetch(`${restUrl}wishcart/v1/products`, {
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': nonce
                        },
                        credentials: 'same-origin'
                    });

                    if (!productsResponse.ok) {
                        throw new Error('Failed to fetch products');
                    }

                    const productsData = await productsResponse.json();
                    setProducts(productsData.map(product => ({
                        value: product.id.toString(),
                        label: product.name,
                        type: 'product'
                    })));
                } catch (error) {
                    console.error('Error loading FluentCart products:', error);
                    // Don't set main error for FluentCart issues
                }
            }

            // Only show no content error if both posts and pages are empty
            if (!postsData.length && !pagesData.length) {
                setError(__('No posts or pages found. Please create some content first.', 'wish-cart'));
            }
        } catch (error) {
            console.error('Error loading content:', error);
            setError(__('Failed to load posts and pages. Please try again.', 'wish-cart'));
        } finally {
            setIsLoading(false);
        }
    };

    const processContent = async () => {

        try {
            setProcessing(true);
            setError(null);
            setSuccessMessage(null);

            if (!nonce) {
                throw new Error(__('Authentication token missing. Please refresh the page.', 'wish-cart'));
            }

            if (!restUrl) {
                throw new Error(__('API endpoint not configured. Please refresh the page.', 'wish-cart'));
            }

            // If there are settings changes, update the original values first
            if (hasSettingsChanges) {
                console.log('Resetting settings changes after processing');
                setOriginalContactInfo(settings.ai_config.contact_info || '');
                setOriginalCustomContent(settings.ai_config.custom_content || '');
                setHasSettingsChanges(false);
            }

            const response = await fetch(`${restUrl}wishcart/v1/process-content`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                    'X-WP-Nonce-Valid': 'true'
                },
                credentials: 'same-origin'
            });

            // Log response details for debugging
            console.log('Response status:', response.status);
            console.log('Response headers:', Object.fromEntries(response.headers.entries()));

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Error response:', errorText);
                throw new Error(`Server returned status ${response.status}: ${errorText}`);
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Invalid content type:', contentType);
                console.error('Response text:', text);
                throw new Error(`Invalid content type: ${contentType}. Expected application/json`);
            }

            const data = await response.json();
            console.log('Processed data:', data);

            if (data.success) {
                let message = __('Successfully processed content', 'wish-cart');
                if (data.processed > 0) {
                    message += `: ${data.processed} ${__('items processed', 'wish-cart')}`;
                    if (data.total > 0) {
                        message += `, ${data.total} ${__('remaining', 'wish-cart')}`;
                    }
                } else {
                    message += `: ${__('No items to process', 'wish-cart')}`;
                }

                setSuccessMessage(message);

                if (data.errors && data.errors.length > 0) {
                    console.warn('Processing completed with errors:', data.errors);
                    // Show errors in a non-blocking way
                    data.errors.forEach(error => {
                        console.error('Processing error:', error);
                    });
                }

                // Refresh unprocessed count after processing
                await loadUnprocessedCount();
            } else {
                throw new Error(data.message || __('Failed to process content', 'wish-cart'));
            }
        } catch (error) {
            console.error('Error processing content:', error);
            setError(error.message || __('Error processing content', 'wish-cart'));
        } finally {
            setProcessing(false);
        }
    };

    // Function to handle content exclusion updates with cleanup
    const handleExclusionUpdate = async (section, field, selected) => {
        // First update the settings in state
        updateSettings(section, field, selected);

        // Then clean up the excluded content from embeddings
        await cleanupExcludedEmbeddings();
    };

    // Function to clean up excluded content embeddings
    const cleanupExcludedEmbeddings = async () => {
        setIsCleaningUp(true);
        setCleanupStatus(__('Removing embeddings for excluded content...', 'wish-cart'));

        try {
            const response = await fetch('/wp-json/wishcart/v1/cleanup-excluded-embeddings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                }
            });

            const data = await response.json();

            if (data.success) {
                setCleanupStatus(data.message || __('Successfully removed embeddings for excluded content.', 'wish-cart'));
                // Refresh unprocessed count after cleanup
                await loadUnprocessedCount();
            } else {
                setCleanupStatus(`${__('Error:', 'wish-cart')} ${data.message || __('Failed to remove embeddings for excluded content.', 'wish-cart')}`);
            }
        } catch (error) {
            console.error('Error cleaning up excluded embeddings:', error);
            setCleanupStatus(`${__('Error:', 'wish-cart')} ${error.message || __('Failed to remove embeddings for excluded content.', 'wish-cart')}`);
        } finally {
            // Clear status message after a delay
            setTimeout(() => {
                setCleanupStatus('');
                setIsCleaningUp(false);
            }, 3000);
        }
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__('AI Configuration', 'wish-cart')}</CardTitle>
                <CardDescription>
                    {__('Configure AI settings and process your content for the chatbot', 'wish-cart')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                {/* FluentCart Integration */}
                <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                        <Label>{__('FluentCart Integration', 'wish-cart')}</Label>
                        <p className="text-sm text-gray-500">
                            {__('Enable AI processing for FluentCart products', 'wish-cart')}
                        </p>
                        <div className="flex items-center gap-4">
                            <Switch
                                checked={settings.ai_config.fluentcart_enabled}
                                disabled={!isFluentCartActive}
                                onCheckedChange={(checked) => {
                                    updateSettings('ai_config', 'fluentcart_enabled', checked);
                                }}
                            />
                            {!isFluentCartActive && (
                                <>
                                    <Button
                                        variant="link"
                                        size="sm"
                                        className="text-blue-500 hover:text-blue-700 p-0"
                                        onClick={async () => {
                                            setProcessing(true);
                                            setProcessMessage(__('Installing FluentCart...', 'wish-cart'));

                                            try {
                                                const response = await fetch('/wp-json/wishcart/v1/install-fluentcart', {
                                                    method: 'POST',
                                                    headers: {
                                                        'Content-Type': 'application/json',
                                                        'X-WP-Nonce': nonce
                                                    }
                                                });

                                                const data = await response.json();

                                                // Check for WordPress REST API error format
                                                if (!response.ok || data.code) {
                                                    // Handle WordPress REST API error format
                                                    const errorMessage = data.message || data.code || __('Failed to install FluentCart', 'wish-cart');
                                                    setProcessMessage(errorMessage);
                                                    setTimeout(() => {
                                                        setProcessing(false);
                                                        setProcessMessage('');
                                                    }, 8000); // Show error longer for manual instructions
                                                    return;
                                                }

                                                if (data.success) {
                                                    // Refresh the status from server after installation
                                                    await checkFluentCartStatus();
                                                    
                                                    setProcessMessage(__('FluentCart installed and activated successfully!', 'wish-cart'));
                                                    setTimeout(() => {
                                                        setProcessing(false);
                                                        setProcessMessage('');
                                                    }, 2000);
                                                } else {
                                                    // Handle error response
                                                    const errorMessage = data.message || __('Failed to install FluentCart', 'wish-cart');
                                                    setProcessMessage(errorMessage);
                                                    setTimeout(() => {
                                                        setProcessing(false);
                                                        setProcessMessage('');
                                                    }, 8000); // Show error longer for manual instructions
                                                }
                                            } catch (error) {
                                                console.error('Error installing FluentCart:', error);
                                                let errorMessage = __('Error installing FluentCart. Please check your connection and try again.', 'wish-cart');
                                                
                                                // Try to get error message from response if available
                                                if (error.message) {
                                                    errorMessage = error.message;
                                                }
                                                
                                                setProcessMessage(errorMessage);
                                                setTimeout(() => {
                                                    setProcessing(false);
                                                    setProcessMessage('');
                                                }, 5000);
                                            }
                                        }}
                                    >
                                        {__('Install & Activate FluentCart', 'wish-cart')}
                                    </Button>
                                    <Button
                                        variant="link"
                                        size="sm"
                                        className="text-gray-500 hover:text-gray-700 p-0"
                                        onClick={async () => {
                                            setProcessMessage(__('Checking FluentCart status...', 'wish-cart'));
                                            await checkFluentCartStatus();
                                            setTimeout(() => {
                                                setProcessMessage('');
                                            }, 1500);
                                        }}
                                        title={__('Refresh FluentCart detection status', 'wish-cart')}
                                    >
                                        {__('Refresh', 'wish-cart')}
                                    </Button>
                                </>
                            )}
                            {processMessage && (
                                <p className={`text-sm ${processMessage.includes('Error') || processMessage.includes('not found') || processMessage.includes('not available') || processMessage.includes('manually') ? 'text-red-500' : 'text-green-500'}`}>{processMessage}</p>
                            )}
                        </div>
                    </div>
                </div>

                {settings.ai_config.fluentcart_enabled && isFluentCartActive && (
                    <div>
                        <Label className="mb-2 block">{__('Excluded Products', 'wish-cart')}</Label>
                        <MultiSelect
                            options={products}
                            selected={settings.ai_config.excluded_products || []}
                            onChange={(selected) => handleExclusionUpdate('ai_config', 'excluded_products', selected)}
                            placeholder={isLoading ?
                                __('Loading products...', 'wish-cart') :
                                products.length ?
                                    settings.ai_config.excluded_products?.length ?
                                        '' :
                                        __('Select products to exclude...', 'wish-cart') :
                                    __('No products found', 'wish-cart')
                            }
                            disabled={isLoading || !products.length || isCleaningUp}
                            type="product"
                        />
                    </div>
                )}

                {/* Content Types */}
                <div className="space-y-3">
                    <Label>{__('Content Types', 'wish-cart')}</Label>
                    <div className="space-y-2">
                        <div className="flex items-center space-x-2">
                            <Checkbox
                                id="posts"
                                checked={settings.ai_config.included_post_types.includes('post')}
                                onCheckedChange={(checked) => {
                                    const types = checked
                                        ? [...settings.ai_config.included_post_types, 'post']
                                        : settings.ai_config.included_post_types.filter(t => t !== 'post');
                                    updateSettings('ai_config', 'included_post_types', types);
                                }}
                            />
                            <label htmlFor="posts" className="text-sm font-medium">{__('Posts', 'wish-cart')}</label>
                        </div>
                        <div className="flex items-center space-x-2">
                            <Checkbox
                                id="pages"
                                checked={settings.ai_config.included_post_types.includes('page')}
                                onCheckedChange={(checked) => {
                                    const types = checked
                                        ? [...settings.ai_config.included_post_types, 'page']
                                        : settings.ai_config.included_post_types.filter(t => t !== 'page');
                                    updateSettings('ai_config', 'included_post_types', types);
                                }}
                            />
                            <label htmlFor="pages" className="text-sm font-medium">{__('Pages', 'wish-cart')}</label>
                        </div>
                    </div>
                </div>

                <div className="space-y-3">
                    {error ? (
                        <Alert>
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    ) : (
                        <div className="space-y-4">
                            {settings.ai_config.included_post_types.includes('post') && (
                                <div>
                                    <Label className="mb-2 block">{__('Excluded Posts', 'wish-cart')}</Label>
                                    <MultiSelect
                                        options={posts}
                                        selected={settings.ai_config.excluded_posts || []}
                                        onChange={(selected) => handleExclusionUpdate('ai_config', 'excluded_posts', selected)}
                                        placeholder={isLoading ?
                                            __('Loading posts...', 'wish-cart') :
                                            posts.length ?
                                                settings.ai_config.excluded_posts?.length ?
                                                    '' :
                                                    __('Select posts to exclude...', 'wish-cart') :
                                                __('No posts found', 'wish-cart')
                                        }
                                        disabled={isLoading || !posts.length || isCleaningUp}
                                        type="post"
                                    />
                                </div>
                            )}
                            {settings.ai_config.included_post_types.includes('page') && (
                                <div>
                                    <Label className="mb-2 block">{__('Excluded Pages', 'wish-cart')}</Label>
                                    <MultiSelect
                                        options={pages}
                                        selected={settings.ai_config.excluded_pages || []}
                                        onChange={(selected) => handleExclusionUpdate('ai_config', 'excluded_pages', selected)}
                                        placeholder={isLoading ?
                                            __('Loading pages...', 'wish-cart') :
                                            pages.length ?
                                                settings.ai_config.excluded_pages?.length ?
                                                    '' :
                                                    __('Select pages to exclude...', 'wish-cart') :
                                                __('No pages found', 'wish-cart')
                                        }
                                        disabled={isLoading || !pages.length || isCleaningUp}
                                        type="page"
                                    />
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* External Sources Section - Using the separated UrlProcessing component */}
                <UrlProcessing settings={settings} updateSettings={updateSettings} />

                {/* PDF Sources Section - Using the separated PdfProcessing component */}
                <PdfProcessing
                    settings={settings}
                    updateSettings={updateSettings}
                    isActiveConfigTab={true}
                />

                {/* Contact Information */}
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <Label htmlFor="contact_info">{__('Contact Information', 'wish-cart')}</Label>
                        {settings.ai_config.contact_info !== originalContactInfo && (
                            <span className="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full">
                                {__('Modified', 'wish-cart')}
                            </span>
                        )}
                    </div>
                    <Textarea
                        id="contact_info"
                        value={settings.ai_config.contact_info || ''}
                        onChange={(e) => updateSettings('ai_config', 'contact_info', e.target.value)}
                        placeholder={__(
                            'Store Hours: Mon-Fri 9am-5pm\n' +
                            'Phone: (555) 123-4567\n' +
                            'Email: support@example.com\n' +
                            'Address: 123 Main St, City, State 12345\n' +
                            'Support Team: John (Sales), Mary (Technical Support)',
                            'wish-cart'
                        )}
                        className={`h-40 ${settings.ai_config.contact_info !== originalContactInfo ? 'border-blue-300 bg-blue-50' : ''}`}
                    />
                    <p className="text-sm text-muted-foreground text-gray-500">
                        {__('Add contact information that the chatbot can share when users ask about contacting support or visiting your store.', 'wish-cart')}
                    </p>
                </div>

                {/* Custom Content */}
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <Label htmlFor="custom_content">{__('Custom Content', 'wish-cart')}</Label>
                        {settings.ai_config.custom_content !== originalCustomContent && (
                            <span className="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full">
                                {__('Modified', 'wish-cart')}
                            </span>
                        )}
                    </div>
                    <Textarea
                        id="custom_content"
                        value={settings.ai_config.custom_content || ''}
                        onChange={(e) => updateSettings('ai_config', 'custom_content', e.target.value)}
                        placeholder={__(
                            'Return Policy: Items can be returned within 30 days of purchase with original receipt.\n' +
                            'Shipping Information: Free shipping on orders over $50. Standard shipping takes 3-5 business days.\n' +
                            'Store Locations: Downtown Store (123 Main St), Mall Store (456 Shopping Ave)',
                            'wish-cart'
                        )}
                        className={`h-60 ${settings.ai_config.custom_content !== originalCustomContent ? 'border-blue-300 bg-blue-50' : ''}`}
                    />
                    <p className="text-sm text-muted-foreground text-gray-500">
                        {__('Add any additional information that you want the chatbot to know about your business, policies, or services.', 'wish-cart')}
                    </p>
                </div>

                {/* Batch Size */}
                <div className="space-y-2">
                    <Label htmlFor="batch_size">{__('Processing Batch Size', 'wish-cart')}</Label>
                    <Input
                        id="batch_size"
                        type="number"
                        min="1"
                        max="50"
                        value={settings.ai_config.batch_size}
                        onChange={(e) => updateSettings('ai_config', 'batch_size', parseInt(e.target.value))}
                    />
                    <p className="text-sm text-gray-500">
                        {__('Number of items to process in each batch', 'wish-cart')}
                    </p>
                </div>

                {/* Knowledge Base Processing */}
                <div className="space-y-4 pt-4 border-t">
                    <div className="flex justify-between items-center">
                        <div>
                            <h4 className="text-sm font-medium">{__('Knowledge Base Processing', 'wish-cart')}</h4>
                            <p className="text-sm text-gray-500">
                                {__('Unprocessed items:', 'wish-cart')} {unprocessedCount + (hasSettingsChanges ? 1 : 0)}
                                {hasSettingsChanges && (
                                    <span className="text-blue-600 ml-1">
                                        ({__('includes Contact Information & Custom Content changes', 'wish-cart')})
                                    </span>
                                )}
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={cleanupExcludedEmbeddings}
                                disabled={processing || isCleaningUp}
                            >
                                {isCleaningUp ? __('Cleaning...', 'wish-cart') : __('Clean Excluded Content', 'wish-cart')}
                            </Button>

                            <Button
                                onClick={processContent}
                                disabled={processing || isCleaningUp}
                            >
                                {processing ? __('Processing...', 'wish-cart') : __('Generate Embeddings', 'wish-cart')}
                            </Button>
                        </div>
                    </div>

                    {(processing || processMessage || isCleaningUp || cleanupStatus) && (
                        <div className="space-y-2">
                            {processing && <Progress value={progress} />}
                            {processMessage && (
                                <p className={`text-sm ${processMessage.includes('Error') ? 'text-red-500' : 'text-green-500'}`}>
                                    {processMessage}
                                </p>
                            )}
                            {cleanupStatus && (
                                <p className={`text-sm ${cleanupStatus.includes('Error') ? 'text-red-500' : 'text-green-500'}`}>
                                    {cleanupStatus}
                                </p>
                            )}
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
};

export default AiConfigSettings;