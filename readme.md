=== Aisk — AI Sales Chatbot for FluentCart | Knowledgebase & Support bot ===
Contributors: aisk, zrshishir, sabbirxprt
Tags: chatbot, live chat, customer support, live support, fluentcart
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
AI-powered chatbot for FluentCart, offers instant product recommendations and self-trained AI knowledge base for enhanced customer support.
== Description ==
Aisk is an **AI-powered chatbot plugin for WordPress** designed to integrate seamlessly with FluentCart stores. Our free WordPress chatbot plugin provides AI-driven customer support with WhatsApp live chat and Telegram integration. With powerful AI knowledgebase plugin features, Aisk handles routine customer inquiries, reduces support tickets, and provides personalized product recommendations.
=== Self-Learning AI Knowledgebase ===
One of the standout features of Aisk is its **self-learning capability**. The **AI-powered chatbot** learns from your business content and continuously improves its responses. During setup, Aisk's **"Generate Embeddings"** process analyzes your website pages, product descriptions, FAQs, and other content to create a **customized knowledge base**. This enables the chatbot in WordPress to provide accurate and relevant answers based on your website. You can easily update this knowledge base by regenerating embeddings whenever new content is added to your site, ensuring the chatbot remains up-to-date with the latest information.
- **Continuous Learning:** As you add new content or update existing pages, Aisk’s "Generate Embeddings" process enables the AI to learn from the latest information. This self-learning process ensures that the chatbot remains accurate and up-to-date without manual intervention.
  
