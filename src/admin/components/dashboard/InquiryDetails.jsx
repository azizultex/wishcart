// InquiryDetails.jsx
import { __ } from '@wordpress/i18n';
import React, { useState, useEffect } from 'react';
import {
    Box,
    Paper,
    Typography,
    Divider,
    TextField,
    Button,
    List,
    ListItem,
    ListItemText,
    Stack,
    FormControl,
    Select,
    MenuItem,
    Chip
} from '@mui/material';

const InquiryDetails = ({ inquiryId }) => {
    const [inquiry, setInquiry] = useState(null);
    const [notes, setNotes] = useState([]);
    const [newNote, setNewNote] = useState('');
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        loadInquiryDetails();
    }, [inquiryId]);

    const loadInquiryDetails = async () => {
        try {
            const response = await fetch(
                `${window.WishCartData.apiUrl}/inquiries/${inquiryId}`,
                {
                    headers: {
                        'X-WP-Nonce': window.WishCartData.nonce
                    }
                }
            );
            const data = await response.json();
            setInquiry(data.inquiry);
            setNotes(data.notes);
        } catch (error) {
            console.error('Error loading inquiry details:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleStatusUpdate = async (newStatus) => {
        try {
            const response = await fetch(
                `${window.WishCartData.apiUrl}/inquiries/${inquiryId}/status`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.WishCartData.nonce
                    },
                    body: JSON.stringify({ status: newStatus })
                }
            );

            if (response.ok) {
                setInquiry(prev => ({
                    ...prev,
                    status: newStatus
                }));
            }
        } catch (error) {
            console.error('Error updating status:', error);
        }
    };

    const handleAddNote = async () => {
        if (!newNote.trim()) return;

        try {
            const response = await fetch(
                `${window.WishCartData.apiUrl}/inquiries/${inquiryId}/notes`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.WishCartData.nonce
                    },
                    body: JSON.stringify({ note: newNote })
                }
            );

            if (response.ok) {
                setNewNote('');
                loadInquiryDetails();
            }
        } catch (error) {
            console.error('Error adding note:', error);
        }
    };

    const getStatusChip = (status) => {
        const statusConfig = {
            pending: { color: 'warning', label: __('Pending', 'wish-cart') },
            in_progress: { color: 'info', label: __('In Progress', 'wish-cart') },
            resolved: { color: 'success', label: __('Resolved', 'wish-cart') }
        };
        const config = statusConfig[status] || statusConfig.pending;
        return <Chip label={config.label} color={config.color} size="small" />;
    };

    const getTimeAgo = (dateString) => {
        const date = new Date(dateString);
        const now = new Date();
        const diffInSeconds = Math.floor((now - date) / 1000);

        if (diffInSeconds < 60) return __('just now', 'wish-cart');

        const diffInMinutes = Math.floor(diffInSeconds / 60);
        if (diffInMinutes < 60) {
            return diffInMinutes === 1
                ? __('1 minute ago', 'wish-cart')
                : sprintf(__('%d minutes ago', 'wish-cart'), diffInMinutes);
        }

        const diffInHours = Math.floor(diffInMinutes / 60);
        if (diffInHours < 24) {
            return diffInHours === 1
                ? __('1 hour ago', 'wish-cart')
                : sprintf(__('%d hours ago', 'wish-cart'), diffInHours);
        }

        const diffInDays = Math.floor(diffInHours / 24);
        if (diffInDays < 30) {
            return diffInDays === 1
                ? __('1 day ago', 'wish-cart')
                : sprintf(__('%d days ago', 'wish-cart'), diffInDays);
        }

        const diffInMonths = Math.floor(diffInDays / 30);
        if (diffInMonths < 12) {
            return diffInMonths === 1
                ? __('1 month ago', 'wish-cart')
                : sprintf(__('%d months ago', 'wish-cart'), diffInMonths);
        }

        const diffInYears = Math.floor(diffInMonths / 12);
        return diffInYears === 1
            ? __('1 year ago', 'wish-cart')
            : sprintf(__('%d years ago', 'wish-cart'), diffInYears);
    };

    // Helper to ensure date string is parsed as UTC
    const parseUTCDate = (dateString) => {
        // If already ISO 8601 with 'T' and 'Z', return as is
        if (/T.*Z$/.test(dateString)) return new Date(dateString);
        // If has 'T' but no 'Z', treat as UTC and add 'Z'
        if (/T/.test(dateString) && !/Z$/.test(dateString)) return new Date(dateString + 'Z');
        // If has space, convert to ISO and add 'Z'
        if (/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(dateString)) {
            return new Date(dateString.replace(' ', 'T') + 'Z');
        }
        // Fallback
        return new Date(dateString);
    };

    if (loading) return <Box sx={{ p: 3 }}>{__('Loading...', 'wish-cart')}</Box>;
    if (!inquiry) return <Box sx={{ p: 3 }}>{__('Inquiry not found', 'wish-cart')}</Box>;

    return (
        <Box sx={{ p: 3 }}>
            <Paper sx={{ p: 3, mb: 3 }}>
                <Box sx={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'start',
                    mb: 3
                }}>
                    <Box>
                        <Box>
                            <Typography variant="h5" gutterBottom>
                                {__('Inquiry', 'wish-cart')} #{inquiry.id} - {__('Order', 'wish-cart')} #{inquiry.order_number}
                            </Typography>
                            <Typography variant="subtitle2" color="text.secondary">
                                {__('Created:', 'wish-cart')}
                                {(() => {
                                    // Parse the UTC timestamp from the database (stored in UTC)
                                    const utcDate = new Date(inquiry.created_at);

                                    // Check if the date is valid
                                    if (isNaN(utcDate)) {
                                        return 'Invalid Date'; // Fallback message if the date is invalid
                                    }

                                    // Return the formatted date in the visitor's local time zone
                                    return utcDate.toLocaleString(undefined, {
                                        year: 'numeric',
                                        month: 'short',
                                        day: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit',
                                        timeZoneName: 'short',
                                    });
                                })()}
                            </Typography>
                        </Box>
                    </Box>
                    <FormControl size="small" sx={{ minWidth: 150 }}>
                        <Select
                            value={inquiry.status}
                            onChange={(e) => handleStatusUpdate(e.target.value)}
                            displayEmpty
                        >
                            <MenuItem value="pending">{getStatusChip('pending')}</MenuItem>
                            <MenuItem value="in_progress">{getStatusChip('in_progress')}</MenuItem>
                            <MenuItem value="resolved">{getStatusChip('resolved')}</MenuItem>
                        </Select>
                    </FormControl>
                </Box>

                <Stack spacing={3}>
                    <Box>
                        <Typography variant="h6" gutterBottom>{__('Customer Details', 'wish-cart')}</Typography>
                        <Stack spacing={1}>
                            <Typography>{__('Email:', 'wish-cart')} {inquiry.customer_email}</Typography>
                            <Typography>{__('Phone:', 'wish-cart')} {inquiry.customer_phone}</Typography>
                        </Stack>
                    </Box>

                    <Box>
                        <Typography variant="h6" gutterBottom>{__('Inquiry Details', 'wish-cart')}</Typography>
                        <Paper variant="outlined" sx={{ p: 2, bgcolor: 'grey.50' }}>
                            <Typography>{inquiry.note}</Typography>
                        </Paper>
                    </Box>

                    <Divider />

                    <Box>
                        <Typography variant="h6" gutterBottom>{__('Notes History', 'wish-cart')}</Typography>
                        <List sx={{ bgcolor: 'background.paper' }}>
                            {notes.map((note) => (
                                <ListItem
                                    key={note.id}
                                    divider
                                    sx={{
                                        display: 'flex',
                                        flexDirection: 'column',
                                        alignItems: 'stretch',
                                        py: 2
                                    }}
                                >
                                    <Box sx={{ width: '100%' }}>
                                        <Typography>{note.note}</Typography>
                                        <Typography
                                            variant="caption"
                                            color="text.secondary"
                                            sx={{ mt: 1, display: 'block' }}
                                        >
                                            {__('Added by', 'wish-cart')} {note.author} • {getTimeAgo(note.created_at)}
                                            <span title={parseUTCDate(note.created_at).toLocaleString(undefined, {
                                                year: 'numeric',
                                                month: 'short',
                                                day: 'numeric',
                                                hour: '2-digit',
                                                minute: '2-digit',
                                                timeZoneName: 'short'
                                            })}>
                                                {" "}({parseUTCDate(note.created_at).toLocaleDateString(undefined, {
                                                    year: 'numeric',
                                                    month: 'short',
                                                    day: 'numeric'
                                                })})
                                            </span>
                                        </Typography>
                                    </Box>
                                </ListItem>
                            ))}
                        </List>

                        <Box sx={{ mt: 3 }}>
                            <Typography variant="subtitle1" gutterBottom>{__('Add Note', 'wish-cart')}</Typography>
                            <TextField
                                fullWidth
                                multiline
                                rows={3}
                                value={newNote}
                                onChange={(e) => setNewNote(e.target.value)}
                                placeholder={__('Type your note here...', 'wish-cart')}
                                sx={{ mb: 2 }}
                            />
                            <Button
                                variant="contained"
                                onClick={handleAddNote}
                                disabled={!newNote.trim()}
                            >
                                {__('Add Note', 'wish-cart')}
                            </Button>
                        </Box>
                    </Box>
                </Stack>
            </Paper>
        </Box>
    );

}

export default InquiryDetails;