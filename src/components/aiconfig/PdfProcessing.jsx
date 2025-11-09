import React, { useState, useEffect, useCallback } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from "@/components/ui/dialog";
import { PlusIcon, XIcon } from "lucide-react";
import { __, sprintf } from '@wordpress/i18n';

const PdfProcessing = ({ settings, updateSettings, isActiveConfigTab }) => {
    const [processMessage, setProcessMessage] = useState('');

    // PDF processing states
    const [openPdfDialog, setOpenPdfDialog] = useState(false);
    const [pdfProcessing, setPdfProcessing] = useState(false);
    const [pdfProgress, setPdfProgress] = useState(0);
    const [pdfFile, setPdfFile] = useState(null);
    const [pdfError, setPdfError] = useState(null);
    const [pdfMessage, setPdfMessage] = useState('');

    // Delete confirmation modal state
    const [openDeleteDialog, setOpenDeleteDialog] = useState(false);
    const [pdfToDelete, setPdfToDelete] = useState(null);

    // Store polling intervals for each PDF
    const pollingIntervals = React.useRef({});

    // Helper function to get max upload size
    const getMaxUploadSize = () => {
        if (window.WishCartSettings && window.WishCartSettings.maxUploadSize) {
            return (window.WishCartSettings.maxUploadSize / (1024 * 1024)).toFixed(1) + ' MB';
        }
        return '2 MB'; // Default fallback
    };

    // Helper function to validate file size
    const validateFileSize = (file) => {
        const maxSize = window.WishCartSettings?.maxUploadSize || 2 * 1024 * 1024; // Default 2MB
        if (file.size > maxSize) {
            setPdfError(sprintf(
                __('File size exceeds the maximum allowed size of %s. This is limited by your server configuration.', 'wish-cart'),
                getMaxUploadSize()
            ));
            return false;
        }
        return true;
    };

    // Helper function to validate PDF file
    const validatePdfFile = (file) => {
        // Check file extension
        if (!file.name.toLowerCase().endsWith('.pdf')) {
            setPdfError(__('Please upload a PDF file with .pdf extension', 'wish-cart'));
            return false;
        }

        // Check MIME type
        if (file.type && file.type !== 'application/pdf') {
            setPdfError(__('Invalid file type. Please upload a valid PDF file', 'wish-cart'));
            return false;
        }

        // Check if file is a valid PDF by checking the header
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                const uint8Array = new Uint8Array(e.target.result);
                const header = Array.from(uint8Array.slice(0, 5))
                    .map(byte => String.fromCharCode(byte))
                    .join('');

                if (header === '%PDF-') {
                    resolve(true);
                } else {
                    setPdfError(__('Invalid PDF file. The file does not appear to be a valid PDF document', 'wish-cart'));
                    resolve(false);
                }
            };
            reader.readAsArrayBuffer(file);
        });
    };

    const addNewPdf = () => {
        setPdfFile(null);
        setPdfError(null);
        setPdfProgress(0);
        setPdfMessage('');
        setOpenPdfDialog(true);
    };

    const processPdf = async () => {
        if (!pdfFile) {
            setPdfError(__('No PDF file selected', 'wish-cart'));
            return;
        }

        // Validate file size before processing
        if (!validateFileSize(pdfFile)) {
            return;
        }

        // Set processing state immediately - this will affect button text/state
        setPdfProcessing(true);
        setPdfError(null);

        try {
            // Get a fresh copy of the current settings
            let currentSettings;

            try {
                // Fetch current settings from WordPress to ensure we have the latest data
                const settingsResponse = await fetch('/wp-json/wishcart/v1/settings', {
                    method: 'GET',
                    headers: {
                        'X-WP-Nonce': window.WishCartData.nonce
                    },
                });

                if (settingsResponse.ok) {
                    currentSettings = await settingsResponse.json();
                } else {
                    console.warn('Could not fetch fresh settings, using local state');
                    currentSettings = { ...settings };
                }
            } catch (fetchError) {
                console.warn('Error fetching settings:', fetchError);
                currentSettings = { ...settings };
            }

            // Ensure pdf_files array exists
            if (!currentSettings.ai_config) {
                currentSettings.ai_config = {};
            }

            if (!currentSettings.ai_config.pdf_files) {
                currentSettings.ai_config.pdf_files = [];
            }

            // Create a new array with all existing PDFs
            const newPdfs = [...currentSettings.ai_config.pdf_files];

            // Create config with "processing" status
            const pdfConfigToSave = {
                name: pdfFile.name,
                status: 'processing',
                processed_date: new Date().toISOString(),
                size: pdfFile.size
            };

            // Add new PDF to the list (do not modify existing PDFs)
            const newPdfsWithNew = [
                ...currentSettings.ai_config.pdf_files,
                pdfConfigToSave
            ];

            // Update the settings object with the new PDF array
            currentSettings.ai_config.pdf_files = newPdfsWithNew;

            // Update the local state through the provided callback
            updateSettings('ai_config', 'pdf_files', newPdfsWithNew);

            // Save to WordPress database immediately
            try {
                const saveResponse = await fetch('/wp-json/wishcart/v1/settings', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.WishCartData.nonce
                    },
                    body: JSON.stringify(currentSettings)
                });

                if (!saveResponse.ok) {
                    console.error('Error saving PDF to settings:', saveResponse.statusText);
                    // Continue anyway
                }
            } catch (saveError) {
                console.error('Error saving PDF to settings:', saveError);
                // Continue anyway
            }

            // Close the dialog FIRST, before showing any processing indicators in the main UI
            setOpenPdfDialog(false);

            // After closing popup, start progress animation and show processing message in the main UI
            setPdfProcessing(true);
            setPdfProgress(10);
            setProcessMessage(__('Processing PDF...', 'wish-cart'));

            // Now process the PDF in the background
            const formData = new FormData();
            formData.append('pdf_file', pdfFile);

            const response = await fetch('/wp-json/wishcart/v1/process-pdf', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': window.WishCartData.nonce
                },
                body: formData
            });

            setPdfProgress(50); // Update progress midway

            let result;
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                result = await response.json();
            } else {
                // Handle non-JSON response
                const text = await response.text();
                console.error('Invalid response:', text);
                throw new Error(__('Server returned an invalid response. Please try again.', 'wish-cart'));
            }

            if (!response.ok) {
                throw new Error(result.message || __('Failed to process PDF', 'wish-cart'));
            }

            if (result.success) {
                setPdfProgress(100);
                setProcessMessage(__('PDF queued for processing!', 'wish-cart'));

                // When updating after upload, only update the last PDF (the new one)
                const latestPdfs = (settings.ai_config.pdf_files || []);
                const updatedPdfs = latestPdfs.map((pdf, idx) =>
                    idx === latestPdfs.length - 1
                        ? {
                            ...pdf,
                            status: 'queued',
                            job_id: result.job_id
                        }
                        : pdf
                );
                updateSettings('ai_config', 'pdf_files', updatedPdfs);

                // Save to server
                currentSettings.ai_config.pdf_files = updatedPdfs;
                fetch('/wp-json/wishcart/v1/settings', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.WishCartData.nonce
                    },
                    body: JSON.stringify(currentSettings)
                }).catch(error => {
                    console.error('Error saving updated settings:', error);
                });

                // Start polling for status after a short delay (2 seconds)
                if (result.job_id) {
                    setTimeout(() => {
                        pollPdfStatus(result.job_id);
                    }, 2000);
                }
            } else {
                throw new Error(result.message || __('Failed to process PDF', 'wish-cart'));
            }

            // Reset states after a short delay
            setTimeout(() => {
                setProcessMessage('');
                setPdfProgress(0);
                setPdfProcessing(false);
            }, 2000);

        } catch (error) {
            console.error('Error processing PDF:', error);
            setPdfProgress(0);
            setPdfProcessing(false);
            setProcessMessage(error.message || __('Failed to process PDF. Please try again.', 'wish-cart'));

            // Remove the failed PDF from settings
            try {
                // Get fresh settings
                const settingsResponse = await fetch('/wp-json/wishcart/v1/settings', {
                    method: 'GET',
                    headers: {
                        'X-WP-Nonce': window.WishCartData.nonce
                    },
                });

                if (settingsResponse.ok) {
                    const freshSettings = await settingsResponse.json();

                    // Remove the last added PDF (which failed)
                    if (freshSettings.ai_config && freshSettings.ai_config.pdf_files) {
                        freshSettings.ai_config.pdf_files = freshSettings.ai_config.pdf_files.slice(0, -1);

                        // Update local state
                        updateSettings('ai_config', 'pdf_files', freshSettings.ai_config.pdf_files);

                        // Save to server
                        fetch('/wp-json/wishcart/v1/settings', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': window.WishCartData.nonce
                            },
                            body: JSON.stringify(freshSettings)
                        }).catch(error => {
                            console.error('Error removing failed PDF from settings:', error);
                        });
                    }
                }
            } catch (cleanupError) {
                console.error('Error cleaning up failed PDF from settings:', cleanupError);
            }
        }
    };

    // Add polling function for PDF status
    const pollPdfStatus = async (queueId) => {
        const maxAttempts = 60; // 5 minutes with 5-second intervals
        let attempts = 0;

        const checkStatus = async () => {
            try {
                const response = await fetch(`/wp-json/wishcart/v1/pdf-job-status?job_id=${queueId}`, {
                    headers: {
                        'X-WP-Nonce': window.WishCartData.nonce
                    }
                });

                if (!response.ok) {
                    throw new Error('Failed to check PDF status');
                }

                const data = await response.json();

                // Update the PDF status in settings
                const currentSettings = { ...settings };
                if (currentSettings.ai_config && currentSettings.ai_config.pdf_files) {
                    const pdfIndex = currentSettings.ai_config.pdf_files.findIndex(
                        pdf => pdf.job_id === queueId
                    );

                    if (pdfIndex !== -1) {
                        let newStatus = data.status;
                        let newChunks = data.embedding_count;
                        let errorMsg = '';
                        if (data.failed) {
                            newStatus = 'failed';
                            newChunks = 0;
                            errorMsg = data.user_message || __('PDF processing failed', 'wish-cart');
                        }
                        currentSettings.ai_config.pdf_files[pdfIndex] = {
                            ...currentSettings.ai_config.pdf_files[pdfIndex],
                            status: newStatus,
                            chunks: newChunks,
                            error_message: errorMsg
                        };

                        // Update local state
                        updateSettings('ai_config', 'pdf_files', currentSettings.ai_config.pdf_files);

                        // Save to server
                        fetch('/wp-json/wishcart/v1/settings', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': window.WishCartData.nonce
                            },
                            body: JSON.stringify(currentSettings)
                        }).catch(error => {
                            console.error('Error saving updated settings:', error);
                        });
                    }
                }

                // Update processing message
                if (data.user_message) {
                    setProcessMessage(data.user_message);
                }

                // Continue polling if still processing
                if (data.processing && attempts < maxAttempts) {
                    attempts++;
                    setTimeout(checkStatus, 5000); // Check every 5 seconds
                } else if (data.failed) {
                    setProcessMessage(data.user_message || __('PDF processing failed', 'wish-cart'));
                } else if (data.processed) {
                    setProcessMessage(__('PDF processed successfully!', 'wish-cart'));
                }
            } catch (error) {
                console.error('Error checking PDF status:', error);
                if (attempts < maxAttempts) {
                    attempts++;
                    setTimeout(checkStatus, 5000);
                }
            }
        };

        // Start polling
        checkStatus();
    };

    // Function to fetch and update status for a single PDF
    const fetchPdfStatus = useCallback(async (pdf) => {
        if (!pdf.job_id) return pdf;
        try {
            const response = await fetch(`/wp-json/wishcart/v1/pdf-job-status?job_id=${pdf.job_id}`, {
                headers: { 'X-WP-Nonce': window.WishCartData.nonce }
            });
            if (!response.ok) return pdf;
            const data = await response.json();
            return {
                ...pdf,
                status: data.status,
                error_message: data.error_message,
                processed: data.processed,
                processing: data.processing,
                failed: data.failed,
                user_message: data.user_message
            };
        } catch {
            return pdf;
        }
    }, []);

    // Fetch all PDF statuses once on mount ONLY
    useEffect(() => {
        const fetchAllStatuses = async () => {
            if (!settings.ai_config || !settings.ai_config.pdf_files) return;
            const statusPromises = settings.ai_config.pdf_files.map(pdf => fetchPdfStatus(pdf));
            const updatedPdfFiles = await Promise.all(statusPromises);
            updateSettings('ai_config', 'pdf_files', updatedPdfFiles);
        };
        fetchAllStatuses();
        // Only run on mount
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const confirmDeletePdf = (index) => {
        setPdfToDelete(index);
        setOpenDeleteDialog(true);
    };

    // Cancel PDF deletion
    const cancelDelete = () => {
        setPdfToDelete(null);
        setOpenDeleteDialog(false);
    };

    // Confirm and execute PDF deletion
    const confirmDelete = async () => {
        const index = pdfToDelete;
        setOpenDeleteDialog(false);

        try {
            const pdfToRemove = settings.ai_config.pdf_files[index];

            // Show processing state
            setProcessMessage(__('Deleting PDF...', 'wish-cart'));

            // First, remove from local state
            const newPdfs = [...(settings.ai_config.pdf_files || [])];
            newPdfs.splice(index, 1);
            updateSettings('ai_config', 'pdf_files', newPdfs);

            // Then also delete from database if we have an attachment ID
            if (pdfToRemove && pdfToRemove.job_id) {

                // Call our endpoint to delete the PDF embeddings
                const response = await fetch('/wp-json/wishcart/v1/delete-pdf', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.WishCartData.nonce
                    },
                    body: JSON.stringify({
                        job_id: pdfToRemove.job_id
                    })
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
                    // Set success message
                    setProcessMessage(__('PDF deleted successfully.', 'wish-cart'));

                    // Fetch the updated queue list from backend
                    await fetchPdfQueueList();

                    // Fetch fresh settings before updating
                    let freshSettings;
                    try {
                        const response = await fetch('/wp-json/wishcart/v1/settings', {
                            method: 'GET',
                            headers: {
                                'X-WP-Nonce': window.WishCartData.nonce
                            }
                        });

                        if (response.ok) {
                            freshSettings = await response.json();

                            // Remove PDF from fresh settings
                            if (freshSettings.ai_config && freshSettings.ai_config.pdf_files) {
                                freshSettings.ai_config.pdf_files = freshSettings.ai_config.pdf_files.filter(
                                    pdf => pdf.job_id !== pdfToRemove.job_id
                                );
                            }
                        } else {
                            console.warn('Failed to get fresh settings, using local state');
                            freshSettings = {
                                ...settings,
                                ai_config: {
                                    ...settings.ai_config,
                                    pdf_files: newPdfs
                                }
                            };
                        }
                    } catch (fetchError) {
                        console.warn('Error fetching settings:', fetchError);
                        freshSettings = {
                            ...settings,
                            ai_config: {
                                ...settings.ai_config,
                                pdf_files: newPdfs
                            }
                        };
                    }

                    // Persist to server
                    fetch('/wp-json/wishcart/v1/settings', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': window.WishCartData.nonce
                        },
                        body: JSON.stringify(freshSettings)
                    }).catch(error => {
                        console.error('Error saving updated settings:', error);
                    });

                    // Reset message after a delay
                    setTimeout(() => {
                        setProcessMessage('');
                    }, 3000);
                } else {
                    throw new Error(data.message || __('Failed to delete PDF', 'wish-cart'));
                }
            } else {
                // For PDFs that are still processing or don't have an attachment ID yet
                console.log('PDF had no attachment ID, only removed from settings');

                // Set success message
                setProcessMessage(__('PDF removed from settings.', 'wish-cart'));

                // Fetch fresh settings before updating
                let freshSettings;
                try {
                    const response = await fetch('/wp-json/wishcart/v1/settings', {
                        method: 'GET',
                        headers: {
                            'X-WP-Nonce': window.WishCartData.nonce
                        }
                    });

                    if (response.ok) {
                        freshSettings = await response.json();

                        // Apply the change by removing the PDF at the given index
                        if (freshSettings.ai_config && freshSettings.ai_config.pdf_files &&
                            index < freshSettings.ai_config.pdf_files.length) {
                            freshSettings.ai_config.pdf_files.splice(index, 1);
                        } else {
                            freshSettings.ai_config = {
                                ...freshSettings.ai_config,
                                pdf_files: newPdfs
                            };
                        }
                    } else {
                        console.warn('Failed to get fresh settings, using local state');
                        freshSettings = {
                            ...settings,
                            ai_config: {
                                ...settings.ai_config,
                                pdf_files: newPdfs
                            }
                        };
                    }
                } catch (fetchError) {
                    console.warn('Error fetching settings:', fetchError);
                    freshSettings = {
                        ...settings,
                        ai_config: {
                            ...settings.ai_config,
                            pdf_files: newPdfs
                        }
                    };
                }

                // Persist to server
                fetch('/wp-json/wishcart/v1/settings', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.WishCartData.nonce
                    },
                    body: JSON.stringify(freshSettings)
                }).catch(error => {
                    console.error('Error saving updated settings:', error);
                });

                // Reset message after a delay
                setTimeout(() => {
                    setProcessMessage('');
                }, 3000);
            }
        } catch (error) {
            console.error('Error removing PDF:', error);
            setProcessMessage(__(`Error: ${error.message}`, 'wish-cart'));

            // Reset error message after a delay
            setTimeout(() => {
                setProcessMessage('');
            }, 5000);
        } finally {
            setPdfToDelete(null);
        }
    };

    // Fetch PDF queue list from backend
    const fetchPdfQueueList = useCallback(async () => {
        try {
            const response = await fetch('/wp-json/wishcart/v1/pdf-queue-list', {
                headers: { 'X-WP-Nonce': window.WishCartData.nonce }
            });
            if (!response.ok) return;
            const pdfQueue = await response.json();
            updateSettings('ai_config', 'pdf_files', pdfQueue);
        } catch (e) {
            // Optionally handle error
        }
    }, [updateSettings]);

    // Fetch queue list on mount
    useEffect(() => {
        fetchPdfQueueList();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // After upload or delete, call fetchPdfQueueList()
    // (Add fetchPdfQueueList() after successful upload and after delete)

    // When polling for status, only update the status of the specific PDF
    const updatePdfStatus = useCallback((job_id, newStatusData) => {
        updateSettings('ai_config', 'pdf_files', (prev) => {
            if (!Array.isArray(prev)) return prev;
            return prev.map(pdf =>
                String(pdf.job_id) === String(job_id)
                    ? { ...pdf, ...newStatusData }
                    : pdf
            );
        });
    }, [updateSettings]);

    return (
        <div className="space-y-4 mt-6">
            <div className="flex items-center justify-between mb-4">
                <Label>{__('PDF Sources', 'wish-cart')}</Label>
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={addNewPdf}
                        className="flex items-center gap-2"
                    >
                        <PlusIcon className="h-4 w-4" />
                        {__('Add PDF', 'wish-cart')}
                    </Button>
                </div>
            </div>

            {/* Status message */}
            {processMessage && (
                <div className={`p-2 rounded-md ${processMessage.includes('Error') || processMessage.includes('Failed') ? 'bg-red-50 text-red-500' : 'bg-green-50 text-green-500'}`}>
                    {processMessage}
                </div>
            )}

            {/* Progress bar */}
            {pdfProcessing && (
                <div className="space-y-2">
                    <Progress value={pdfProgress} className="w-full" />
                    <p className="text-sm text-center text-gray-500">
                        {__('Processing... This may take a few minutes for larger files.', 'wish-cart')}
                    </p>
                </div>
            )}

            {(settings.ai_config.pdf_files || []).length === 0 ? (
                <div className="text-center py-4 border rounded-lg bg-gray-50">
                    <p className="text-sm text-gray-500">
                        {__('No PDF sources added yet', 'wish-cart')}
                    </p>
                </div>
            ) : (
                <div className="space-y-2">
                    {(settings.ai_config.pdf_files || []).map((pdfConfig, index) => (
                        <div
                            key={index}
                            className="flex items-center justify-between p-3 border rounded-lg hover:bg-gray-50 transition-colors"
                        >
                            <div className="flex items-center gap-2 w-full">
                                <span className="font-medium">
                                    {pdfConfig.name || __('Unnamed PDF', 'wish-cart')}
                                </span>
                                {/* Status badge with color and error message for failed */}
                                {pdfConfig.status && (
                                    <>
                                        <span className={`text-xs px-2 py-1 rounded-full ${pdfConfig.status === 'processed' || pdfConfig.status === 'completed'
                                            ? 'text-green-700 bg-green-100'
                                            : pdfConfig.status === 'processing'
                                                ? 'text-yellow-700 bg-yellow-100'
                                                : pdfConfig.status === 'failed'
                                                    ? 'text-red-700 bg-red-100'
                                                    : 'text-gray-700 bg-gray-100'
                                            }`}>
                                            {pdfConfig.status.charAt(0).toUpperCase() + pdfConfig.status.slice(1)}
                                        </span>
                                        {pdfConfig.status === 'failed' && pdfConfig.error_message && (
                                            <span className="text-xs text-red-600">
                                                {pdfConfig.error_message}
                                            </span>
                                        )}
                                    </>
                                )}
                                {pdfConfig.chunks !== undefined && (
                                    <span className="text-xs text-gray-500">
                                        {pdfConfig.chunks} {__('chunks', 'wish-cart')}
                                    </span>
                                )}

                                {pdfConfig.size && (
                                    <span className="text-xs text-gray-500">
                                        {(Number(pdfConfig.size) / (1024 * 1024)).toFixed(2)} MB
                                    </span>
                                )}
                                {/* Show error message within the row for failed PDFs */}

                                {pdfConfig.status === 'failed' && pdfConfig.user_message && (
                                    <span className="text-xs text-red-600 bg-red-100 px-2 py-0.5 rounded ml-2">
                                        <strong>{__('Error:', 'wish-cart')}</strong> {pdfConfig.user_message}
                                    </span>
                                )}
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => confirmDeletePdf(index)}
                                className="hover:bg-red-50"
                            >
                                <XIcon className="h-4 w-4 text-red-500" />
                            </Button>
                        </div>
                    ))}
                </div>
            )}

            {/* PDF Upload Dialog */}
            <Dialog open={openPdfDialog} onOpenChange={setOpenPdfDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{__('Add PDF Document', 'wish-cart')}</DialogTitle>
                        <DialogDescription>
                            {__('Upload a PDF document to process and include in the knowledge base', 'wish-cart')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="space-y-2">
                            <Label htmlFor="pdf-upload">{__('PDF Document', 'wish-cart')}</Label>
                            <Input
                                id="pdf-upload"
                                type="file"
                                accept=".pdf"
                                onChange={(e) => {
                                    const file = e.target.files[0];
                                    if (file) {
                                        // Check file size on client side
                                        // const maxSize = window.WishCartSettings?.maxUploadSize || 2 * 1024 * 1024; // Default 2MB
                                        const maxSize = getMaxUploadSize();

                                        if (file.size > maxSize) {
                                            setPdfError(
                                                __(`The file is too large. Maximum allowed size is ${getMaxUploadSize()}. This is limited by your server configuration.`, 'wish-cart')
                                            );
                                            e.target.value = ''; // Clear the input
                                        } else {
                                            // Validate PDF file
                                            validatePdfFile(file).then((isValid) => {
                                                if (isValid) {
                                                    setPdfFile(file);
                                                    setPdfError(null); // Clear any previous errors
                                                } else {
                                                    e.target.value = ''; // Clear the input
                                                }
                                            });
                                        }
                                    }
                                }}
                            />
                            <p className="text-xs text-gray-500">
                                {__(`Maximum file size: ${getMaxUploadSize()} (server limit)`, 'wish-cart')}
                            </p>
                            {pdfFile && (
                                <p className="text-sm text-gray-500">
                                    {__('Selected file:', 'wish-cart')} {pdfFile.name} ({(pdfFile.size / (1024 * 1024)).toFixed(2)} MB)
                                </p>
                            )}
                        </div>

                        {pdfError && (
                            <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-md">
                                <p className="text-sm">{pdfError}</p>
                            </div>
                        )}

                        {pdfMessage && !pdfError && (
                            <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-md">
                                <p className="text-sm">{pdfMessage}</p>
                            </div>
                        )}
                    </div>
                    <DialogFooter className="flex justify-between items-center">
                        <Button
                            variant="secondary"
                            onClick={() => {
                                setOpenPdfDialog(false);
                                setPdfFile(null);
                                setPdfError(null);
                                setPdfMessage('');
                                setPdfProcessing(false); // Reset processing state when canceling
                            }}
                            disabled={pdfProcessing}
                        >
                            {__('Cancel', 'wish-cart')}
                        </Button>
                        <Button
                            variant="default"
                            className="bg-green-600 hover:bg-green-700"
                            onClick={processPdf}
                            disabled={!pdfFile || pdfProcessing || pdfError}
                        >
                            {pdfProcessing ? __('Processing...', 'wish-cart') : __('Process Now', 'wish-cart')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation Dialog */}
            <Dialog open={openDeleteDialog} onOpenChange={setOpenDeleteDialog}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{__('Confirm Deletion', 'wish-cart')}</DialogTitle>
                        <DialogDescription>
                            {__('Are you sure you want to delete this PDF? This will remove it from your knowledge base.', 'wish-cart')}
                        </DialogDescription>
                    </DialogHeader>
                    {pdfToDelete !== null && settings.ai_config.pdf_files && settings.ai_config.pdf_files[pdfToDelete] && (
                        <div className="py-3">
                            <p className="font-medium text-center">
                                {settings.ai_config.pdf_files[pdfToDelete].name || __('Unnamed PDF', 'wish-cart')}
                            </p>
                        </div>
                    )}
                    <DialogFooter className="flex justify-between items-center">
                        <Button
                            variant="secondary"
                            onClick={cancelDelete}
                        >
                            {__('Cancel', 'wish-cart')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={confirmDelete}
                        >
                            {__('Delete', 'wish-cart')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
};

export default PdfProcessing;
