import React, { useState, useEffect } from 'react';
import { TrendingUp, Heart, ShoppingCart, Share2, Users, BarChart } from 'lucide-react';
import { Card, CardContent } from '../../components/ui/card';
import '../../styles/Analytics.scss';

export const AnalyticsDashboard = () => {
    const [overview, setOverview] = useState(null);
    const [popularProducts, setPopularProducts] = useState([]);
    const [conversionData, setConversionData] = useState(null);
    const [isLoading, setIsLoading] = useState(true);

    const apiUrl = window.WishCartSettings?.apiUrl || '/wp-json/wishcart/v1/';
    const nonce = window.WishCartSettings?.nonce;

    useEffect(() => {
        fetchAnalytics();
    }, []);

    const fetchAnalytics = async () => {
        setIsLoading(true);
        try {
            // Fetch overview
            const overviewRes = await fetch(`${apiUrl}analytics/overview`, {
                headers: { 'X-WP-Nonce': nonce },
            });
            if (overviewRes.ok) {
                const overviewData = await overviewRes.json();
                setOverview(overviewData.data);
            }

            // Fetch popular products
            const popularRes = await fetch(`${apiUrl}analytics/popular?limit=10`, {
                headers: { 'X-WP-Nonce': nonce },
            });
            if (popularRes.ok) {
                const popularData = await popularRes.json();
                setPopularProducts(popularData.products || []);
            }

            // Fetch conversion funnel
            const conversionRes = await fetch(`${apiUrl}analytics/conversion`, {
                headers: { 'X-WP-Nonce': nonce },
            });
            if (conversionRes.ok) {
                const conversionData = await conversionRes.json();
                setConversionData(conversionData.data);
            }
        } catch (err) {
            console.error('Error fetching analytics:', err);
        } finally {
            setIsLoading(false);
        }
    };

    if (isLoading) {
        return (
            <div className="analytics-dashboard">
                <div className="loading-state">
                    <div className="spinner"></div>
                    <p>Loading analytics...</p>
                </div>
            </div>
        );
    }

    return (
        <div className="analytics-dashboard">
            <div className="dashboard-header">
                <h2>Wishlist Analytics</h2>
                <button onClick={fetchAnalytics} className="refresh-button">
                    Refresh Data
                </button>
            </div>

            {/* Overview Cards */}
            {overview && (
                <div className="overview-grid">
                    <Card className="stat-card">
                        <CardContent>
                            <div className="stat-icon wishlist">
                                <Heart size={24} />
                            </div>
                            <div className="stat-content">
                                <p className="stat-label">Total Wishlists</p>
                                <p className="stat-value">{overview.total_wishlists || 0}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="stat-card">
                        <CardContent>
                            <div className="stat-icon items">
                                <BarChart size={24} />
                            </div>
                            <div className="stat-content">
                                <p className="stat-label">Total Items</p>
                                <p className="stat-value">{overview.total_items || 0}</p>
                                <p className="stat-meta">Avg: {overview.avg_items_per_wishlist || 0} per wishlist</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="stat-card">
                        <CardContent>
                            <div className="stat-icon conversion">
                                <ShoppingCart size={24} />
                            </div>
                            <div className="stat-content">
                                <p className="stat-label">Total Purchases</p>
                                <p className="stat-value">{overview.total_purchases || 0}</p>
                                <p className="stat-meta">Conversion: {overview.overall_conversion_rate || 0}%</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="stat-card">
                        <CardContent>
                            <div className="stat-icon shares">
                                <Share2 size={24} />
                            </div>
                            <div className="stat-content">
                                <p className="stat-label">Total Shares</p>
                                <p className="stat-value">{overview.total_shares || 0}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            )}

            {/* Conversion Funnel */}
            {conversionData && (
                <Card className="funnel-card">
                    <CardContent>
                        <h3>Conversion Funnel</h3>
                        <div className="funnel-visualization">
                            <div className="funnel-stage">
                                <div className="funnel-bar" style={{ width: '100%' }}>
                                    <span className="funnel-label">Added to Wishlist</span>
                                    <span className="funnel-value">{conversionData.added_to_wishlist || 0}</span>
                                </div>
                            </div>
                            <div className="funnel-stage">
                                <div className="funnel-bar" style={{ width: '85%' }}>
                                    <span className="funnel-label">Clicked</span>
                                    <span className="funnel-value">{conversionData.clicked || 0}</span>
                                </div>
                            </div>
                            <div className="funnel-stage">
                                <div className="funnel-bar" style={{ width: `${conversionData.wishlist_to_cart_rate || 0}%` }}>
                                    <span className="funnel-label">Added to Cart</span>
                                    <span className="funnel-value">{conversionData.added_to_cart || 0}</span>
                                    <span className="funnel-rate">{conversionData.wishlist_to_cart_rate || 0}%</span>
                                </div>
                            </div>
                            <div className="funnel-stage">
                                <div className="funnel-bar" style={{ width: `${conversionData.overall_conversion_rate || 0}%` }}>
                                    <span className="funnel-label">Purchased</span>
                                    <span className="funnel-value">{conversionData.purchased || 0}</span>
                                    <span className="funnel-rate">{conversionData.overall_conversion_rate || 0}%</span>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Popular Products */}
            {popularProducts.length > 0 && (
                <Card className="popular-products-card">
                    <CardContent>
                        <h3>Most Wishlisted Products</h3>
                        <div className="products-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Wishlist Count</th>
                                        <th>Add to Cart</th>
                                        <th>Purchases</th>
                                        <th>Conversion Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {popularProducts.map((product) => (
                                        <tr key={product.product_id}>
                                            <td>
                                                <a href={product.product_url} target="_blank" rel="noopener noreferrer">
                                                    {product.product_name}
                                                </a>
                                            </td>
                                            <td>
                                                <span className="badge">{product.wishlist_count}</span>
                                            </td>
                                            <td>{product.add_to_cart_count}</td>
                                            <td>{product.purchase_count}</td>
                                            <td>
                                                <span className={`conversion-badge ${product.conversion_rate > 10 ? 'high' : ''}`}>
                                                    {product.conversion_rate}%
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            )}
        </div>
    );
};

