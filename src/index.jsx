import React from 'react';
import ReactDOM from 'react-dom';
import ChatWidget from './components/ChatWidget';

window.addEventListener('load', () => {
    const container = document.getElementById('aisk-chat-widget');
    if (container) {
        ReactDOM.render(<ChatWidget />, container);
    }
});