- **Intelligent Answering:** The AI improves over time, ensuring it provides personalized, context-aware responses to customer queries, making it even smarter with every interaction.
- **Instant Customization:** Any time new information is added to your site, you can regenerate the knowledge base and have the chatbot immediately adapt to the changes. This reduces the need for ongoing maintenance and ensures that the AI is always aligned with the latest content and product offerings.
The self-learning feature makes Aisk more than just a support tool; it becomes a dynamic, intelligent resource that grows alongside your business, offering consistent, personalized, and up-to-date customer service.
=== Why Choose AISK? ===
- 💯 **Aisk Chat is FREE!**
- 📉 **Reduce support tickets to a significant number to focus your business**
- ⏱️ **Provide instant 24/7 customer support**
- 👨‍💼 **Free up your team's time for complex issues**
- 😊 **Increase customer satisfaction with quick responses**
- 📈 **Boost sales with intelligent product recommendations**
- 📱 **Seamlessly integrate WhatsApp live chat and Telegram in WordPress**
- 🔗 **Improved URL handling and content extraction for better chatbot support in WordPress**
== FluentCart Integration == 
AISK is built from the ground up for FluentCart stores:
- **Smart Product Search**: Helps customers find products based on descriptions, colors, sizes, categories, and more.
- **Order Management**: Customers can track orders, view status, and submit inquiries about their orders using their order number and email.
- **Intelligent Recommendations**: Suggests relevant products based on customer queries and browsing history, improving conversion rates.
## Powerful Aisk AI Chatbot Features
### Automated Responses, No Delays. Ever.
Answer customer questions instantly 24/7 through your website, **WhatsApp**, and **Telegram** using your existing content. Aisk handles routine inquiries automatically, eliminating wait times, with seamless handoff to your team when needed.
### Smart Product Recommendations That Convert
When customers describe what they want, Aisk instantly suggests matching products with images and purchase links, increasing conversions and order values through personalized recommendations.
### Order Tracking Made Effortless
Customers can check **order status**, shipping updates, and tracking information via your website, **WhatsApp**, or **Telegram**, reducing support inquiries by 90%.
### WhatsApp & Telegram Integration
Turn **WhatsApp live chat WordPress** and **Telegram** into your 24/7 sales and support channels. Customers can browse products, check order status, and get instant support using apps they already use daily.
### Complete Omnichannel Support
Unify your website, **WhatsApp**, and **Telegram** conversations in a single dashboard. This ensures consistent responses across channels, eliminates communication silos, and provides your team complete visibility of customer interactions from every source.
= 📞 Support =
If you have any questions or need assistance, please visit our [support forum](https://wordpress.org/support/plugin/aisk-ai-chat-for-fluentcart/) or [contact us directly](https://aisk.chat/support/).
= 📝 License =
This project is licensed under the GPL-2.0+ License - see the LICENSE file for details.
= 🔌 External Services =
This plugin connects to several external services to provide its functionality. Here's a detailed breakdown of each service and how it's used:
== OpenAI API ==
- **Purpose**: Used for generating AI responses and creating embeddings from your content
- **Data Sent**: 
  - Content from your website (posts, pages, products)
  - User queries and messages
  - Custom knowledge base content
- **When**: 
  - During initial setup when generating embeddings
  - When processing user messages
  - When updating the knowledge base
- **Links**:
  - [Terms of Service](https://openai.com/policies/terms-of-use)
  - [Privacy Policy](https://openai.com/policies/privacy-policy)
== Telegram Bot API ==
- **Purpose**: Enables chat functionality through Telegram messenger
- **Data Sent**:
  - User messages
  - Bot responses
  - Product information
  - Order status updates
- **When**: 
  - When users interact with the Telegram bot
  - When sending automated responses
  - When processing order inquiries
- **Links**:
  - [Terms of Service](https://telegram.org/terms)
  - [Privacy Policy](https://telegram.org/privacy)
== Twilio API ==
- **Purpose**: Enables WhatsApp integration for customer support
- **Data Sent**:
  - User messages
  - Bot responses
  - Product information
  - Order status updates
- **When**:
  - When users interact via WhatsApp
  - When sending automated responses
  - When processing order inquiries
- **Links**:
  - [Terms of Service](https://www.twilio.com/legal/tos)
  - [Privacy Policy](https://www.twilio.com/legal/privacy)
== IPAPI.co ==
- **Purpose**: Used to detect user's location for showing location in the chat list display
- **Data Sent**:
  - User's IP address
- **When**:
  - When the chat widget is loaded
  - When location-based features are requested
- **Links**:
  - [Terms of Service](https://ipapi.co/terms/)
  - [Privacy Policy](https://ipapi.co/privacy/)
== Data Privacy ==
All data transmission to these services is done securely using HTTPS. The plugin only sends necessary data required for its functionality. You can control what data is sent by:
1. Configuring which content types are included in the knowledge base
2. Managing integration settings in the plugin dashboard
3. Using the privacy settings to control data collection
---
Developed with ❤️ by [Aisk.chat Team](https://aisk.chat)
== Frequently Asked Questions ==
= How much does Aisk.chat cost? =
Aisk.chat is completely free of costs. Use Aisk at full fledge without any limitation as there are no Free or Paid plan. All features are open for everyone without any limit. You don't need to have any credit card to start using Aisk.chat. It's because we don't run it on our server. The only thing you will need is the Keys from OpenAI to generate Embedding and answers.
= Do I need OpenAI keys to use Aisk.chat? =
Yes, Aisk.chat requires an OpenAI API key to function. This is because we leverage OpenAI's powerful language models to provide intelligent responses to customer inquiries. You'll need to create an OpenAI account and obtain an API key, which you'll enter in the Aisk dashboard during setup. This approach gives you direct ownership of your AI usage and data while allowing us to provide advanced AI capabilities. We provide step-by-step guidance on obtaining and configuring your OpenAI key during the setup process.
= Can I train Aisk from my own knowledge base? =
Absolutely. Aisk is designed to learn from your specific business content. During setup, the "Generate Embeddings" process analyzes your website pages, product descriptions, FAQs, and other content to create a customized knowledge base. This ensures the chatbot provides accurate, relevant answers based on your unique business information. You can update this knowledge base anytime you add new content to your site by regenerating embeddings.
= Can I add external sites, PDFs, or content as a knowledge base? =
Yes, Aisk supports importing external content to enhance its knowledge base. You can add PDFs, documents, external website content, and custom text sources through the "External Knowledge" section in the dashboard. This feature is particularly useful for adding training manuals, detailed product specifications, or support documentation that isn't published on your main website. All imported content is processed and made searchable for the AI to reference when answering customer questions.
= What happens when the AI can't answer a customer's question? =
If there is any scenario when Aisk couldn't find any answer in the provided knowledge or products, it will offer support information or ask if you would like to create a support ticket. Thus Aisk behaves super friendly when it comes to customer satisfaction and ease.
= Will Aisk work with my existing FluentCart store? =
Yes, Aisk is built specifically for FluentCart integration. It can access your product catalog, pricing, inventory status, and order information to provide accurate responses to customer inquiries. The chatbot can suggest products based on customer descriptions, help with checkout questions, and provide order tracking information automatically. Simply activate the FluentCart integration in the Aisk settings panel.

== Changelog ==
= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of AISK - start automating your FluentCart customer support today!

== Development ==

This plugin uses modern development tools and follows WordPress coding standards. Here's how to set up the development environment:

== Screenshots ==

1. Chat widget interface
2. Admin dashboard
3. Settings panel
4. WhatsApp integration
5. Telegram integration
6. Product recommendations
7. Order tracking
8. PDF processing
9. Analytics dashboard
10. Customization options
11. Knowledge base management
12. Integration settings
13. Support ticket creation

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/aisk-ai-chat-for-fluentcart` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Settings->Aisk screen to configure the plugin
4. Enter your OpenAI API key in the settings
5. Generate embeddings to create your AI knowledge base
6. Configure WhatsApp and Telegram integrations (optional)

== Support ==

Visit [aisk.chat](https://aisk.chat) for documentation and support.

## Privacy Policy 
Aisk — AI Sales Chatbot for FluentCart | Knowledgebase & Support bot uses [Appsero](https://appsero.com) SDK to collect some telemetry data upon user's confirmation. This helps us to troubleshoot problems faster & make product improvements.

Appsero SDK **does not gather any data by default.** The SDK only starts gathering basic telemetry data **when a user allows it via the admin notice**. We collect the data to ensure a great user experience for all our users. 

Integrating Appsero SDK **DOES NOT IMMEDIATELY** start gathering data, **without confirmation from users in any case.**

Learn more about how [Appsero collects and uses this data](https://appsero.com/privacy-policy/).

#   a i s k - a i - c h a t - f l u e n t c a r t  
 