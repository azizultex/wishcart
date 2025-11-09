import React, { useEffect, useRef, useState } from 'react';
import '../../styles/features/ContactForm.scss';

const ContactForm = () => {
    const iframeRef = useRef(null);
    const formUrl = window.WishCartData?.contactFormUrl;
    const [iframeLoaded, setIframeLoaded] = useState(false);
    const [iframeHeight, setIframeHeight] = useState(450); // Default height
    const [showSuccessMessage, setShowSuccessMessage] = useState(false);
    const resizeHandlerRef = useRef(null);

    useEffect(() => {
        if (!iframeRef.current) return;

        resizeHandlerRef.current = (event) => {
            try {
                // Only accept messages from the form's origin
                if (event.origin !== new URL(formUrl).origin) return;

                const data = event.data;

                // Handle form submission success
                if (data.type === 'formSubmission') {
                    setShowSuccessMessage(true);
                    return;
                }

                // Handle iframe resize
                if (!data || typeof data !== 'object' || !('frameHeight' in data)) return;

                const height = parseInt(data.frameHeight);
                if (isNaN(height) || height < 450 || height > 800) return; // Enforce min/max height

                // Only update if height change is significant (more than 10px)
                if (Math.abs(height - iframeHeight) > 10) {
                    setIframeHeight(height);
                }
            } catch (error) {
                console.error('Error handling iframe message:', error);
            }
        };

        window.addEventListener('message', resizeHandlerRef.current);

        return () => {
            if (resizeHandlerRef.current) {
                window.removeEventListener('message', resizeHandlerRef.current);
            }
        };
    }, [formUrl, iframeHeight]);

    const handleIframeLoad = () => {
        setIframeLoaded(true);
    };

    if (!formUrl) return null;

    return (
        <div className="contact-form">
            <div className="header">
                <h3>Contact Support</h3>
                <p>Please fill out this form to get in touch with our support team directly:</p>
            </div>
            <div className="form-container">
                {!iframeLoaded && (
                    <div className="loading">
                        <div className="spinner"></div>
                    </div>
                )}
                {showSuccessMessage ? (
                    <div className="success-message">
                        Thanks for contacting us! We will be in touch with you shortly.
                    </div>
                ) : (
                    <iframe
                        ref={iframeRef}
                        src={formUrl}
                        title="Contact Form"
                        onLoad={handleIframeLoad}
                        style={{ height: `${iframeHeight}px` }}
                    />
                )}
            </div>
        </div>
    );
};

export default ContactForm;