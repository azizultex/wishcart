// InquiriesPage.jsx
import { __ } from '@wordpress/i18n';
import React, { useState, useEffect, useRef } from 'react';
import { DataGrid } from '@mui/x-data-grid';
import {
    Box,
    Button,
    Typography,
    Chip,
    Stack,
    TextField,
    FormControl,
    InputLabel,
    Select,
    MenuItem,
} from '@mui/material';
import ChatWidget from '../../../components/ChatWidget';
import '../../styles/CustomerInquiries.scss';

const InquiriesPage = () => {
    const [inquiries, setInquiries] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedChat, setSelectedChat] = useState(null);
    const [page, setPage] = useState(0);
    const [pageSize, setPageSize] = useState(20);
    const [totalRows, setTotalRows] = useState(0);

    // Filter states
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [searchQuery, setSearchQuery] = useState('');
    const [isFluentCartActive, setIsFluentCartActive] = useState(false);

    const chatWidgetRef = useRef(null);

    useEffect(() => {
        isFluentCartActive && loadInquiries();
    }, [page, pageSize, startDate, endDate, statusFilter, searchQuery, isFluentCartActive]);

    
    useEffect(() => {
        setIsFluentCartActive(!!window?.WishCartSettings?.isFluentCartActive);
    }, []);

    const loadInquiries = async () => {
        try {
            const queryParams = new URLSearchParams({
                page: page + 1,
                per_page: pageSize,
                status: statusFilter !== 'all' ? statusFilter : '',
                search: searchQuery,
                start_date: startDate,
                end_date: endDate
            });

            const restUrl = window?.wpApiSettings?.root || window?.WishCartData?.apiUrl || '/wp-json/';
            const nonce = window?.wpApiSettings?.nonce || window?.WishCartData?.nonce;

            if (!nonce) {
                throw new Error(__('Authentication token missing. Please refresh the page.', 'wish-cart'));
            }

            const response = await fetch(
                `${restUrl}wishcart/v1/inquiries?${queryParams.toString()}`,
                {
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce
                    },
                    credentials: 'same-origin'
                }
            );
            const data = await response.json();
            setInquiries(data.inquiries);
            setTotalRows(data.total);
        } catch (error) {
            console.error('Error loading inquiries:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleViewChat = (chat) => {
        setSelectedChat(null);
        localStorage.removeItem('wooai_conversation_id');

        setTimeout(() => {
            localStorage.setItem('wooai_conversation_id', chat.conversation_id);
            setSelectedChat(chat);
            if (chatWidgetRef.current) {
                chatWidgetRef.current.open();
            }
        }, 0);
    };

    const handleViewDetails = (id) => {
        window.location.href = `${window.WishCartData.adminUrl}?page=wishcart-inquiries&view=details&id=${id}`;
    };

    const handleStatusUpdate = async (inquiryId, newStatus) => {
        try {
            const restUrl = window?.wpApiSettings?.root || window?.WishCartData?.apiUrl || '/wp-json/';
            const nonce = window?.wpApiSettings?.nonce || window?.WishCartData?.nonce;

            if (!nonce) {
                throw new Error(__('Authentication token missing. Please refresh the page.', 'wish-cart'));
            }

            const response = await fetch(
                `${restUrl}wishcart/v1/inquiries/${inquiryId}/status`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ status: newStatus })
                }
            );

            if (response.ok) {
                isFluentCartActive && loadInquiries();
            }
        } catch (error) {
            console.error('Error updating status:', error);
        }
    };

    const handleResetFilters = () => {
        setStartDate('');
        setEndDate('');
        setStatusFilter('all');
        setSearchQuery('');
        setPage(0);
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

    const columns = [
        {
            field: 'id',
            headerName: __('ID', 'wish-cart'),
            width: 70
        },
        {
            field: 'order_number',
            headerName: __('Order #', 'wish-cart'),
            width: 100
        },
        {
            field: 'customer_email',
            headerName: __('Email', 'wish-cart'),
            width: 200
        },
        {
            field: 'customer_phone',
            headerName: __('Phone', 'wish-cart'),
            width: 130
        },
        {
            field: 'note',
            headerName: __('Inquiry', 'wish-cart'),
            width: 300,
            renderCell: (params) => (
                <Typography
                    variant="body2"
                    sx={{
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                        whiteSpace: 'nowrap'
                    }}
                >
                    {params.value}
                </Typography>
            )
        },
        {
            field: 'status',
            headerName: __('Status', 'wish-cart'),
            width: 150,
            renderCell: (params) => (
                <FormControl size="small" sx={{ minWidth: 120 }}>
                    <Select
                        value={params.value}
                        onChange={(e) => handleStatusUpdate(params.row.id, e.target.value)}
                        size="small"
                        sx={{
                            '&.Mui-focused': {
                                backgroundColor: 'transparent'
                            }
                        }}
                    >
                        <MenuItem value="pending">
                            {getStatusChip('pending')}
                        </MenuItem>
                        <MenuItem value="in_progress">
                            {getStatusChip('in_progress')}
                        </MenuItem>
                        <MenuItem value="resolved">
                            {getStatusChip('resolved')}
                        </MenuItem>
                    </Select>
                </FormControl>
            )
        },
        {
            field: 'created_at',
            headerName: __('Date', 'wish-cart'),
            width: 180,
            valueFormatter: (params) => {
                let utcDateString = params.value;
                if (utcDateString && !utcDateString.includes('T')) {
                    utcDateString = utcDateString.replace(' ', 'T');
                }
                if (utcDateString && !utcDateString.endsWith('Z')) {
                    utcDateString += 'Z';
                }
                const utcDate = new Date(utcDateString);

                if (isNaN(utcDate)) {
                    return 'Invalid Date';
                }

                // Get the user's time zone abbreviation and name
                const localeString = utcDate.toLocaleString(undefined, {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    timeZoneName: 'short',
                });
                const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                return `${localeString} (${tz})`;
            }
        },
        {
            field: 'actions',
            headerName: __('Actions', 'wish-cart'),
            width: 200,
            renderCell: (params) => (
                <Stack direction="row" spacing={1}>
                    <Button
                        variant="contained"
                        size="small"
                        onClick={() => handleViewChat(params.row)}
                    >
                        View Chat
                    </Button>
                    <Button
                        variant="outlined"
                        size="small"
                        onClick={() => handleViewDetails(params.row.id)}
                    >
                        Details
                    </Button>
                </Stack>
            )
        }
    ];

    return (
        <Box sx={{ p: 3 }} className="admin-customer-inquiries">
            <Typography variant="h4" component="h1" gutterBottom>
                {__('Customer Inquiries', 'wish-cart')}
            </Typography>

            <Stack direction="row" spacing={2} sx={{ mb: 3 }} alignItems="center">
                <TextField
                    type="date"
                    label={__('Start Date', 'wish-cart')}
                    className="admin-customer-inquiries__input-date-pic"
                    value={startDate}
                    onChange={(e) => setStartDate(e.target.value)}
                    size="small"
                    InputLabelProps={{ shrink: true }}
                    sx={{ width: 150 }}
                />

                <TextField
                    type="date"
                    label={__('End Date', 'wish-cart')}
                    className="admin-customer-inquiries__input-date-pic"
                    value={endDate}
                    onChange={(e) => setEndDate(e.target.value)}
                    size="small"
                    InputLabelProps={{ shrink: true }}
                    sx={{ width: 150 }}
                />

                <FormControl size="small" sx={{ minWidth: 120 }}>
                    <InputLabel className="admin-customer-inquiries__input-select-label">{__('Status', 'wish-cart')}</InputLabel>
                    <Select
                        className="admin-customer-inquiries__input-select"
                        value={statusFilter}
                        label={__('Status', 'wish-cart')}
                        onChange={(e) => setStatusFilter(e.target.value)}
                    >
                        <MenuItem value="all">{__('All', 'wish-cart')}</MenuItem>
                        <MenuItem value="pending">{__('Pending', 'wish-cart')}</MenuItem>
                        <MenuItem value="in_progress">{__('In Progress', 'wish-cart')}</MenuItem>
                        <MenuItem value="resolved">{__('Resolved', 'wish-cart')}</MenuItem>
                    </Select>
                </FormControl>

                <TextField
                    size="small"
                    className="admin-customer-inquiries__input-search"
                    label={__('Search', 'wish-cart')}
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder={__('Order #, Email, Phone...', 'wish-cart')}
                    sx={{ width: 250 }}
                />

                <Button
                    variant="outlined"
                    className="admin-customer-inquiries__button"
                    onClick={handleResetFilters}
                >
                    {__('Reset Filters', 'wish-cart')}
                </Button>
            </Stack>

            <Box sx={{ height: 600, width: '100%' }}>
                <DataGrid
                    rows={inquiries}
                    columns={columns}
                    pagination
                    page={page}
                    pageSize={pageSize}
                    rowCount={totalRows}
                    paginationMode="server"
                    onPageChange={(newPage) => setPage(newPage)}
                    onPageSizeChange={(newPageSize) => setPageSize(newPageSize)}
                    rowsPerPageOptions={[10, 20, 50, 100]}
                    loading={isFluentCartActive ? loading : false}
                    disableSelectionOnClick
                /> 
            </Box>

            {selectedChat && (
                <ChatWidget
                    ref={chatWidgetRef}
                    key={selectedChat.conversation_id}
                    conversationId={selectedChat.conversation_id}
                    readOnly={true}
                />
            )}
        </Box>
    );
};

export default InquiriesPage;