import { __ } from '@wordpress/i18n';
import React, { useState, useEffect, useRef } from 'react';
import { ThemeProvider, createTheme } from '@mui/material/styles';
import {
    DataGrid,
    gridPageCountSelector,
    gridPageSelector,
    useGridApiContext,
    useGridSelector
} from '@mui/x-data-grid';
import {
    Box,
    Button,
    Pagination,
    Stack,
    Typography,
    Chip,
    Tooltip,
    Select,
    MenuItem,
    FormControl,
    InputLabel
} from '@mui/material';
import {
    Monitor,
    Smartphone,
    Tablet,
    MapPin
} from 'lucide-react';
import ChatWidget from '../../../components/ChatWidget';

// Create theme that matches WordPress admin
const theme = createTheme({
    palette: {
        primary: {
            main: '#2271b1',
            dark: '#135e96'
        },
        secondary: {
            main: '#72777c'
        },
        background: {
            default: '#f0f0f1'
        }
    },
    components: {
        MuiDataGrid: {
            styleOverrides: {
                root: {
                    backgroundColor: '#fff',
                    border: '1px solid #c3c4c7',
                    '& .MuiDataGrid-columnHeaders': {
                        backgroundColor: '#f0f0f1',
                        borderBottom: '1px solid #c3c4c7'
                    }
                }
            }
        },
        MuiChip: {
            styleOverrides: {
                root: {
                    borderRadius: '16px',
                    margin: '2px'
                }
            }
        }
    }
});

function CustomPagination() {
    const apiRef = useGridApiContext();
    const page = useGridSelector(apiRef, gridPageSelector);
    const pageCount = useGridSelector(apiRef, gridPageCountSelector);

    return (
        <Pagination
            color="primary"
            count={pageCount}
            page={page + 1}
            onChange={(event, value) => apiRef.current.setPage(value - 1)}
        />
    );
}

// Device detection helper
const getDeviceIcon = (platform) => {
    if (!platform) {
        return <Monitor size={20} />;
    }

    const ua = String(platform).toLowerCase();

    // Check for specific platforms first
    if (ua.includes('whatsapp')) {
        return <svg id="Bold" enable-background="new 0 0 24 24" height="20" viewBox="0 0 24 24" width="20" xmlns="http://www.w3.org/2000/svg"><path d="m17.507 14.307-.009.075c-2.199-1.096-2.429-1.242-2.713-.816-.197.295-.771.964-.944 1.162-.175.195-.349.21-.646.075-.3-.15-1.263-.465-2.403-1.485-.888-.795-1.484-1.77-1.66-2.07-.293-.506.32-.578.878-1.634.1-.21.049-.375-.025-.524-.075-.15-.672-1.62-.922-2.206-.24-.584-.487-.51-.672-.51-.576-.05-.997-.042-1.368.344-1.614 1.774-1.207 3.604.174 5.55 2.714 3.552 4.16 4.206 6.804 5.114.714.227 1.365.195 1.88.121.574-.091 1.767-.721 2.016-1.426.255-.705.255-1.29.18-1.425-.074-.135-.27-.21-.57-.345z"/><path d="m20.52 3.449c-7.689-7.433-20.414-2.042-20.419 8.444 0 2.096.549 4.14 1.595 5.945l-1.696 6.162 6.335-1.652c7.905 4.27 17.661-1.4 17.665-10.449 0-3.176-1.24-6.165-3.495-8.411zm1.482 8.417c-.006 7.633-8.385 12.4-15.012 8.504l-.36-.214-3.75.975 1.005-3.645-.239-.375c-4.124-6.565.614-15.145 8.426-15.145 2.654 0 5.145 1.035 7.021 2.91 1.875 1.859 2.909 4.35 2.909 6.99z"/></svg>;
    }

    if (ua.includes('telegram')) {
        return <svg id="Bold" enable-background="new 0 0 24 24" height="20" viewBox="0 0 24 24" width="20" xmlns="http://www.w3.org/2000/svg"><path d="m9.417 15.181-.397 5.584c.568 0 .814-.244 1.109-.537l2.663-2.545 5.518 4.041c1.012.564 1.725.267 1.998-.931l3.622-16.972.001-.001c.321-1.496-.541-2.081-1.527-1.714l-21.29 8.151c-1.453.564-1.431 1.374-.247 1.741l5.443 1.693 12.643-7.911c.595-.394 1.136-.176.691.218z"/></svg>;
    }

    // Check for different mobile/desktop platforms
    if (ua.includes('mobile') ||
        ua.includes('android') ||
        ua.includes('iphone') ||
        ua.includes('ipad') ||
        ua.includes('windows phone')) {
        return <Smartphone size={20} />;
    }

    if (ua.includes('tablet') ||
        ua.includes('ipad') ||
        ua.includes('android') && !ua.includes('mobile')) {
        return <Tablet size={20} />;
    }

    return <Monitor size={20} />;
};

