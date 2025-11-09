import React, { useState, useEffect, useRef, use } from 'react';
import '../styles/widget-position.scss';

const ChatBubbleRollingMessage = ({ onClick, settings }) => {
    const [currentMessageIndex, setCurrentMessageIndex] = useState(0);
    const [showMessage, setShowMessage] = useState(false);
    const [playSound, setPlaySound] = useState(false);
    const [messagePosition, setMessagePosition] = useState({ right: 80 });
    const messageRef = useRef(null);
    const [widgetPosition, setWidgetPosition] = useState('bottom-right'); 

    const messages = settings.messages || [
        "👋 Need help?",
        "💬 Chat with us!",
        "🛍️ Find products"
    ];

    // Calculate message position based on its width
    useEffect(() => {
        const updatePosition = () => {
            if (messageRef.current) {
                const messageWidth = messageRef.current.offsetWidth;
                const bubbleWidth = 80; // Width of the chat bubble container
                const spacing = 16; // Spacing between bubble and message
                const newRight = bubbleWidth + spacing;
                
                if('bottom-left' == widgetPosition || 'top-left' == widgetPosition) {
                  setMessagePosition({ left: newRight });
                } else {
                  setMessagePosition({ right: newRight });
                }
            }
        };

        // Update position when message changes or becomes visible
        if (showMessage) {
            updatePosition();
        }

        // Handle window resize
        window.addEventListener('resize', updatePosition);
        return () => window.removeEventListener('resize', updatePosition);
    }, [showMessage, messages[currentMessageIndex], widgetPosition]);

    useEffect(() => {
        const initialTimer = setTimeout(() => {
            setShowMessage(true);
            if (settings.soundEnabled) {
                setPlaySound(true);
            }
        }, 1000);

        const rotationTimer = setInterval(() => {
            setShowMessage(false);
            setCurrentMessageIndex((prev) => (prev + 1) % messages.length);

            setTimeout(() => {
                setShowMessage(true);
            }, 100);
        }, 4000);

        return () => {
            clearTimeout(initialTimer);
            clearInterval(rotationTimer);
        };
    }, [settings.soundEnabled, messages.length]);

    useEffect(() => {
        if (playSound && settings.soundEnabled) {
            const audio = new Audio(settings.soundUrl || '/notification.mp3');
            audio.volume = 0.5;
            audio.play().catch(e => console.log('Audio play failed:', e));
            setPlaySound(false);
        }
    }, [playSound, settings.soundEnabled, settings.soundUrl]);

    useEffect(() => {
        setWidgetPosition(settings.widgetPosition);
    }, [settings.widgetPosition]);

    return (
        <div className={`modern-chat-container ${widgetPosition}`} onClick={onClick}>
            {/* Single message display */}
            <div className="floating-messages" style={messagePosition}>
                <div
                    ref={messageRef}
                    className={`floating-message ${showMessage ? 'show' : 'hide'}`}
                    style={{ backgroundColor: `#FFF` }}
                >
                    {messages[currentMessageIndex]}
                </div>
            </div>

            {/* Main chat bubble */}
            <div className="modern-chat-bubble">
                <div className="pulse-ring ring1" style={{ borderColor: settings.widgetColor || '#1976d2' }}></div>
                <div className="pulse-ring ring2" style={{ borderColor: settings.widgetColor || '#1976d2' }}></div>

                <div className="glass-bubble" style={{ backgroundColor: `${settings.widgetColor}dd` || '#1976d2dd' }}>
                    <img
                        src={settings.chatIcon || `${window.AiskData.pluginUrl}assets/images/icons/message-square.svg`}
                        alt="Chat"
                        width="24"
                        height="24"
                        className="chat-icon"
                    />
                    <div className="status-dot"></div>
                </div>
            </div>

            <style jsx>{`
        .modern-chat-container {
          position: fixed;
          cursor: pointer;
          width: 80px;
          height: 80px;
          bottom: 20px;
          right: 20px;
          z-index: 9999;
        }

        .modern-chat-bubble {
          position: relative;
          width: 60px;
          height: 60px;
          margin: 10px;
        }

        .glass-bubble {
          position: absolute;
          width: 100%;
          height: 100%;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          backdrop-filter: blur(8px);
          -webkit-backdrop-filter: blur(8px);
          box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
          border: 1px solid rgba(255, 255, 255, 0.2);
          transition: transform 0.3s ease;
          z-index: 2;
        }

        .glass-bubble:hover {
          transform: scale(1.1) translateY(-5px);
        }

        .chat-icon {
          filter: brightness(0) invert(1);
          opacity: 0.9;
        }

        .status-dot {
          position: absolute;
          right: 4px;
          top: 4px;
          width: 8px;
          height: 8px;
          border-radius: 50%;
          background: #4CAF50;
          border: 2px solid white;
          animation: pulse 2s infinite;
        }

        .pulse-ring {
          position: absolute;
          border-radius: 50%;
          border-width: 2px;
          border-style: solid;
          opacity: 0;
          width: 100%;
          height: 100%;
          animation: pulsate 3s ease-out infinite;
        }

        .ring2 {
          animation-delay: 1s;
        }

        .floating-messages {
          position: absolute;
          bottom: 50%;
          transform: translateY(25%);
          z-index: 999;
          width: max-content;
          pointer-events: none;
          height: auto;
        }

        .floating-message {
          position: relative;
          background: rgba(255, 255, 255, 0.98);
          padding: 12px 20px;
          border-radius: 20px;
          font-size: 14px;
          white-space: nowrap;
          box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
          opacity: 0;
          transform: translateX(100%);
          transition: all 0.3s ease;
          will-change: transform, opacity;
        }

        .floating-message.show {
          opacity: 1;
          animation: rollUp 3s cubic-bezier(0.23, 1, 0.32, 1) forwards;
        }
        
        @keyframes rollUp {
          0% {
            transform: translateY(15px);
            opacity: 0;
          }
          15% {
            transform: translateY(0);
            opacity: 1;
          }
          85% {
            transform: translateY(0);
            opacity: 1;
          }
          100% {
            transform: translateY(-15px);
            opacity: 0;
          }
        }

        .floating-message::after {
          content: '';
          position: absolute;
          right: -6px;
          top: 50%;
          transform: translateY(-50%);
          border-left: 8px solid rgba(255, 255, 255, 0.98);
          border-top: 8px solid transparent;
          border-bottom: 8px solid transparent;
        }

        @keyframes pulsate {
          0% {
            transform: scale(1);
            opacity: 0.5;
          }
          100% {
            transform: scale(1.5);
            opacity: 0;
          }
        }

        @keyframes pulse {
          0% { opacity: 1; }
          50% { opacity: 0.5; }
          100% { opacity: 1; }
        }
        
        
        @media (max-width: 768px) {
          .floating-messages {
            bottom: 100%;
            right: 0 !important;
            transform: translateY(-20px);
          }

          .floating-message {
            text-align: right;
          }

          .floating-message::after {
            right: 20px;
            top: 100%;
            transform: rotate(90deg);
            margin-top: -4px;
          }
        }
        
      `}</style>
        </div>
    );
};

export default ChatBubbleRollingMessage;