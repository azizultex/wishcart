import React, { useState, useRef, useEffect, useCallback } from 'react';
import { ChevronDown, Send, MessageCircle, History, MessagesSquare } from 'lucide-react';
import ProductCarousel from './ProductCarousel';
import OrderStatus from './OrderStatus';
import ConversationList from './ConversationList';
import ChatBubbleRollingMessage from './ChatBubbleRollingMessage';
import ChatBubbleDefault from './ChatBubbleDefault';
import ContactForm from "./features/ContactForm";
import ChatIntegrations from './features/ChatIntegrations';
import formatMessageTimestamp from './utils/formatMessageTimestamp';
import '../styles/ChatWidget.scss';

const ChatWidget = React.forwardRef((props, ref) => {
    const [isOpen, setIsOpen] = useState(false);
    const [messages, setMessages] = useState([]);
    const [inputValue, setInputValue] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [conversationId, setConversationId] = useState(null);
    const [conversations, setConversations] = useState([]);
    const [showConversations, setShowConversations] = useState(false);
    const messagesEndRef = useRef(null);
    const messagesContainerRef = useRef(null);
    const [isInitialized, setIsInitialized] = useState(false);
    const [widgetPosition, setWidgetPosition] = useState('bottom-right');
    const [widgetPlaceholder, setWidgetPlaceholder] = useState('Type your message...');
    const [isInitializingConversation, setIsInitializingConversation] = useState(false);
    const isSendingMessage = useRef(false);
    const initializationPromise = useRef(null);
    const [loadedOnInit, setLoadedOnInit] = useState(false);
	const [isLoadingConversationsList, setIsLoadingConversationsList] = useState(false);
		const [isLoadingMessages, setIsLoadingMessages] = useState(false);
		const [loadingConversationId, setLoadingConversationId] = useState(null);
		const [hasLoadedConversations, setHasLoadedConversations] = useState(false);

    const {
        chatwidget: {
            widget_color: color = '#4F46E5',
            widget_logo: widgetLogo = '',
            widget_text: widgetText = '',
            widget_greeting: widgetGreeting = '',
            suggested_questions: suggestedQuestions = [],
            chat_icon: chatIcon,
            bubble_type: bubbleType = 'default',
            rolling_messages: rollingMessages = [
                "👋 Need help?",
                "💬 Chat with us!",
                "🛍️ Find products"
            ],
            default_message: defaultMessage = "Hey, need help? 👋"
        } = {},
        integrations: {
            whatsapp: {
                enabled: whatsappEnabled = false,
                phone_number: whatsappNumber = ''
            } = {},
            telegram: {
                enabled: telegramEnabled = false,
                bot_username: telegramUsername = ''
            } = {}
        } = {},
        colors = {
            primary: '#4F46E5',
            secondary: '#E0E7FF',
            text: '#FFFFFF'
        },
        pluginUrl
    } = window.AiskData || {};

    const defaultIcon = `${pluginUrl}assets/images/icons/message-square.svg`;
    const finalChatIcon = chatIcon || defaultIcon;

    const styles = {
        headerBg: { backgroundColor: color },
        headerText: { color: colors.text },
        suggestedQuestion: {
            backgroundColor: colors.secondary,
            color: color
        },
        primaryButton: {
            backgroundColor: color,
            color: colors.text
        }
    };

    // Scroll functionality with smooth behavior
    const scrollToBottom = useCallback(() => {
        setTimeout(() => {
            const messagesContainer = messagesContainerRef.current;
            if (messagesContainer) {
                messagesContainer.scrollTo({
                    top: messagesContainer.scrollHeight,
                    behavior: 'smooth'
                });
            }
        }, 100); // small delay for smoother scrolling
    }, []);

    // Initialize conversation and load messages
    const initializeWidget = useCallback(async () => {
        try {
            const storedConversationId = localStorage.getItem('wooai_conversation_id');

            if (storedConversationId && storedConversationId !== 'undefined') {
                try {
                    const messages = await loadMessages(storedConversationId);
                    if (messages && messages.length > 0) {
                        setMessages(messages);
                        setConversationId(storedConversationId);
                        // Mark that messages were loaded during initialization
                        setLoadedOnInit(true);
                    } else {
                        // If no messages found, create a new conversation
                        await initializeConversation();
                    }
                } catch (error) {
                    console.error('Error loading messages for stored conversation:', error);
                    // If there's an error, create a new conversation
                    await initializeConversation();
                }
            } else {
                // No stored conversation ID, create a new one
                await initializeConversation();
            }
            setIsInitialized(true);
        } catch (error) {
            console.error('Error initializing widget:', error);
            try {
                await initializeConversation();
            } catch (initError) {
                console.error('Error creating new conversation:', initError);
            }
            setIsInitialized(true);
        }
    }, []);

    // Initial setup effect
    useEffect(() => {
        initializeWidget();
    }, [initializeWidget]);

	// Load messages when chat is opened
    useEffect(() => {
        // Skip loading messages if we just started a new conversation
        const isNewConversation = localStorage.getItem('is_new_conversation') === 'true';
        // Also skip once if messages were already loaded on initialization
        if (loadedOnInit) {
            // setLoadedOnInit(false); // clear the one-time flag
            return;
        }
		if (isOpen && conversationId && isInitialized && !isSendingMessage.current && !isNewConversation && !isLoadingMessages) { 
			setIsLoadingMessages(true);
			loadMessages(conversationId).then(messages => {
                if (messages && messages.length > 0) {
                    setMessages(messages);
                }
			}).catch(error => {
                console.error('Error loading messages:', error);
            }).finally(() => {
                setIsLoadingMessages(false);
            });
        }
        // Clear the new conversation flag
        if (isNewConversation) {
            localStorage.removeItem('is_new_conversation');
        }
	}, [isOpen, conversationId, isInitialized, loadedOnInit]);

	// Do not auto-fetch conversations on conversationId change to avoid redundant fetches

    // Scroll effect
    useEffect(() => {
        if (!isLoading && messages.length > 0) {
            scrollToBottom();
        }
    }, [messages, isLoading, scrollToBottom]);

    // Expose methods to parent
    React.useImperativeHandle(ref, () => ({
        open: () => setIsOpen(true),
        close: () => setIsOpen(false)
    }));

    const loadMessages = async (convId) => {
        if (!convId) return [];

        try {
            const response = await fetch(`${AiskData.apiUrl}/messages/${convId}`, {
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce
                }
            });

            if (!response.ok) {
                console.error('Failed to load messages:', response.status);
                return [];
            }

            const data = await response.json();

            if (!Array.isArray(data)) {
                console.error('Invalid message data format:', data);
                return [];
            }

            const formattedMessages = data.map(message => {
                let metadata = {};
                try {
                    metadata = message.metadata ? JSON.parse(message.metadata) : {};
                } catch (e) {
                    console.error('Error parsing metadata:', e);
                }

                return {
                    type: message.message_type,
                    messageType: message.message_type,
                    content: message.message,
                    timestamp: message.created_at,
                    // Extract metadata fields
                    products: metadata.products || null,
                    order: metadata.order || null,
                    support: metadata.support || false,
                    response_type: metadata.response_type || metadata.type || null,
                    contact_info: metadata.contact_info || null,
                    form_fields: metadata.form_fields || null
                };
            });

            return formattedMessages;
        } catch (error) {
            console.error('Error loading messages:', error);
            return [];
        }
    };

	const selectConversation = async (convId) => {
        if (!convId) return;

        // Clear existing messages first
        setMessages([]);
		setLoadingConversationId(convId);

        // Set the new conversation ID
        setConversationId(convId);
        localStorage.setItem('wooai_conversation_id', convId);

        // Load messages for the selected conversation
		setIsLoadingMessages(true);
		try {
			const loadedMessages = await loadMessages(convId);
			if (loadedMessages.length > 0) {
				setMessages(loadedMessages);
			}
		} catch (error) {
			console.error('Error loading messages for conversation:', error);
		} finally {
			setIsLoadingMessages(false);
			setLoadingConversationId(null);
		}

        setShowConversations(false);
		setIsOpen(true);
        // Scroll to bottom after loading messages
        scrollToBottom();
    };

    const initializeNewConversation = async () => {
        try {
            const locationData = await getLocationData();
            const requestData = {
                url: window.location.href,
                userAgent: navigator.userAgent,
                city: locationData.city,
                country: locationData.country,
                country_code: locationData.country_code
            };

            const response = await fetch(`${AiskData.apiUrl}/conversations`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce
                },
                body: JSON.stringify(requestData)
            });

            const data = await response.json();

            if (!response.ok || !data.conversation_id) {
                console.error('Failed to create conversation:', response.status, data);
                return null;
            }

            return data.conversation_id;
        } catch (error) {
            console.error('Error creating conversation:', error);
            return null;
        }
    };

    const startNewConversation = async () => {
        try {
            // Set flag to indicate new conversation
            localStorage.setItem('is_new_conversation', 'true');
            // Clear existing state
            localStorage.removeItem('wooai_conversation_id');
            setShowConversations(false);
            setMessages([]);

            // Initialize new conversation
            const newConversationId = await initializeNewConversation();
            console.log('newConversationId', newConversationId);
            if (!newConversationId) {
                throw new Error('Failed to create new conversation');
            }

            // Set the new conversation ID
            setConversationId(newConversationId);
            localStorage.setItem('wooai_conversation_id', newConversationId);

            // Wait a bit to ensure state is updated
            await new Promise(resolve => setTimeout(resolve, 100));

			// Reload conversations list once after creating a new conversation
			setHasLoadedConversations(false);

        } catch (error) {
            console.error('Error in startNewConversation:', error);
            setConversationId(null);
            setMessages([]);
        }
    };

	const loadRecentConversations = async () => {
		if (!isOpen) return;
        try {
			setIsLoadingConversationsList(true);
            const response = await fetch(`${AiskData.apiUrl}/conversations`, {
                headers: {
                    'X-WP-Nonce': AiskData.nonce
                }
            });

            const data = await response.json();

            // Handle both array and object responses
            if (Array.isArray(data)) {
                setConversations({ conversations: data });
            } else if (data.conversations) {
                setConversations(data);
            } else {
                console.error('Unexpected conversations API response format:', data);
                setConversations({ conversations: [] });
            }
        } catch (error) {
            console.error('Error loading conversations:', error);
            setConversations({ conversations: [] });
		} finally {
			setIsLoadingConversationsList(false);
		}
    };

	const handleHistoryClick = async () => {
		// Toggle off if currently showing
		if (showConversations) {
			setShowConversations(false);
			return;
		}
		// Show conversations panel and load
		setShowConversations(true);
		if (!hasLoadedConversations) {
			await loadRecentConversations();
			setHasLoadedConversations(true);
		}
	};

    // Rest of your existing functions...
    const getLocationData = async () => {
        try {
            // Try to get location from ipapi.co
            const response = await fetch('https://ipapi.co/json/', {
                mode: 'cors',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Failed to fetch location data');
            }

            const data = await response.json();
            return {
                city: data.city || null,
                country: data.country_name || null,
                country_code: data.country_code || null
            };
        } catch (error) {
            console.error('Error getting location from ipapi.co:', error);
            // Fallback to using the browser's geolocation API
            try {
                const position = await new Promise((resolve, reject) => {
                    navigator.geolocation.getCurrentPosition(resolve, reject);
                });

                // Use a reverse geocoding service to get city/country
                const reverseGeocodeResponse = await fetch(
                    `https://nominatim.openstreetmap.org/reverse?format=json&lat=${position.coords.latitude}&lon=${position.coords.longitude}`
                );

                if (!reverseGeocodeResponse.ok) {
                    throw new Error('Failed to reverse geocode');
                }

                const reverseData = await reverseGeocodeResponse.json();
                return {
                    city: reverseData.address?.city || reverseData.address?.town || null,
                    country: reverseData.address?.country || null,
                    country_code: reverseData.address?.country_code?.toUpperCase() || null
                };
            } catch (geoError) {
                console.error('Error getting location from geolocation:', geoError);
                // Return null values if all methods fail
                return {
                    city: null,
                    country: null,
                    country_code: null
                };
            }
        }
    };

    const initializeConversation = async () => {
        try {
            const locationData = await getLocationData();
            const initialMessage = "Hi, how can I help you?";
            const currentTime = new Date().toISOString();

            const requestData = {
                url: window.location.href,
                userAgent: navigator.userAgent,
                city: locationData.city,
                country: locationData.country,
                country_code: locationData.country_code,
                initial_message: initialMessage
            };

            const response = await fetch(`${AiskData.apiUrl}/conversations`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce
                },
                body: JSON.stringify(requestData)
            });

            const data = await response.json();

            if (!data.conversation_id) {
                throw new Error('No conversation_id in response');
            }

            // Save conversation ID first
            setConversationId(data.conversation_id);
            localStorage.setItem('wooai_conversation_id', data.conversation_id);

            // Save initial message to database
            await fetch(`${AiskData.apiUrl}/messages/${data.conversation_id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce
                },
                body: JSON.stringify({
                    message: '',
                    message_type: 'bot',
                    created_at: currentTime
                })
            });

            // Add initial bot message to state
            const initialMessageObj = {
                type: 'bot',
                messageType: 'bot',
                content: initialMessage,
                timestamp: currentTime
            };
            setMessages([initialMessageObj]);

            return data.conversation_id;
        } catch (error) {
            console.error('Error creating conversation:', error);
            return null;
        }
    };

    const handleQuestionClick = (question) => {
        setInputValue(question);
        Promise.resolve().then(() => {
            handleSubmit({ preventDefault: () => { }, currentTarget: { elements: { input: { value: question } } } });
        });
    };

    const handleOrderAction = (action) => {
        switch (action.type) {
            case 'track_order':
                window.open(`/tracking/${action.order_number}`, '_blank');
                break;
            case 'download_invoice':
                window.open(`/invoice/${action.order_number}`, '_blank');
                break;
            case 'contact_support':
            case 'modify_order':
            case 'return_help':
            case 'item_support':
                break;
        }
    };


    const handleSubmit = async (e) => {
        e.preventDefault();
        if (!inputValue.trim() || isLoading) return;

        const userMessage = inputValue.trim();
        setInputValue('');
        setIsLoading(true);

        // Store message in a variable to ensure it's not lost
        const newUserMessage = {
            type: 'user',
            messageType: 'user',
            content: userMessage,
            timestamp: new Date().toISOString()
        };

        // Add user message immediately for instant feedback
        setMessages(prevMessages => [...prevMessages, newUserMessage]);
        scrollToBottom();


        // Add optimistic bot message with typing indicator
        const optimisticBotMessage = {
            type: 'bot',
            messageType: 'bot',
            content: '<div class="typing-indicator"><span></span><span></span><span></span></div>',
            timestamp: new Date().toISOString(),
            isOptimistic: true
        };
        setMessages(prevMessages => [...prevMessages, optimisticBotMessage]);

        try {
            let currentConversationId = conversationId;

            // If no conversation exists, create one first
            if (!currentConversationId) {
                currentConversationId = await initializeNewConversation();

                if (!currentConversationId) {
                    throw new Error('Failed to create conversation');
                }

                setConversationId(currentConversationId);
                localStorage.setItem('wooai_conversation_id', currentConversationId);

                // Wait for state update
                await new Promise(resolve => setTimeout(resolve, 100));
            }

            // Make the API call with shorter timeout for faster response
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 120000); // 120 second timeout

            const response = await fetch(`${AiskData.apiUrl}/chat`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce
                },
                body: JSON.stringify({
                    message: userMessage,
                    conversation_id: currentConversationId
                }),
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            const data = await response.json();

            if (!response.ok) {
                if (data.error?.code === 'context_length_exceeded') {
                    throw new Error('context_length_exceeded');
                }
                throw new Error(data.message || 'Failed to get response');
            }

            // Process bot response
            let messageContent = '';
            if (typeof data === 'string') {
                messageContent = data;
            } else if (typeof data === 'object') {
                messageContent = data.message || JSON.stringify(data);
            }

            // Create bot message
            const botMessage = {
                type: 'bot',
                messageType: 'bot',
                content: messageContent,
                timestamp: new Date().toISOString(),
                products: data.products || null,
                order: data.order || null,
                support: data.support || false,
                response_type: data.response_type || data.type || data.content_type || null,
                contact_info: data.contact_info || null,
                form_fields: data.form_fields || null
            };

            // Replace optimistic message with real response
            setMessages(prevMessages => {
                const filteredMessages = prevMessages.filter(msg => !msg.isOptimistic);
                return [...filteredMessages, botMessage];
            });

        } catch (error) {
            console.error('Error in handleSubmit:', error);
            const errorMessage = error.name === 'AbortError' 
                ? 'Request timed out. Please try again.'
                : error.message === 'context_length_exceeded'
                ? 'The conversation has become too long. Please start a new conversation to continue.'
                : 'Sorry, I encountered an error. Please try again.';

            // Replace optimistic message with error message
            setMessages(prevMessages => {
                const filteredMessages = prevMessages.filter(msg => !msg.isOptimistic);
                return [...filteredMessages, {
                    type: 'bot',
                    messageType: 'bot',
                    content: errorMessage,
                    timestamp: new Date().toISOString()
                }];
            });
        } finally {
            setIsLoading(false);
            setIsOpen(true);
            scrollToBottom();
        }
    };

    const handleContactFormSubmit = async (formData) => {
        try {
            const response = await fetch(`${AiskData.apiUrl}/submit-contact`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': AiskData.nonce
                },
                body: JSON.stringify({
                    ...formData,
                    conversation_id: conversationId
                })
            });

            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.message || 'Failed to submit form');
            }

            // Return the success status instead of adding to messages
            return {
                success: true,
                message: data.message
            };

        } catch (error) {
            console.error('Error submitting form:', error);
            return {
                success: false,
                message: 'Sorry, there was an error submitting your message. Please try again.'
            };
        }
    };

    useEffect(() => {
        setWidgetPosition(window.AiskData?.chatwidget?.widget_position);
    }, [window.AiskData?.chatwidget?.widget_position]);


    useEffect(() => {
        setWidgetPlaceholder(window.AiskData?.chatwidget?.widget_placeholder);
    }, [window.AiskData?.chatwidget?.widget_placeholder]);

    return (
        <div className={`support-buddy-widget ${widgetPosition}`}>
            {isOpen && (
                <div className="support-buddy-container support-buddy-fade-in">
                    <div className="support-buddy-header" style={styles.headerBg}>
                        <div className="support-buddy-header-title">
                            {widgetLogo ? (
                                <div className="support-buddy-header-logo">
                                    <img src={widgetLogo} alt="Widget logo" />
                                </div>
                            ) : (
                                <span style={styles.headerText}>{widgetText}</span>
                            )}
                        </div>
                        <div className="support-buddy-header-actions">
					<button
						className="support-buddy-header-icon"
						onClick={handleHistoryClick}
						style={styles.headerText}
						disabled={props.readOnly}
					>
                                <History size={20} />
                            </button>
                            <button
                                onClick={() => setIsOpen(false)}
                                className="support-buddy-header-close"
                                style={styles.headerText}
                            >
                                <ChevronDown size={24} />
                            </button>
                        </div>
                    </div>

                    <div className="support-buddy-messages" ref={messagesContainerRef}>
                        {showConversations ? (
                            <div className="support-buddy-conversations">
                                <div className="support-buddy-conversations-header">
                                    <h3 style={{ color: colors.primary }}>Chat History</h3>
                                    <button
                                        onClick={startNewConversation}
                                        className="support-buddy-new-chat"
                                        style={styles.primaryButton}
                                    >
                                        New Chat
                                    </button>
                                </div>
						{isLoadingConversationsList ? (
							<div className="support-buddy-conversations-loading" style={{ color: colors.primary }}>
								Loading conversations...
							</div>
						) : (
							<ConversationList
								conversations={conversations.conversations || []}
								onSelect={selectConversation}
								selectedId={conversationId}
								loadingId={loadingConversationId}
							/>
						)}
                            </div>
                        ) : (
                            <>
                                {messages.length === 0 || messages.length === 1 ? (
                                    <div>
                                        <div className="support-buddy-message bot">
                                            <div className="support-buddy-message-content">
                                                {widgetGreeting}
                                            </div>
                                        </div>
                                        <div className="support-buddy-suggested-questions">
                                            {suggestedQuestions.map((question, index) => (
                                                <button
                                                    key={index}
                                                    className="support-buddy-suggested-question"
                                                    style={styles.suggestedQuestion}
                                                    onClick={() => handleQuestionClick(question)}
                                                >
                                                    {question}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                ) : (
								<>
									{isLoadingMessages && messages.length === 0 && (
										<div className="support-buddy-messages-loading" style={{ color: colors.primary }}>
											Loading messages...
										</div>
									)}
									{messages.map((message, index) => (
										<div key={index}>
                                                <div className={`support-buddy-message ${message.type}`}>
                                                    <div
                                                        className="support-buddy-message-content"
                                                        style={message.type === 'user' ? styles.primaryButton : {}}
                                                        dangerouslySetInnerHTML={{ __html: message.content }}
                                                    />
                                                    <div className={`support-buddy-message-timestamp ${message.type}`}>
                                                        {formatMessageTimestamp(message.timestamp)}
                                                    </div>
                                                </div>
                                                {message.products && Array.isArray(message.products) && message.products.length > 0 ? (
                                                    <ProductCarousel products={message.products} />
                                                ) : null}
                                                {message.order?.order_info && (
                                                    <OrderStatus
                                                        order={message.order.order_info}
                                                        onAction={handleOrderAction}
                                                        conversationId={conversationId}
                                                    />
                                                )}

                                                {(message.type === 'bot' || message.type === 'contact_support') && message.support === true && message.response_type === 'form' && (
                                                    <ContactForm />
                                                )}
									</div>
								))}
								</>
                                )}
                            </>
                        )}
                        <div ref={messagesEndRef} />
                    </div>

                    {!showConversations && (
                        <div className="support-buddy-input">
                            <form onSubmit={handleSubmit}>
                                <input
                                    type="text"
                                    value={inputValue}
                                    onChange={(e) => setInputValue(e.target.value)}
                                    placeholder={props.readOnly ? "This conversation is in view-only mode" : widgetPlaceholder}
                                    style={{ "--primary-color": colors.primary }}
                                    disabled={props.readOnly}
                                    name="input"
                                />
                                <button type="submit" style={styles.primaryButton} disabled={props.readOnly}>
                                    <Send />
                                </button>
                            </form>
                        </div>
                    )}

                    <div className="support-buddy-footer">
                        <div className="support-buddy-footer-icons">
                            <ChatIntegrations
                                isOpen={isOpen}
                                whatsappNumber={whatsappNumber}
                                telegramUsername={telegramUsername}
                                whatsappEnabled={whatsappEnabled}
                                telegramEnabled={telegramEnabled}
                            />
                        </div>
                        <span className="support-buddy-footer-text">Powered by <a href="https://aisk.chat" target="_blank" rel="noopener noreferrer">Aisk.chat</a></span>
                    </div>
                </div>
            )}

            {!isOpen && (
                bubbleType === 'rolling' ? (
                    <ChatBubbleRollingMessage
                        onClick={() => setIsOpen(!isOpen)}
                        settings={{
                            widgetColor: color,
                            chatIcon: chatIcon,
                            soundEnabled: window.AiskData?.chatwidget?.soundEnabled,
                            soundUrl: window.AiskData?.chatwidget?.soundUrl,
                            widgetPosition: window.AiskData?.chatwidget?.widget_position,
                            messages: rollingMessages
                        }}
                    />
                ) : (
                    <ChatBubbleDefault
                        onClick={() => setIsOpen(!isOpen)}
                        settings={{
                            widgetColor: color,
                            chatIcon: chatIcon,
                            soundEnabled: window.AiskData?.chatwidget?.soundEnabled,
                            soundUrl: window.AiskData?.chatwidget?.soundUrl,
                            widgetPosition: window.AiskData?.chatwidget?.widget_position,
                            captionText: defaultMessage
                        }}
                    />
                )
            )}
        </div>
    );
});

export default ChatWidget;