// Intent chips with different colors
const IntentChip = ({ intent }) => {
    const getColor = (intent) => {
        const colors = {
            'pre-sales': 'rgb(176, 230, 255)',
            'after-sales': 'rgb(171, 255, 208)',
            'payment': 'rgb(255, 223, 171)',
            'order': 'rgb(255, 171, 194)',
            'product': 'rgb(190, 171, 255)',
            'delivery': 'rgb(171, 255, 245)',
            'discounts': 'rgb(255, 171, 237)',
            'promotions': 'rgb(215, 255, 171)'
        };
        return colors[intent.toLowerCase()] || 'rgb(200, 200, 200)';
    };

    return (
        <Chip
            label={intent}
            sx={{
                backgroundColor: getColor(intent),
                color: '#000',
                fontWeight: 500,
                fontSize: '0.8rem'
            }}
        />
    );
};

const ChatAdminDashboard = () => {
    const [conversations, setConversations] = useState([]);
    const [selectedChat, setSelectedChat] = useState(null);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(0);
    const [pageSize, setPageSize] = useState(3);
    const [totalRows, setTotalRows] = useState(0);
    const [timeFilter, setTimeFilter] = useState('7days');
    const [locationFilter, setLocationFilter] = useState('all');
    const [availableLocations, setAvailableLocations] = useState([]);
    const [error, setError] = useState(null);
    const chatWidgetRef = useRef(null);

    const handleViewChat = (chat) => {
        // Clear localStorage when opening a new chat
        localStorage.removeItem('wooai_conversation_id');

        if (selectedChat?.conversation_id !== chat.conversation_id) {
            setSelectedChat(null);
            setTimeout(() => {
                // Set the new conversation ID in localStorage
                localStorage.setItem('wooai_conversation_id', chat.conversation_id);
                setSelectedChat(chat);
                if (chatWidgetRef.current) {
                    chatWidgetRef.current.open();
                }
            }, 0);
        } else {
            if (chatWidgetRef.current) {
                chatWidgetRef.current.open();
            }
        }
    };

    const columns = [
        {
            field: 'id',
            headerName: __('ID', 'wish-cart'),
            width: 100,
            renderCell: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                    {getDeviceIcon(params.row.platform)}
                    <Typography variant="body2">#{params.row.conversation_id}</Typography>
                </Box>
            )
        },
        {
            field: 'location',
            headerName: __('Location', 'wish-cart'),
            width: 200,
            renderCell: (params) => (
                <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                    <MapPin size={16} />
                    <Stack>
                        <Typography variant="body2">
                            {params.row.city || __('Unknown City', 'wish-cart')}
                            {params.row.country && `, ${params.row.country}`}
                        </Typography>
                        {params.row.ip_address && (
                            <Typography variant="caption" color="textSecondary">
                                {params.row.ip_address}
                            </Typography>
                        )}
                    </Stack>
                </Box>
            ),
            sortable: true
        },
        {
            field: 'intents',
            headerName: __('Intents', 'wish-cart'),
            width: 200,
            renderCell: (params) => {
                let intents = [];
                try {
                    // Try to parse if it's a JSON string
                    intents = params.row.intents ? JSON.parse(params.row.intents) : [];
                } catch (e) {
                    // If it's a comma-separated string, split it
                    intents = params.row.intents ? params.row.intents.split(',') : [];
                }

                return (
                    <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
                        {intents.map((intent, index) => (
                            <IntentChip key={index} intent={intent.trim()} />
                        ))}
                    </Box>
                );
            }
        },
        {
            field: 'user_info',
            headerName: __('User Info', 'wish-cart'),
            width: 250,
            renderCell: (params) => (
                <Stack>
                    <Typography variant="body2">
                        {params.row.user_name || __('Anonymous', 'wish-cart')}
                    </Typography>
                    {params.row.user_email && (
                        <Typography variant="caption" color="textSecondary">
                            {params.row.user_email}
                        </Typography>
                    )}
                    {params.row.user_phone && (
                        <Typography variant="caption" color="textSecondary">
                            {params.row.user_phone}
                        </Typography>
                    )}
                </Stack>
            )
        },
        {
            field: 'page_url',
            headerName: __('Source Page', 'wish-cart'),
            width: 200,
            renderCell: (params) => (
                <Tooltip title={params.row.page_url || ''}>
                    <Typography variant="body2" noWrap>
                        {params.row.page_url ? new URL(params.row.page_url).pathname : 'N/A'}
                    </Typography>
                </Tooltip>
            )
        },
        {
            field: 'created_at',
            headerName: __('Date & Time', 'wish-cart'),
            width: 200,
            valueFormatter: (params) => {
                // Parse the UTC timestamp from the database (stored in UTC)
                const utcDate = new Date(params.value);

                // Check if the date is valid
                if (isNaN(utcDate)) {
                    return 'Invalid Date'; // Fallback message if the date is invalid
                }

                // Get the visitor's local time zone offset in minutes (in UTC)
                const localOffset = new Date().getTimezoneOffset(); // This returns the offset in minutes

                // Adjust the UTC date by the visitor's local time zone offset
                utcDate.setMinutes(utcDate.getMinutes() - localOffset);

                // Get today's date and yesterday's date for comparison
                const today = new Date();
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);

                // Format time with user's locale settings
                const time = utcDate.toLocaleTimeString(undefined, {
                    hour: '2-digit',
                    minute: '2-digit',
                });

                // If date is today
                if (utcDate.toDateString() === today.toDateString()) {
                    return __('Today', 'wish-cart') + `, ${time}`;
                }
                // If date is yesterday
                if (utcDate.toDateString() === yesterday.toDateString()) {
                    return __('Yesterday', 'wish-cart') + `, ${time}`;
                }
                // For other dates
                return utcDate.toLocaleDateString(undefined, {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                }) + `, ${time}`;
            }
        },
        {
            field: 'actions',
            headerName: __('Actions', 'wish-cart'),
            width: 120,
            sortable: false,
            renderCell: (params) => (
                <Button
                    variant="contained"
                    size="small"
                    onClick={() => handleViewChat(params.row)}
                >
                    {__('View Chat', 'wish-cart')}
                </Button>
            )
        }
    ];

    useEffect(() => {
        loadConversations();
    }, [page, pageSize, timeFilter, locationFilter]);
 
    const loadConversations = async () => {
        setLoading(true); // Set loading immediately

        let apiUrl = `${WishCartSettings.apiUrl}/conversations`;
        apiUrl += `?page=${page + 1}&per_page=${pageSize}&time_filter=${timeFilter}&location_filter=${locationFilter}`;

        console.log("Fetching from API:", apiUrl);

        try {
            const response = await fetch(apiUrl, {
                headers: { 'X-WP-Nonce': WishCartSettings.nonce }
            });

            if (!response.ok) {
                throw new Error(`API error: ${response.status}`);
            }

            const data = await response.json();
            console.log("API Response in React:", data);
            console.log("Value of data.total:", data.total);

            if (!data || !data.conversations || !Array.isArray(data.conversations)) {
                console.error("Invalid API response:", data);
                setError("Failed to load conversations.");
                return;
            }

            const conversationsWithIds = data.conversations.map(conv => ({
                ...conv,
                id: conv.conversation_id
            }));

            setConversations(conversationsWithIds);
            setTotalRows(data.total || 0);
            console.log("totalRows state after update:", totalRows); // Check again (may still be previous)

            if (data.locations && Array.isArray(data.locations)) {
                setAvailableLocations(data.locations);
            }
        } catch (error) {
            console.error('Error loading conversations:', error);
            setError(`Failed to load conversations: ${error.message}`);
        } finally {
            setLoading(false);
        }
    };

    return (
        <ThemeProvider theme={theme}>
            <Box sx={{ p: 3 }}>
                <Typography variant="h4" component="h1" gutterBottom>
                    {__('Chats', 'wish-cart')}
                </Typography>

                {/* Filters */}
                <Box sx={{ mb: 3, display: 'flex', gap: 2 }}>
                    <FormControl size="small" sx={{ width: 200 }}>
                        <InputLabel>{__('Time Period', 'wish-cart')}</InputLabel>
                        <Select
                            value={timeFilter}
                            label={__('Time Period', 'wish-cart')}
                            onChange={(e) => setTimeFilter(e.target.value)}
                        >
                            <MenuItem value="7days">{__('Past 7 Days', 'wish-cart')}</MenuItem>
                            <MenuItem value="30days">{__('Past 30 Days', 'wish-cart')}</MenuItem>
                            <MenuItem value="90days">{__('Past 90 Days', 'wish-cart')}</MenuItem>
                        </Select>
                    </FormControl>

                    <FormControl size="small" sx={{ width: 200 }}>
                        <InputLabel>{__('Location', 'wish-cart')}</InputLabel>
                        <Select
                            value={locationFilter}
                            label={__('Location', 'wish-cart')}
                            onChange={(e) => setLocationFilter(e.target.value)}
                        >
                            <MenuItem value="all">{__('All Locations', 'wish-cart')}</MenuItem>
                            {availableLocations.map(location => (
                                <MenuItem key={location} value={location.toLowerCase()}>
                                    {location}
                                </MenuItem>
                            ))}
                        </Select>
                    </FormControl>
                </Box>

                <Stack spacing={2}>
                    <Box sx={{ height: 600, width: '100%' }}>
                        <DataGrid
                            rows={conversations}
                            columns={columns}
                            pagination
                            paginationMode="server" // Enables server-side pagination
                            rowCount={totalRows} // Ensures total row count updates dynamically
                            pageSize={pageSize}
                            page={page}
                            rowsPerPageOptions={[3, 5, 10, 25, 50]} // Changed default to include 3
                            onPageChange={(newPage) => {
                                console.log("Page changed to:", newPage);
                                setPage(newPage);
                            }}
                            onPageSizeChange={(newPageSize) => {
                                console.log("Page size changed to:", newPageSize);
                                setPageSize(newPageSize);
                                setPage(0); // Reset to first page when changing page size
                            }}
                            loading={loading}
                            getRowId={(row) => row.conversation_id || row.id}
                            components={{
                                Pagination: CustomPagination,
                            }}
                            disableSelectionOnClick
                            disableColumnMenu
                            sx={{
                                '& .MuiDataGrid-cell': {
                                    borderBottom: 1,
                                    borderColor: 'divider',
                                },
                                '& .MuiDataGrid-row:hover': {
                                    backgroundColor: 'action.hover',
                                }
                            }}
                        />
                    </Box>

                    {selectedChat && (
                        <Box
                            sx={{
                                position: 'fixed',
                                top: 0,
                                left: 0,
                                right: 0,
                                bottom: 0,
                                backgroundColor: 'rgba(0, 0, 0, 0.5)',
                                zIndex: 1000,
                                display: 'flex',
                                justifyContent: 'center',
                                alignItems: 'center'
                            }}
                            onClick={(e) => {
                                if (e.target === e.currentTarget) {
                                    setSelectedChat(null);
                                }
                            }}
                        >
                            <Box onClick={(e) => e.stopPropagation()}>
                                <ChatWidget
                                    ref={chatWidgetRef}
                                    key={selectedChat.conversation_id}
                                    conversationId={selectedChat.conversation_id}
                                    readOnly={true}
                                />
                            </Box>
                        </Box>
                    )}
                    {error && (
                        <Box sx={{ p: 2, color: 'error.main' }}>
                            {error}
                        </Box>
                    )}
                </Stack>
            </Box>
        </ThemeProvider>
    );
};

export default ChatAdminDashboard;