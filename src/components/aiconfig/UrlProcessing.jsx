// Modified implementation for URL Processing with polling mechanism

import React, { useState, useEffect, useCallback } from 'react';
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from "@/components/ui/dialog";
import { EyeIcon, RefreshCwIcon, PlusIcon, XIcon, PencilIcon } from "lucide-react";
import { __ } from '@wordpress/i18n';

const UrlProcessing = ({ settings, updateSettings }) => {
    const [processMessage, setProcessMessage] = useState('');
    const [userNotice, setUserNotice] = useState('');
    const [showUserNotice, setShowUserNotice] = useState(false);
    const [showProcessMessage, setShowProcessMessage] = useState(false);
    const [viewingUrls, setViewingUrls] = useState({});
    const [loadingUrls, setLoadingUrls] = useState({});
    const [processingUrls, setProcessingUrls] = useState({});

    // Polling mechanism for background processes
    const [pollingActive, setPollingActive] = useState(false);
    const [urlsToCheck, setUrlsToCheck] = useState([]);

    // URL processing states
    const [openUrlDialog, setOpenUrlDialog] = useState(false);
    const [urlProcessing, setUrlProcessing] = useState(false);
    const [urlProgress, setUrlProgress] = useState(0);
    const [newUrlConfig, setNewUrlConfig] = useState({
        url: '',
        follow_links: false,
        include_paths: '',
        exclude_paths: '',
        include_selectors: '',
        exclude_selectors: ''
    });

    // Advanced options toggle
    const [showAdvancedOptions, setShowAdvancedOptions] = useState(false);

    // URL viewing dialog states
    const [openDialog, setOpenDialog] = useState(false);
    const [selectedUrlData, setSelectedUrlData] = useState({ url: '', urls: [] });

    // Editing states
    const [isEditing, setIsEditing] = useState(false);
    const [editingIndex, setEditingIndex] = useState(null);

    // Poll for status updates on URLs that are processing
    useEffect(() => {
        // Check if there are any URLs with 'processing' status
        const processingUrls = settings.ai_config.website_urls?.filter(url => url.status === 'processing') || [];

        if (processingUrls.length > 0) {
            setUrlsToCheck(processingUrls.map(url => url.url));
            setPollingActive(true);
        } else {
            setPollingActive(false);
        }
    }, [settings.ai_config.website_urls]);

    // Polling effect
    useEffect(() => {
        let pollTimer;
        let isPolling = false;

        const pollStatus = async () => {
            if (!pollingActive || urlsToCheck.length === 0 || isPolling) return;

            isPolling = true; // Prevent concurrent polling

            try {
                // Make an API call to check status
                const response = await fetch('/wp-json/aisk/v1/check-url-status', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': AiskData.nonce
                    },
                    body: JSON.stringify({ urls: urlsToCheck })
                }).catch(() => null);

                if (!response || !response.ok) {
                    // Manual check for each URL
                    const currentUrlsToCheck = [...urlsToCheck]; // Create a stable copy

                    for (const url of currentUrlsToCheck) {
                        if (!url) continue; // Skip invalid URLs

                        const urlResponse = await fetch('/wp-json/aisk/v1/get-crawled-urls', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': AiskData.nonce
                            },
                            body: JSON.stringify({ parent_url: url })
                        }).catch(() => null);

                        if (urlResponse && urlResponse.ok) {
                            const data = await urlResponse.json();

                            // If we have crawled URLs, processing is complete
                            if (data.success && data.urls && data.urls.length > 0) {
                                updateUrlStatus(url, 'processed');
                            }
                        }
                    }
                } else {
                    // Handle response from check-url-status endpoint
                    const data = await response.json();
                    if (data.success && data.statuses) {
                        const statusEntries = Object.entries(data.statuses);
                        for (const [url, statusObj] of statusEntries) {
                            // Support both string and object status
                            let status = typeof statusObj === 'string' ? statusObj : statusObj.status;
                            let userMessage = typeof statusObj === 'object' && statusObj.user_message ? statusObj.user_message : null;
                            let errorDetails = typeof statusObj === 'object' && statusObj.error ? statusObj.error : null;

                            // Handle bot protection status
                            if (
                                (typeof statusObj === 'object' && statusObj.error_type === 'bot_protection') ||
                                (userMessage && userMessage.toLowerCase().includes('protected against automated access'))
                            ) {
                                status = 'bot_protected';
                                userMessage = 'This URL is protected against automated access. Please try a different URL or contact the website administrator.';
                            }

                            // Update status and display message
                            updateUrlStatus(url, status);
                            if (userMessage) {
                                setUserNotice(userMessage);
                                setShowUserNotice(true);
                                clearMessages();
                            }
                            // Clear any existing messages first
                            const clearMessages = () => {
                                setProcessMessage('');
                                setShowProcessMessage(false);
                            };

                            if (status === 'processed') {
                                updateUrlStatus(url, 'processed');
                                clearMessages();
                            } else if (status === 'failed' || status === 'error' || userMessage) {
                                // Check for bot protection first
                                if (errorDetails && typeof errorDetails === 'string' &&
                                    (errorDetails.toLowerCase().includes('bot protection') ||
                                        errorDetails.toLowerCase().includes('captcha') ||
                                        errorDetails.toLowerCase().includes('automated access'))) {
                                    updateUrlStatus(url, 'blocked');
                                    setUserNotice(__('Bot protection detected. This website is protected against automated access. Please try accessing the content manually.', 'aisk-ai-chat-for-fluentcart'));
                                    setShowUserNotice(true);
                                    clearMessages();
                                } else {
                                    // Handle other errors
                                    updateUrlStatus(url, 'error');
                                    if (userMessage) {
                                        setUserNotice(userMessage);
                                        setShowUserNotice(true);
                                        clearMessages();
                                    } else if (errorDetails) {
                                        setUserNotice(__('Error processing URL: ', 'aisk-ai-chat-for-fluentcart') + errorDetails);
                                        setShowUserNotice(true);
                                        clearMessages();
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (error) {
                console.error('Error polling URL status:', error);
            } finally {
                isPolling = false;

                // Continue polling if there are still URLs to check
                if (pollingActive && urlsToCheck.length > 0) {
                    pollTimer = setTimeout(pollStatus, 5000);
                }
            }
        };

        // Start polling
        if (pollingActive && urlsToCheck.length > 0 && !isPolling) {
            pollTimer = setTimeout(pollStatus, 1000); // Start after 1s delay
        }

        return () => {
            clearTimeout(pollTimer);
        };
    }, [pollingActive, urlsToCheck, settings]);

    // Helper method to update URL status
    const updateUrlStatus = (url, newStatus) => {
        if (!url || !newStatus) return;

        // Create a stable copy of the current settings
        const currentSettings = JSON.parse(JSON.stringify(settings));
        const updatedUrls = [...(currentSettings.ai_config.website_urls || [])];
        const urlIndex = updatedUrls.findIndex(item => item.url === url);

        if (urlIndex !== -1) {
            // Only update if status is different
            if (updatedUrls[urlIndex].status !== newStatus) {
                // Create a new object instead of mutating
                updatedUrls[urlIndex] = {
                    ...updatedUrls[urlIndex],
                    status: newStatus
                };

                // Create a new settings object
                const newSettings = {
                    ...currentSettings,
                    ai_config: {
                        ...currentSettings.ai_config,
                        website_urls: updatedUrls
                    }
                };

                // Update local state with a new object
                updateSettings('ai_config', 'website_urls', updatedUrls);

                // Update URLs to check - using a functional update
                setUrlsToCheck(prev => prev.filter(u => u !== url));

                // Persist to server
                fetch('/wp-json/aisk/v1/settings', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': AiskData.nonce
                    },
                    body: JSON.stringify(newSettings)
                }).catch(error => {
                    console.error('Error saving updated URL status:', error);
                });
            }
        }

        if (newStatus === 'bot_protected') {
            setProcessMessage('');
            setShowProcessMessage(false);
        }
    };
    const addNewUrl = () => {
        setIsEditing(false);
        setEditingIndex(null);
        setNewUrlConfig({
            url: '',
            follow_links: false,
            include_paths: '',
            exclude_paths: '',
            include_selectors: '',
            exclude_selectors: ''
        });
        setShowAdvancedOptions(false);
        setOpenUrlDialog(true);
    };

    // Function to deduplicate URLs when viewing them in the dialog
    const viewUrlDetails = async (url, index) => {
        if (!url) return;

        try {
            setViewingUrls(prev => ({ ...prev, [index]: true }));

            const response = await fetch('/wp-json/aisk/v1/get-crawled-urls', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce
                },
                body: JSON.stringify({ parent_url: url })
            });

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Server response:', errorText);
                throw new Error(`Server returned ${response.status}: ${response.statusText}`);
            }

            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                console.error('Failed to parse response as JSON:', parseError);
                throw new Error('Invalid response from server (not JSON)');
            }

            if (data.success) {
                // Store debug info for inspection
                if (data.debug) {
                    console.log("Debug info:", data.debug);
                }

                // Deduplicate the URLs array before setting it in state
                const uniqueUrls = data.urls ? [...new Set(data.urls)] : [];

                setSelectedUrlData({
                    url,
                    urls: uniqueUrls,
                    debug: data.debug || null
                });
                setOpenDialog(true);

                // If we got URLs back, then the URL is processed
                if (uniqueUrls.length > 0) {
                    // Update status if it's still showing as processing
                    const urlConfig = settings.ai_config.website_urls[index];
                    if (urlConfig && urlConfig.status === 'processing') {
                        updateUrlStatus(url, 'processed');
                    }
                }
            } else {
                throw new Error(data.message || __('Failed to fetch URLs', 'aisk-ai-chat-for-fluentcart'));
            }
        } catch (error) {
            console.error('Error fetching crawled URLs:', error);
            setProcessMessage(__(`Error: ${error.message}`, 'aisk-ai-chat-for-fluentcart'));
        } finally {
            setViewingUrls(prev => ({ ...prev, [index]: false }));
        }
    };

    // Updated function to handle URL deletion from the view modal
    const handleDeleteUrl = async (index) => {
        const urlToDelete = selectedUrlData.urls[index];
        if (!urlToDelete) return;

        try {
            // Show processing state
            setProcessMessage(__('Deleting URL...', 'aisk-ai-chat-for-fluentcart'));

            const response = await fetch('/wp-json/aisk/v1/delete-url', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce,
                },
                body: JSON.stringify({
                    url: urlToDelete,
                    parent_url: selectedUrlData.url
                }),
            });

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Server response:', errorText);
                throw new Error(`Server returned ${response.status}: ${response.statusText}`);
            }

            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                console.error('Failed to parse response as JSON:', parseError);
                throw new Error('Invalid response from server (not JSON)');
            }

            if (data.success) {
                // Remove the deleted URL from the selectedUrlData
                const updatedUrls = [...selectedUrlData.urls];
                updatedUrls.splice(index, 1);

                // Update the state with the new list of URLs
                setSelectedUrlData({
                    ...selectedUrlData,
                    urls: updatedUrls
                });

                // Set success message
                setProcessMessage(__('URL deleted successfully.', 'aisk-ai-chat-for-fluentcart'));

                // If this was the last URL, remove the parent URL from the list and close the modal
                if (updatedUrls.length === 0) {
                    // Find the index of the parent URL in the website_urls array
                    const parentUrlIndex = settings.ai_config.website_urls.findIndex(
                        item => item.url === selectedUrlData.url
                    );

                    if (parentUrlIndex !== -1) {
                        // Remove the parent URL from the settings
                        const newWebsiteUrls = [...settings.ai_config.website_urls];
                        newWebsiteUrls.splice(parentUrlIndex, 1);

                        // Update the settings
                        updateSettings('ai_config', 'website_urls', newWebsiteUrls);

                        // Close the modal
                        setOpenDialog(false);

                        // Show success message in the main UI
                        setProcessMessage(__('URL and all related items removed successfully.', 'aisk-ai-chat-for-fluentcart'));

                        // Also persist to server
                        fetch('/wp-json/aisk/v1/settings', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': AiskData.nonce
                            },
                            body: JSON.stringify({
                                ...settings,
                                ai_config: {
                                    ...settings.ai_config,
                                    website_urls: newWebsiteUrls
                                }
                            })
                        }).catch(error => {
                            console.error('Error saving updated settings:', error);
                        });
                    }
                }

                // Reset message after a delay
                setTimeout(() => {
                    setProcessMessage('');
                }, 3000);
            } else {
                throw new Error(data.message || __('Failed to delete URL', 'aisk-ai-chat-for-fluentcart'));
            }
        } catch (error) {
            console.error('Error deleting URL:', error);
            setProcessMessage(__(`Error: ${error.message}`, 'aisk-ai-chat-for-fluentcart'));
        }
    };

    const processUrl = async () => {
        if (!newUrlConfig.url) return;

        try {
            const url = new URL(newUrlConfig.url);
            if (!url.protocol.startsWith('http')) {
                setProcessMessage(__('URL must start with http:// or https://', 'aisk-ai-chat-for-fluentcart'));
                setShowProcessMessage(true);
                return;
            }

            // Show processing state in the dialog immediately
            setUrlProcessing(true);
            setUrlProgress(10);

            // Create a stable copy of the current settings to avoid race conditions
            const currentSettings = JSON.parse(JSON.stringify(settings));
            const newUrls = [...(currentSettings.ai_config.website_urls || [])];

            // Create config with "processing" status
            const urlConfigToSave = {
                ...newUrlConfig,
                status: 'processing',
                last_crawled: new Date().toISOString(),
            };

            let urlIndex;
            if (isEditing && editingIndex !== null) {
                // Update existing URL
                urlIndex = editingIndex;
                newUrls[urlIndex] = urlConfigToSave;
            } else {
                // Add new URL
                newUrls.push(urlConfigToSave);
                urlIndex = newUrls.length - 1;
            }

            // Create a new settings object instead of mutating the existing one
            const updatedSettings = {
                ...currentSettings,
                ai_config: {
                    ...currentSettings.ai_config,
                    website_urls: newUrls
                }
            };

            // Update local state with the new settings object
            updateSettings('ai_config', 'website_urls', newUrls);

            // Add to URLs being checked - using a functional update to avoid stale state
            setUrlsToCheck(prev => {
                const filtered = prev.filter(u => u !== url.toString());
                return [...filtered, url.toString()];
            });

            // Set polling active - using a functional update
            setPollingActive(true);

            // Update progress to indicate we're saving settings
            setUrlProgress(30);

            // Save to WordPress database
            try {
                const saveResponse = await fetch('/wp-json/aisk/v1/settings', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': AiskData.nonce
                    },
                    body: JSON.stringify(updatedSettings)
                });

                if (!saveResponse.ok) {
                    console.error('Error saving URL to settings:', saveResponse.statusText);
                }
            } catch (saveError) {
                console.error('Error saving URL to settings:', saveError);
            }

            // Update progress after saving settings
            setUrlProgress(50);

            // Close the dialog after settings are saved but before processing begins
            setTimeout(() => {
                setOpenUrlDialog(false);
                // Show processing message in the main UI
                const processingUrls = (settings.ai_config.website_urls || []).filter(url => url.status === 'processing');
                const anyBotProtected = (settings.ai_config.website_urls || []).some(url => url.status === 'bot_protected');
                if (processingUrls.length > 0 && !anyBotProtected) {
                    setProcessMessage(__('Processing website in background...', 'aisk-ai-chat-for-fluentcart'));
                    setShowProcessMessage(true);
                } else if (anyBotProtected) {
                    setProcessMessage('');
                    setShowProcessMessage(false);
                }
            }, 1000);

            // Process URLs in the background - wrap in try/catch to prevent cascading errors
            let processResponse;
            try {
                processResponse = await fetch('/wp-json/aisk/v1/process-urls', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': AiskData.nonce
                    },
                    body: JSON.stringify({
                        website_url: url.toString(),
                        follow_links: newUrlConfig.follow_links || false,
                        include_paths: newUrlConfig.include_paths?.split(',').map(p => p.trim()).filter(Boolean) || [],
                        exclude_paths: newUrlConfig.exclude_paths?.split(',').map(p => p.trim()).filter(Boolean) || [],
                        include_selectors: newUrlConfig.include_selectors?.split(',').map(s => s.trim()).filter(Boolean) || [],
                        exclude_selectors: newUrlConfig.exclude_selectors?.split(',').map(s => s.trim()).filter(Boolean) || []
                    })
                });
            } catch (processError) {
                console.error('Error in process-urls fetch:', processError);
                processResponse = { status: 0, ok: false };
            }

            // Set progress to 100% regardless of response to finish the animation
            setUrlProgress(100);
            console.log(processResponse);
            // Handle different response scenarios
            if (!processResponse || processResponse.status === 504) {
                console.log('Server timeout occurred, but URL already saved. Status will be updated via polling.');
                setProcessMessage(__('Website is being processed in the background.', 'aisk-ai-chat-for-fluentcart'));
            }
            else if (!processResponse.ok) {
                const errorText = await processResponse.text().catch(() => 'Unknown error');
                let errorJson;
                try { errorJson = JSON.parse(errorText); } catch { }
                if (processResponse.status === 403 && errorJson?.code === 'bot_protected') {
                    setUserNotice(errorJson.message);
                    setShowUserNotice(true);
                    setProcessMessage('');
                    setShowProcessMessage(false);
                    updateUrlStatus(url.toString(), 'bot_protected');
                    setUrlProcessing(false);
                    setUrlProgress(0);
                    setOpenUrlDialog(false);
                    return;
                }
                throw new Error(`Server returned ${processResponse.status}: ${errorText}`);
            }
            else {
                // Try to parse the JSON response safely
                let data;
                try {
                    data = await processResponse.json();

                    if (data.success) {
                        updateUrlStatus(url.toString(), 'processed');
                        if (data.user_message) {
                            setUserNotice(data.user_message);
                            setShowUserNotice(true);
                        }
                        setProcessMessage(__(`Website processed successfully! ${data.processed || 0} pages indexed.`, 'aisk-ai-chat-for-fluentcart'));
                    } else {
                        throw new Error(data.message || __('Failed to process website', 'aisk-ai-chat-for-fluentcart'));
                    }
                } catch (parseError) {
                    console.error('Failed to parse response as JSON:', parseError);
                    throw new Error('Invalid response from server (not JSON)');
                }
            }

            // Reset states after a delay - using a single setTimeout to avoid multiple state updates
            setTimeout(() => {
                // Use a callback to ensure the latest state
                setIsEditing(false);
                setEditingIndex(null);
                setUrlProcessing(false);
                setUrlProgress(0);
                setProcessMessage('');
            }, 3000);

        } catch (error) {
            console.error('Error processing website:', error);

            // Close the dialog even on error
            setOpenUrlDialog(false);

            // Set error message
            setProcessMessage(__(`Error: ${error.message}`, 'aisk-ai-chat-for-fluentcart'));

            // Reset states
            setTimeout(() => {
                setUrlProcessing(false);
                setUrlProgress(0);
            }, 2000);
        }
    };


    // Update the resyncUrl function
    const resyncUrl = async (index) => {
        const urlConfig = settings.ai_config.website_urls[index];
        if (!urlConfig.url) return;

        try {
            const url = new URL(urlConfig.url);
            if (!url.protocol.startsWith('http')) {
                setProcessMessage(__('URL must start with http:// or https://', 'aisk-ai-chat-for-fluentcart'));
                return;
            }

            setLoadingUrls(prev => ({ ...prev, [index]: true }));
            setProcessMessage(__('Processing website...', 'aisk-ai-chat-for-fluentcart'));

            // First update the status to 'processing'
            const updatedUrls = [...settings.ai_config.website_urls];
            updatedUrls[index] = {
                ...updatedUrls[index],
                status: 'processing',
                last_crawled: new Date().toISOString(),
            };
            updateSettings('ai_config', 'website_urls', updatedUrls);

            // Add to URLs to monitor
            setUrlsToCheck(prev => [...prev.filter(u => u !== urlConfig.url), urlConfig.url]);
            setPollingActive(true);

            const response = await fetch('/wp-json/aisk/v1/process-urls', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce
                },
                body: JSON.stringify({
                    website_url: url.toString(),
                    follow_links: urlConfig.follow_links || false,
                    include_paths: urlConfig.include_paths?.split(',').map(p => p.trim()).filter(Boolean) || [],
                    exclude_paths: urlConfig.exclude_paths?.split(',').map(p => p.trim()).filter(Boolean) || [],
                    include_selectors: urlConfig.include_selectors?.split(',').map(s => s.trim()).filter(Boolean) || [],
                    exclude_selectors: urlConfig.exclude_selectors?.split(',').map(s => s.trim()).filter(Boolean) || []
                })
            });
            console.log('Response from server:', response);
            // Check if response is OK before trying to parse JSON
            if (!response.ok) {
                const errorText = await response.text();
                let errorJson;
                try { errorJson = JSON.parse(errorText); } catch { }
                if (response.status === 403 && errorJson?.code === 'bot_protected') {
                    setUserNotice(errorJson.message);
                    setShowUserNotice(true);

                    setShowProcessMessage(false);
                    updateUrlStatus(urlConfig.url, 'bot_protected');
                    setTimeout(() => {
                        setProcessMessage('');
                    }, 3000);

                    setLoadingUrls(prev => ({ ...prev, [index]: false }));
                    return;
                }
                throw new Error(`Server returned ${response.status}: ${response.statusText}`);
            }

            // Try to parse the JSON response safely
            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                console.error('Failed to parse response as JSON:', parseError);
                throw new Error('Invalid response from server (not JSON)');
            }

            if (data.success) {
                updateUrlStatus(url.toString(), 'processed');

                setProcessMessage(__(`Website processed successfully! ${data.processed || 0} pages indexed.`, 'aisk-ai-chat-for-fluentcart'));
            } else {
                throw new Error(data.message || __('Failed to process website', 'aisk-ai-chat-for-fluentcart'));
            }
        } catch (error) {
            console.error('Error processing website:', error);
            setProcessMessage(__(`Error: ${error.message}`, 'aisk-ai-chat-for-fluentcart'));

            // Update URL status to error
            const newUrls = [...settings.ai_config.website_urls];
            newUrls[index] = {
                ...newUrls[index],
                status: 'error',
                error_message: error.message
            };
            updateSettings('ai_config', 'website_urls', newUrls);
        } finally {
            setLoadingUrls(prev => ({ ...prev, [index]: false }));
            setTimeout(() => {
                setProcessMessage('');
            }, 3000);
        }
    };

    const editUrl = (index) => {
        setIsEditing(true);
        setEditingIndex(index);
        // Load the current URL configuration into the newUrlConfig state
        setNewUrlConfig({ ...settings.ai_config.website_urls[index] });

        // If any advanced options are set, show the advanced options panel
        const urlConfig = settings.ai_config.website_urls[index];
        if (urlConfig.include_selectors || urlConfig.exclude_selectors) {
            setShowAdvancedOptions(true);
        } else {
            setShowAdvancedOptions(false);
        }

        setOpenUrlDialog(true);
    };

    // const removeUrl = (index) => {
    //     const newUrls = [...(settings.ai_config.website_urls || [])];
    //     newUrls.splice(index, 1);
    //     updateSettings('ai_config', 'website_urls', newUrls);
    // };

    // Function to delete the parent URL and all its associated URLs
    const handleDeleteParentUrl = async () => {
        try {
            // Show processing state
            setProcessMessage(__('Deleting all related URLs...', 'aisk-ai-chat-for-fluentcart'));

            // Find the index of the parent URL in the website_urls array
            const parentUrlIndex = settings.ai_config.website_urls.findIndex(
                item => item.url === selectedUrlData.url
            );

            if (parentUrlIndex === -1) {
                throw new Error('Parent URL not found in settings');
            }

            const response = await fetch('/wp-json/aisk/v1/delete-url', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce,
                },
                body: JSON.stringify({
                    parent_url: selectedUrlData.url,
                    delete_all: true,
                    scope: 'parent_only' // Important: Only delete URLs for this parent
                }),
            });

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Server response:', errorText);
                throw new Error(`Server returned ${response.status}: ${response.statusText}`);
            }

            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                console.error('Failed to parse response as JSON:', parseError);
                throw new Error('Invalid response from server (not JSON)');
            }

            if (data.success) {
                // Remove the URL from the website_urls array in settings
                const newWebsiteUrls = [...settings.ai_config.website_urls];
                newWebsiteUrls.splice(parentUrlIndex, 1);
                updateSettings('ai_config', 'website_urls', newWebsiteUrls);

                // Close the modal
                setOpenDialog(false);

                // Show success message
                setProcessMessage(__(`Successfully removed URL and ${data.count || 0} related items.`, 'aisk-ai-chat-for-fluentcart'));

                // Also persist to server
                fetch('/wp-json/aisk/v1/settings', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': AiskData.nonce
                    },
                    body: JSON.stringify({
                        ...settings,
                        ai_config: {
                            ...settings.ai_config,
                            website_urls: newWebsiteUrls
                        }
                    })
                }).catch(error => {
                    console.error('Error saving updated settings:', error);
                });

                // Reset message after a delay
                setTimeout(() => {
                    setProcessMessage('');
                }, 3000);
            } else {
                throw new Error(data.message || __('Failed to delete URLs', 'aisk-ai-chat-for-fluentcart'));
            }
        } catch (error) {
            console.error('Error deleting URLs:', error);
            setProcessMessage(__(`Error: ${error.message}`, 'aisk-ai-chat-for-fluentcart'));
        }
    };

    // Function to delete all URLs associated with the current parent URL
    const handleDeleteAllUrls = async () => {
        try {
            // Show processing state
            setProcessMessage(__('Deleting all URLs for this source...', 'aisk-ai-chat-for-fluentcart'));

            // Find the index of the parent URL in the website_urls array
            const parentUrlIndex = settings.ai_config.website_urls.findIndex(
                item => item.url === selectedUrlData.url
            );

            if (parentUrlIndex === -1) {
                throw new Error('Parent URL not found in settings');
            }

            // Delete only URLs for this specific parent URL
            const response = await fetch('/wp-json/aisk/v1/delete-url', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce,
                },
                body: JSON.stringify({
                    parent_url: selectedUrlData.url,
                    delete_all: true,
                    scope: 'parent_only' // Important: Only delete URLs for this parent
                }),
            });

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Server response:', errorText);
                throw new Error(`Server returned ${response.status}: ${response.statusText}`);
            }

            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                console.error('Failed to parse response as JSON:', parseError);
                throw new Error('Invalid response from server (not JSON)');
            }

            if (data.success) {
                // Remove the parent URL from the settings
                const newWebsiteUrls = [...settings.ai_config.website_urls];
                newWebsiteUrls.splice(parentUrlIndex, 1);
                updateSettings('ai_config', 'website_urls', newWebsiteUrls);

                // Close the modal
                setOpenDialog(false);

                // Show success message
                setProcessMessage(__(`Successfully removed URL and ${data.count || 0} related items.`, 'aisk-ai-chat-for-fluentcart'));

                // Also persist to server
                fetch('/wp-json/aisk/v1/settings', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': AiskData.nonce
                    },
                    body: JSON.stringify({
                        ...settings,
                        ai_config: {
                            ...settings.ai_config,
                            website_urls: newWebsiteUrls
                        }
                    })
                }).catch(error => {
                    console.error('Error saving updated settings:', error);
                });

                // Reset message after a delay
                setTimeout(() => {
                    setProcessMessage('');
                }, 3000);
            } else {
                throw new Error(data.message || __('Failed to delete URLs', 'aisk-ai-chat-for-fluentcart'));
            }
        } catch (error) {
            console.error('Error deleting URLs:', error);
            setProcessMessage(__(`Error: ${error.message}`, 'aisk-ai-chat-for-fluentcart'));
        }
    };

    return (
        <div className="space-y-4">
            {/* User Notice for backend reasoning (e.g., JS-rendered site) */}
            {showUserNotice && userNotice && (
                <div className="mb-4 p-4 rounded-md bg-yellow-50 border border-yellow-300 text-yellow-800 relative">
                    <strong>{__('Notice:', 'aisk-ai-chat-for-fluentcart')}</strong> {userNotice}
                    <button
                        onClick={() => setShowUserNotice(false)}
                        aria-label="Dismiss"
                        className="absolute top-2 right-2 text-xl text-yellow-800 hover:text-yellow-600 focus:outline-none"
                        style={{ background: 'none', border: 'none', cursor: 'pointer', lineHeight: 1 }}
                    >
                        ×
                    </button>
                </div>
            )}

            <div className="flex items-center justify-between mb-4">
                <Label>{__('External Sources', 'aisk-ai-chat-for-fluentcart')}</Label>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={addNewUrl}
                    className="flex items-center gap-2"
                >
                    <PlusIcon className="h-4 w-4" />
                    {__('Add URL', 'aisk-ai-chat-for-fluentcart')}
                </Button>
            </div>

            {/* Status message */}
            {processMessage && (
                <div className={`p-2 rounded-md ${processMessage.includes('Error') ? 'bg-red-50 text-red-500' : 'bg-green-50 text-green-500'}`}>
                    {processMessage}
                </div>
            )}

            {/* Display active polling indicator */}
            {pollingActive && urlsToCheck.length > 0 && (
                <div className="text-xs text-blue-500 flex items-center gap-1 mb-2">
                    <svg className="animate-spin h-3 w-3 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {__('Checking status of processing URLs...', 'aisk-ai-chat-for-fluentcart')}
                </div>
            )}

            {(settings.ai_config.website_urls || []).length === 0 ? (
                <div className="text-center py-4 border rounded-lg bg-gray-50">
                    <p className="text-sm text-gray-500">
                        {__('No external sources added yet', 'aisk-ai-chat-for-fluentcart')}
                    </p>
                </div>
            ) : (
                <div className="space-y-2">
                    {(settings.ai_config.website_urls || []).map((urlConfig, index) => (
                        <div
                            key={index}
                            className="flex items-center justify-between p-3 border rounded-lg hover:bg-gray-50 transition-colors"
                        >
                            <div className="flex items-center gap-2">
                                <span className="font-medium truncate max-w-md">
                                    {urlConfig.url ?
                                        urlConfig.url :
                                        __('Unnamed Source', 'aisk-ai-chat-for-fluentcart')
                                    }
                                </span>
                                {urlConfig.status === 'processed' && (
                                    <span className="text-xs text-green-500 bg-green-50 px-2 py-0.5 rounded-full">
                                        {__('Processed', 'aisk-ai-chat-for-fluentcart')}
                                    </span>
                                )}
                                {urlConfig.status === 'processing' && (
                                    <span className="text-xs text-blue-500 bg-blue-50 px-2 py-0.5 rounded-full flex items-center">
                                        <svg className="animate-spin -ml-1 mr-2 h-3 w-3 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        {__('Processing in background', 'aisk-ai-chat-for-fluentcart')}
                                    </span>
                                )}
                                {urlConfig.status === 'error' && (
                                    <span className="text-xs text-red-500 bg-red-50 px-2 py-0.5 rounded-full">
                                        {__('Error', 'aisk-ai-chat-for-fluentcart')}
                                    </span>
                                )}
                                {urlConfig.status === 'bot_protected' && (
                                    <span className="text-xs text-yellow-600 bg-yellow-50 px-2 py-0.5 rounded-full">
                                        {__('Bot Protected', 'aisk-ai-chat-for-fluentcart')}
                                    </span>
                                )}
                            </div>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => editUrl(index)}
                                    className="hover:bg-blue-50"
                                    disabled={urlConfig.status === 'processing'}
                                >
                                    <PencilIcon className="h-4 w-4 text-blue-500" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => viewUrlDetails(urlConfig.url, index)}
                                    disabled={viewingUrls[index] || !urlConfig.url}
                                    className="hover:bg-blue-50"
                                >
                                    <EyeIcon className="h-4 w-4 text-blue-500" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => resyncUrl(index)}
                                    disabled={loadingUrls[index] || !urlConfig.url || urlConfig.status === 'processing'}
                                    className="hover:bg-green-50"
                                >
                                    <RefreshCwIcon
                                        className={`h-4 w-4 text-green-500 ${loadingUrls[index] ? 'animate-spin' : ''}`}
                                    />
                                </Button>
                                {/* <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => removeUrl(index)}
                                    className="hover:bg-red-50"
                                >
                                    <XIcon className="h-4 w-4 text-red-500" />
                                </Button> */}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* URL Add/Edit Dialog */}
            <Dialog open={openUrlDialog} onOpenChange={setOpenUrlDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {isEditing
                                ? __('Edit External URL', 'aisk-ai-chat-for-fluentcart')
                                : __('Add External URL', 'aisk-ai-chat-for-fluentcart')
                            }
                        </DialogTitle>
                        <DialogDescription>
                            {isEditing
                                ? __('Edit website URL settings', 'aisk-ai-chat-for-fluentcart')
                                : __('Add a website URL to process and include in the knowledge base', 'aisk-ai-chat-for-fluentcart')
                            }
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="space-y-2">
                            <Label htmlFor="url-input">{__('Website URL', 'aisk-ai-chat-for-fluentcart')}</Label>
                            <Input
                                id="url-input"
                                placeholder={__('Enter website URL (e.g., https://example.com)', 'aisk-ai-chat-for-fluentcart')}
                                value={newUrlConfig.url || ''}
                                onChange={(e) => {
                                    setNewUrlConfig(prev => ({
                                        ...prev,
                                        url: e.target.value
                                    }));
                                }}
                            />
                        </div>

                        <div className="flex items-center space-x-2">
                            <Switch
                                id="follow_links"
                                checked={newUrlConfig.follow_links || false}
                                onCheckedChange={(checked) => {
                                    setNewUrlConfig(prev => ({
                                        ...prev,
                                        follow_links: checked
                                    }));
                                }}
                            />
                            <Label htmlFor="follow_links">
                                {__('Follow Links', 'aisk-ai-chat-for-fluentcart')}
                            </Label>
                        </div>

                        {/* Path filters - only shown if Follow Links is enabled */}
                        {newUrlConfig.follow_links && (
                            <div className="space-y-4 pl-6">
                                <div className="space-y-2">
                                    <Label htmlFor="include_paths">
                                        {__('Include Paths', 'aisk-ai-chat-for-fluentcart')}
                                    </Label>
                                    <Input
                                        id="include_paths"
                                        placeholder={__('e.g., /blog/*, /docs/*, /products/*', 'aisk-ai-chat-for-fluentcart')}
                                        value={newUrlConfig.include_paths || ''}
                                        onChange={(e) => {
                                            setNewUrlConfig(prev => ({
                                                ...prev,
                                                include_paths: e.target.value
                                            }));
                                        }}
                                    />
                                    <p className="text-xs text-gray-500">
                                        {__('Comma-separated list of paths to include', 'aisk-ai-chat-for-fluentcart')}
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="exclude_paths">
                                        {__('Exclude Paths', 'aisk-ai-chat-for-fluentcart')}
                                    </Label>
                                    <Input
                                        id="exclude_paths"
                                        placeholder={__('e.g., /wp-*, /cart/*, /checkout/*', 'aisk-ai-chat-for-fluentcart')}
                                        value={newUrlConfig.exclude_paths || ''}
                                        onChange={(e) => {
                                            setNewUrlConfig(prev => ({
                                                ...prev,
                                                exclude_paths: e.target.value
                                            }));
                                        }}
                                    />
                                    <p className="text-xs text-gray-500">
                                        {__('Comma-separated list of paths to exclude', 'aisk-ai-chat-for-fluentcart')}
                                    </p>
                                </div>
                            </div>
                        )}

                        {/* Advanced Options Toggle */}
                        <div className="flex items-center space-x-2 pt-2 border-t">
                            <Switch
                                id="show_advanced_options"
                                checked={showAdvancedOptions}
                                onCheckedChange={setShowAdvancedOptions}
                            />
                            <Label htmlFor="show_advanced_options">
                                {__('Advanced Options', 'aisk-ai-chat-for-fluentcart')}
                            </Label>
                        </div>

                        {/* CSS Selector filters - only shown if Advanced Options is enabled */}
                        {showAdvancedOptions && (
                            <div className="space-y-4 pl-6 pt-2">
                                <div className="space-y-2">
                                    <Label htmlFor="include_selectors">
                                        {__('Include Elements', 'aisk-ai-chat-for-fluentcart')}
                                    </Label>
                                    <Input
                                        id="include_selectors"
                                        placeholder={__('e.g., article, .content, #main-content', 'aisk-ai-chat-for-fluentcart')}
                                        value={newUrlConfig.include_selectors || ''}
                                        onChange={(e) => {
                                            setNewUrlConfig(prev => ({
                                                ...prev,
                                                include_selectors: e.target.value
                                            }));
                                        }}
                                    />
                                    <p className="text-xs text-gray-500">
                                        {__('Comma-separated list of CSS selectors to include (tags, classes, IDs)', 'aisk-ai-chat-for-fluentcart')}
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="exclude_selectors">
                                        {__('Exclude Elements', 'aisk-ai-chat-for-fluentcart')}
                                    </Label>
                                    <Input
                                        id="exclude_selectors"
                                        placeholder={__('e.g., header, footer, nav, .sidebar, #comments', 'aisk-ai-chat-for-fluentcart')}
                                        value={newUrlConfig.exclude_selectors || ''}
                                        onChange={(e) => {
                                            setNewUrlConfig(prev => ({
                                                ...prev,
                                                exclude_selectors: e.target.value
                                            }));
                                        }}
                                    />
                                    <p className="text-xs text-gray-500">
                                        {__('Comma-separated list of CSS selectors to exclude (tags, classes, IDs)', 'aisk-ai-chat-for-fluentcart')}
                                    </p>
                                </div>
                            </div>
                        )}

                        {urlProcessing && (
                            <div className="space-y-2">
                                <Progress value={urlProgress} className="w-full" />
                                <p className="text-sm text-center text-gray-500">
                                    {__('Processing...', 'aisk-ai-chat-for-fluentcart')}
                                </p>
                            </div>
                        )}
                    </div>
                    <DialogFooter className="flex justify-between items-center">
                        <Button
                            variant="secondary"
                            onClick={() => {
                                setOpenUrlDialog(false);
                                setIsEditing(false);
                                setEditingIndex(null);
                            }}
                            disabled={urlProcessing}
                        >
                            {__('Cancel', 'aisk-ai-chat-for-fluentcart')}
                        </Button>
                        <Button
                            variant="default"
                            className="bg-green-600 hover:bg-green-700"
                            onClick={processUrl}
                            disabled={!newUrlConfig.url || urlProcessing}
                        >
                            {__('Process Now', 'aisk-ai-chat-for-fluentcart')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {/* URLs Viewing Dialog */}
            <Dialog open={openDialog} onOpenChange={setOpenDialog}>
                <DialogContent className="sm:max-w-4xl max-h-[80vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {__('Processed URLs for', 'aisk-ai-chat-for-fluentcart')} {selectedUrlData.url}
                        </DialogTitle>
                        <DialogDescription>
                            {__('List of all URLs crawled from the specified source', 'aisk-ai-chat-for-fluentcart')}
                        </DialogDescription>
                    </DialogHeader>

                    {/* Processing Message */}
                    {processMessage && (
                        <div className={`p-2 rounded-md mb-4 ${processMessage.includes('Error') ? 'bg-red-50 text-red-500' : 'bg-green-50 text-green-500'}`}>
                            {processMessage}
                        </div>
                    )}

                    <div className="space-y-2 max-h-[60vh] overflow-y-auto">
                        {!selectedUrlData.urls || selectedUrlData.urls.length === 0 ? (
                            <div>
                                <div className="text-center py-4 text-gray-500">
                                    {__('No crawled URLs found in database. The site may have been processed but URLs not stored correctly.', 'aisk-ai-chat-for-fluentcart')}
                                </div>

                                {/* Fallback - at least show the main URL */}
                                <div className="mt-4 p-4 border rounded-lg bg-blue-50">
                                    <h3 className="font-medium mb-2">{__('Main URL', 'aisk-ai-chat-for-fluentcart')}</h3>
                                    <div className="flex items-center justify-between p-3 border bg-white rounded-lg">
                                        <a
                                            href={selectedUrlData.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-blue-600 hover:underline flex-grow truncate mr-4"
                                        >
                                            {selectedUrlData.url}
                                        </a>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => handleDeleteParentUrl()}
                                            className="hover:bg-red-50"
                                        >
                                            <XIcon className="h-4 w-4 text-red-500" />
                                        </Button>
                                    </div>
                                </div>

                                <div className="mt-4 text-sm text-gray-600">
                                    <p>{__('If the site was recently processed, try the following:', 'aisk-ai-chat-for-fluentcart')}</p>
                                    <ul className="list-disc ml-5 mt-2">
                                        <li>{__('Check if "Follow Links" was enabled during processing', 'aisk-ai-chat-for-fluentcart')}</li>
                                        <li>{__('Try reprocessing the URL', 'aisk-ai-chat-for-fluentcart')}</li>
                                        <li>{__('Check server logs for any crawling errors', 'aisk-ai-chat-for-fluentcart')}</li>
                                    </ul>
                                </div>
                            </div>
                        ) : (
                            selectedUrlData.urls.map((url, index) => (
                                <div
                                    key={index}
                                    className="flex items-center justify-between p-3 border rounded-lg hover:bg-gray-50 transition-colors"
                                >
                                    <a
                                        href={url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-blue-600 hover:underline flex-grow truncate mr-4"
                                    >
                                        {url}
                                    </a>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => handleDeleteUrl(index)}
                                        className="hover:bg-red-50"
                                    >
                                        <XIcon className="h-4 w-4 text-red-500" />
                                    </Button>
                                </div>
                            ))
                        )}
                    </div>
                    <DialogFooter className="flex justify-between items-center">
                        {selectedUrlData.urls && selectedUrlData.urls.length > 0 && (
                            <Button
                                variant="destructive"
                                onClick={() => handleDeleteAllUrls()}
                                className="mr-auto"
                            >
                                {__('Delete All', 'aisk-ai-chat-for-fluentcart')}
                            </Button>
                        )}
                        <Button onClick={() => setOpenDialog(false)}>
                            {__('Close', 'aisk-ai-chat-for-fluentcart')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
};

export default UrlProcessing;