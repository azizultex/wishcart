import React from 'react';
import '../../styles/features/ChatIntegrations.scss';

const ChatIntegrations = ({ isOpen = false, whatsappNumber, telegramUsername,  whatsappEnabled, telegramEnabled}) => {
    const openWhatsApp = (e) => {
        e.preventDefault();
        if (!whatsappEnabled || !whatsappNumber) return;
        const formattedNumber = whatsappNumber.replace('whatsapp:', '').replace(/[^0-9]/g, '');
        window.open(`https://wa.me/${formattedNumber}`, '_blank');
    };

    const openTelegram = (e) => {
        e.preventDefault();
        if (!telegramEnabled || !telegramUsername) return;
        window.open(`https://t.me/${telegramUsername}`, '_blank');
    };

    return (
        <div className="chat-integrations">
            {whatsappEnabled && whatsappNumber && (
                <a href="#" onClick={openWhatsApp} className="chat-integration-link whatsapp" title="Chat on WhatsApp">
                    <img src={`${WishCartData.pluginUrl}assets/images/whatsapp.svg`} alt="WhatsApp" />
                </a>
            )}
            {telegramEnabled && telegramUsername && (
                <a href="#" onClick={openTelegram} className="chat-integration-link telegram" title="Chat on Telegram">
                    <img src={`${WishCartData.pluginUrl}assets/images/telegram.svg`} alt="Telegram" />
                </a>
            )}
        </div>
    );
};

export default ChatIntegrations;