import { __ } from '@wordpress/i18n';
import React, { useState, useEffect } from 'react';
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
    InputLabel,
    Card,
    CardContent,
    CardHeader,
    Grid,
    Tabs,
    Tab
} from '@mui/material';
import {
    BarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip as RechartsTooltip,
    ResponsiveContainer,
    LineChart,
    Line,
    PieChart,
    Pie,
    Cell
} from 'recharts';
import {
    TrendingUp,
    TrendingDown,
    DollarSign,
    Clock,
    AlertTriangle,
    CheckCircle,
    Activity,
    Users,
    MessageSquare,
    Zap
} from 'lucide-react';

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

// KPI Card Component
const KPICard = ({ title, value, change, icon: Icon, color = 'primary' }) => (
    <Card>
        <CardContent>
            <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <Box>
                    <Typography color="textSecondary" gutterBottom variant="h6">
                        {title}
                    </Typography>
                    <Typography variant="h4" component="div">
                        {value}
                    </Typography>
                    {change && (
                        <Box sx={{ display: 'flex', alignItems: 'center', mt: 1 }}>
                            {change > 0 ? (
                                <TrendingUp size={16} color="green" />
                            ) : (
                                <TrendingDown size={16} color="red" />
                            )}
                            <Typography
                                variant="body2"
                                color={change > 0 ? 'success.main' : 'error.main'}
                                sx={{ ml: 0.5 }}
                            >
                                {Math.abs(change)}%
                            </Typography>
                        </Box>
                    )}
                </Box>
                <Icon size={40} color={color} />
            </Box>
        </CardContent>
    </Card>
);

// Status Chip Component
const StatusChip = ({ status }) => {
    const getColor = (status) => {
        switch (status?.toLowerCase()) {
            case 'success':
            case 'completed':
                return 'success';
            case 'error':
            case 'failed':
                return 'error';
            case 'pending':
            case 'processing':
                return 'warning';
            default:
                return 'default';
        }
    };

    return (
        <Chip
            label={status}
            color={getColor(status)}
            size="small"
        />
    );
};

