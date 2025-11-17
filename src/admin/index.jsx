import React from 'react';
import { createRoot } from 'react-dom/client';
import SettingsApp from './components/settings/SettingsApp';
import './styles/main.scss';

document.addEventListener('DOMContentLoaded', () => {
    // Mount settings page
    const settingsContainer = document.getElementById('wishcart-settings-app');
    if (settingsContainer) {
        const settingsRoot = createRoot(settingsContainer);
        settingsRoot.render(<SettingsApp />);
    }
});