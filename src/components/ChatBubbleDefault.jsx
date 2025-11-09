import React, { useState, useEffect } from 'react';

const ChatBubbleDefault = ({ onClick, settings }) => {
  const [showWave, setShowWave] = useState(true);
  const [playSound, setPlaySound] = useState(true);


  console.log("ChatBubbleDefault: ", settings);

  useEffect(() => {
    // Start waving animation after a delay
    const timer = setTimeout(() => {
      setShowWave(true);
      if (settings.soundEnabled) {
        setPlaySound(true);
      }
    }, 2000);

    return () => clearTimeout(timer);
  }, [settings.soundEnabled]);

  useEffect(() => {
    if (playSound && settings.soundEnabled) {
      const audio = new Audio(settings.soundUrl || '/notification.mp3');
      audio.volume = 0.5;
      audio.play().catch(e => console.log('Audio play failed:', e));
      setPlaySound(false);
    }
  }, [playSound, settings.soundEnabled, settings.soundUrl]);

  return (
    <div className="chat-bubble-container" onClick={onClick}>
      {/* Chat bubble with icon */}
      <div className="chat-bubble" style={{ backgroundColor: settings.widgetColor || '#1976d2' }}>
        <img
          src={settings.chatIcon || `${window.AiskData.pluginUrl}assets/images/icons/message-square.svg`}
          alt="Chat"
          width="24"
          height="24"
        />
      </div>

      {/* Caption with waving hand */}
      <div className="chat-caption">
        <span className="caption-text">{settings.captionText || "👋 Hey, need help? I'm here to help you!"}</span>
      </div>

      <style jsx>{`
        .chat-bubble-container {
          position: relative;
          cursor: pointer;
        }

        .chat-bubble {
          width: 60px;
          height: 60px;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
          transition: transform 0.3s ease;
        }

        .chat-bubble:hover {
          transform: scale(1.1);
        }

        .chat-caption {
          position: absolute;
          right: 75px;
          top: 33%;
          transform: translateY(-50%);
          background: white;
          padding: 8px 16px;
          border-radius: 20px;
          box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
          white-space: nowrap;
          display: flex;
          align-items: center;
          gap: 8px;
          opacity: 0;
          animation: fadeIn 0.5s ease forwards;
          animation-delay: 1s;
        }

        .chat-caption::after {
          content: '';
          position: absolute;
          right: -6px;
          top: 50%;
          transform: translateY(-50%);
          border-left: 8px solid white;
          border-top: 8px solid transparent;
          border-bottom: 8px solid transparent;
        }

        .wave-hand {
          display: inline-block;
          font-size: 18px;
        }

        .wave-hand.wave {
          animation: wave 2s infinite;
        }

        .caption-text {
          font-size: 14px;
          color: #333;
        }

        @keyframes wave {
          0%, 100% { transform: rotate(0deg); }
          25% { transform: rotate(-20deg); }
          75% { transform: rotate(20deg); }
        }

        @keyframes fadeIn {
          from { opacity: 0; transform: translate(-20px, -50%); }
          to { opacity: 1; transform: translate(0, -50%); }
        }
      `}</style>
    </div>
  );
};

export default ChatBubbleDefault;