const UsesAnalytics = () => {
    const [activeTab, setActiveTab] = useState(0);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [timeFilter, setTimeFilter] = useState('7days');

    // State for different data
    const [overviewData, setOverviewData] = useState({
        totalRequests: 0,
        successRate: 0,
        avgLatency: 0,
        totalTokens: 0,
        errorRate: 0
    });

    const [usageData, setUsageData] = useState([]);
    
    const [errorData, setErrorData] = useState([]);
    const [costData, setCostData] = useState([]);


    useEffect(() => {
        loadAnalyticsData();
    }, [timeFilter]);

    const loadAnalyticsData = async () => {
        setLoading(true);
        try {
            // Load analytics data from API (overview, usage, errors, costs)
            const [overviewResponse, usageResponse, errorsResponse, costsResponse] = await Promise.all([
                fetch(`${AiskSettings.apiUrl}/analytics/overview?time_filter=${timeFilter}`, {
                    headers: { 'X-WP-Nonce': AiskSettings.nonce }
                }),
                fetch(`${AiskSettings.apiUrl}/analytics/usage?time_filter=${timeFilter}`, {
                    headers: { 'X-WP-Nonce': AiskSettings.nonce }
                }),
                fetch(`${AiskSettings.apiUrl}/analytics/errors?time_filter=${timeFilter}`, {
                    headers: { 'X-WP-Nonce': AiskSettings.nonce }
                }),
                fetch(`${AiskSettings.apiUrl}/analytics/costs?time_filter=${timeFilter}`, {
                    headers: { 'X-WP-Nonce': AiskSettings.nonce }
                })
            ]);

            const [overview, usage, errors, costs] = await Promise.all([
                overviewResponse.json(),
                usageResponse.json(),
                errorsResponse.json(),
                costsResponse.json()
            ]);

            setOverviewData(overview);
            setUsageData(usage);
            setErrorData(errors);
            setCostData(Array.isArray(costs) ? costs : []);
        } catch (error) {
            console.error('Error loading analytics data:', error);
            setError('Failed to load analytics data');
        } finally {
            setLoading(false);
        }
    };

    const handleTabChange = (event, newValue) => {
        setActiveTab(newValue);
    };

    const COLORS = ['#2271b1', '#00a32a', '#dba617', '#d63638', '#826eb4'];

    const renderOverviewTab = () => (
        <Box>
            {/* KPI Cards */}
            <Grid container spacing={3} sx={{ mb: 3 }}>
                <Grid item xs={12} sm={6} md={2}>
                    <KPICard
                        title={__('Total Requests', 'aisk-ai-chat-for-fluentcart')}
                        value={overviewData.totalRequests.toLocaleString()}
                        change={12.5}
                        icon={Activity}
                        color="#2271b1"
                    />
                </Grid>
                <Grid item xs={12} sm={6} md={2}>
                    <KPICard
                        title={__('Chat Requests', 'aisk-ai-chat-for-fluentcart')}
                        value={(overviewData.chatRequests ?? 0).toLocaleString()}
                        change={0}
                        icon={MessageSquare}
                        color="#2271b1"
                    />
                </Grid>
                <Grid item xs={12} sm={6} md={2}>
                    <KPICard
                        title={__('Success Rate', 'aisk-ai-chat-for-fluentcart')}
                        value={`${overviewData.successRate}%`}
                        change={2.1}
                        icon={CheckCircle}
                        color="#00a32a"
                    />
                </Grid>
                
                <Grid item xs={12} sm={6} md={2}>
                    <KPICard
                        title={__('Total Tokens', 'aisk-ai-chat-for-fluentcart')}
                        value={overviewData.totalTokens.toLocaleString()}
                        change={15.3}
                        icon={Zap}
                        color="#826eb4"
                    />
                </Grid>
                <Grid item xs={12} sm={6} md={2}>
                    <KPICard
                        title={__('Error Rate', 'aisk-ai-chat-for-fluentcart')}
                        value={`${overviewData.errorRate}%`}
                        change={-1.2}
                        icon={AlertTriangle}
                        color="#d63638"
                    />
                </Grid>
                <Grid item xs={12} sm={6} md={2}>
                    <KPICard
                        title={__('Cost', 'aisk-ai-chat-for-fluentcart')}
                        value={`$${(costData.reduce((sum, d) => sum + (parseFloat(d.total ?? 0) || 0), 0)).toFixed(2)}`}
                        change={0}
                        icon={DollarSign}
                        color="#826eb4"
                    />
                </Grid>
            </Grid>

            {/* Charts */}
            <Grid container spacing={3}>
                <Grid item xs={12} md={8}>
                    <Card>
                        <CardHeader title={<Typography variant="h6">{__('Usage Over Time', 'aisk-ai-chat-for-fluentcart')}</Typography>} />
                        <CardContent>
                            <ResponsiveContainer width="100%" height={300}>
                                <LineChart data={usageData}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="date" />
                                    <YAxis />
                                    <RechartsTooltip />
                                    <Line type="monotone" dataKey="requests" stroke="#2271b1" strokeWidth={2} />
                                </LineChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                </Grid>
                
            </Grid>
        </Box>
    );

    // Removed Providers & Models and Features & Channels tabs per request

    const renderErrorsTab = () => (
        <Box>
            <Grid container spacing={3}>
                <Grid item xs={12} md={6}>
                    <Card>
                        <CardHeader title={<Typography variant="h6">{__('Error Distribution', 'aisk-ai-chat-for-fluentcart')}</Typography>} />
                        <CardContent>
                            <ResponsiveContainer width="100%" height={300}>
                                <PieChart>
                                    <Pie
                                        data={errorData}
                                        cx="50%"
                                        cy="50%"
                                        labelLine={false}
                                        label={({ error, percentage }) => `${error} ${percentage}%`}
                                        outerRadius={80}
                                        fill="#8884d8"
                                        dataKey="count"
                                    >
                                        {errorData.map((entry, index) => (
                                            <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                                        ))}
                                    </Pie>
                                    <RechartsTooltip />
                                </PieChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                </Grid>
                <Grid item xs={12} md={6}>
                    <Card>
                        <CardHeader title={<Typography variant="h6">{__('Error Details', 'aisk-ai-chat-for-fluentcart')}</Typography>} />
                        <CardContent>
                            <Stack spacing={2}>
                                {errorData.map((error, index) => (
                                    <Box key={index} sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                        <Typography variant="body2">{error.error}</Typography>
                                        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                                            <Typography variant="body2" color="textSecondary">
                                                {error.count} ({error.percentage}%)
                                            </Typography>
                                            <StatusChip status="error" />
                                        </Box>
                                    </Box>
                                ))}
                            </Stack>
                        </CardContent>
                    </Card>
                </Grid>
            </Grid>
        </Box>
    );

    // Removed cost tab per request

    return (
        <ThemeProvider theme={theme}>
            <Box sx={{ p: 3 }}>
                <Typography variant="h4" component="h1" gutterBottom>
                    {__('API Usage Analytics', 'aisk-ai-chat-for-fluentcart')}
                </Typography>

                {/* Filters */}
                <Box sx={{ mb: 3, display: 'flex', gap: 2 }}>
                    <FormControl size="small" sx={{ width: 200 }}>
                        <InputLabel>{__('Time Period', 'aisk-ai-chat-for-fluentcart')}</InputLabel>
                        <Select
                            value={timeFilter}
                            label={__('Time Period', 'aisk-ai-chat-for-fluentcart')}
                            onChange={(e) => setTimeFilter(e.target.value)}
                        >
                            <MenuItem value="7days">{__('Past 7 Days', 'aisk-ai-chat-for-fluentcart')}</MenuItem>
                            <MenuItem value="30days">{__('Past 30 Days', 'aisk-ai-chat-for-fluentcart')}</MenuItem>
                            <MenuItem value="90days">{__('Past 90 Days', 'aisk-ai-chat-for-fluentcart')}</MenuItem>
                        </Select>
                    </FormControl>
                </Box>

                {/* Tabs */}
                <Box sx={{ borderBottom: 1, borderColor: 'divider', mb: 3 }}>
                    <Tabs value={activeTab} onChange={handleTabChange}>
                        <Tab label={__('Overview', 'aisk-ai-chat-for-fluentcart')} />
                        <Tab label={__('Errors', 'aisk-ai-chat-for-fluentcart')} />
                    </Tabs>
                </Box>

                {/* Tab Content */}
                {activeTab === 0 && renderOverviewTab()}
                {activeTab === 1 && renderErrorsTab()}
                

                {error && (
                    <Box sx={{ p: 2, color: 'error.main' }}>
                        {error}
                    </Box>
                )}
            </Box>
        </ThemeProvider>
    );
};

export default UsesAnalytics;
