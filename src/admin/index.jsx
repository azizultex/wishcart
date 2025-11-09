import React from 'react';
import { StyledEngineProvider } from '@mui/material';
import { createRoot } from 'react-dom/client';
import ChatAdminDashboard from './components/dashboard/ChatAdminDashboard';
import SettingsApp from './components/settings/SettingsApp';
import InquiriesPage from './components/dashboard/InquiriesPage';
import InquiryDetails from './components/dashboard/InquiryDetails';
import UsesAnalytics from './components/dashboard/UsesAnalytics';
import './styles/main.scss';
document.addEventListener('DOMContentLoaded', () => {
    // Mount admin dashboard
    const dashboardContainer = document.getElementById('wishcart-history');
    if (dashboardContainer) {
        const dashboardRoot = createRoot(dashboardContainer);
        dashboardRoot.render(<ChatAdminDashboard />);
    }

    // Mount settings page
    const settingsContainer = document.getElementById('wishcart-settings-app');
    if (settingsContainer) {
        const settingsRoot = createRoot(settingsContainer);
        settingsRoot.render(<SettingsApp />);
    }

    // Mount inquiries page
    const inquiriesContainer = document.getElementById('wishcart-inquiries');
    if (inquiriesContainer) {
        const urlParams = new URLSearchParams(window.location.search);
        const view = urlParams.get('view');
        const inquiryId = urlParams.get('id');

        const inquiriesRoot = createRoot(inquiriesContainer);
        inquiriesRoot.render(
            <StyledEngineProvider injectFirst>
                {view === 'details' ? (
                    <InquiryDetails inquiryId={inquiryId} />
                ) : (
                    <InquiriesPage />
                )}
            </StyledEngineProvider>
        );
    }

    // Mount uses analytics page
    const usesContainer = document.getElementById('wishcart-uses');
    if (usesContainer) {
        const usesRoot = createRoot(usesContainer);
        usesRoot.render(
            <StyledEngineProvider injectFirst>
                <UsesAnalytics />
            </StyledEngineProvider>
        );
    }
});