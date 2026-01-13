document.addEventListener('DOMContentLoaded', function() {
    // DOM Elements
    const chatContainer = document.getElementById('chat-container');
    const messageInput = document.getElementById('message-input');
    const sendButton = document.getElementById('send-message');
    const clearButton = document.getElementById('clear-chat');
    const botTemplate = document.getElementById('bot-message-template');
    const userTemplate = document.getElementById('user-message-template');
    const typingTemplate = document.getElementById('typing-indicator-template');
    const welcomeTemplate = document.getElementById('welcome-message-template');
    
    // Variables
    let isLoading = false;
    let conversation = [];

    // Initialize chat
    init();

    // Event Listeners
    sendButton.addEventListener('click', sendMessage);
    messageInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            sendMessage();
        }
    });
    clearButton.addEventListener('click', clearChat);

    // Functions
    function init() {
        fetchConversation()
            .then(data => {
                conversation = data.conversation || [];
                renderConversation();
                if (conversation.length === 0) {
                    addWelcomeMessage();
                }
            })
            .catch(error => {
                console.error('Error initializing chat:', error);
                addWelcomeMessage();
            });
    }

    function fetchConversation() {
        return fetch('chat_api.php')
            .then(response => response.json())
            .catch(error => {
                console.error('Error fetching conversation:', error);
                return { conversation: [] };
            });
    }

    function sendMessage() {
        if (isLoading) return;
        
        const message = messageInput.value.trim();
        if (!message) return;
        
        // Clear input
        messageInput.value = '';
        
        // Add user message to UI
        addUserMessage(message);
        
        // Add typing indicator
        const typingIndicator = addTypingIndicator();
        
        // Set loading state
        isLoading = true;
        sendButton.innerHTML = '<i class="ri-loader-4-line animate-spin"></i>';
        sendButton.disabled = true;
        messageInput.disabled = true;
        
        // Send to API
        fetch('chat_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ message: message })
        })
        .then(response => response.json())
        .then(data => {
            // Remove typing indicator
            if (typingIndicator) {
                chatContainer.removeChild(typingIndicator);
            }
            
            if (data.status === 'success') {
                // Update conversation
                conversation = data.conversation;
                
                // Add bot reply to UI
                addBotMessage(data.message);
            } else {
                // Handle error
                addBotMessage("Sorry, I encountered an error while processing your request.");
                console.error('API Error:', data.message);
            }
        })
        .catch(error => {
            // Remove typing indicator
            if (typingIndicator) {
                chatContainer.removeChild(typingIndicator);
            }
            
            // Handle error
            addBotMessage("Sorry, I couldn't connect to the server. Please try again later.");
            console.error('Fetch Error:', error);
        })
        .finally(() => {
            // Reset loading state
            isLoading = false;
            sendButton.innerHTML = '<i class="ri-send-plane-fill text-lg"></i>';
            sendButton.disabled = false;
            messageInput.disabled = false;
            messageInput.focus();
        });
    }

    function clearChat() {
        if (isLoading) return;
        
        fetch('chat_api.php?clear=1')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    conversation = [];
                    chatContainer.innerHTML = '';
                    addWelcomeMessage();
                }
            })
            .catch(error => {
                console.error('Error clearing chat:', error);
            });
    }

    function renderConversation() {
        chatContainer.innerHTML = '';
        
        conversation.forEach(exchange => {
            addUserMessage(exchange.user, false);
            addBotMessage(exchange.bot, false);
        });
        
        scrollToBottom();
    }

    function addUserMessage(text, animate = true) {
        const clone = document.importNode(userTemplate.content, true);
        clone.querySelector('.message-text').textContent = text;
        
        if (!animate) {
            clone.querySelector('.message-enter').classList.remove('message-enter');
        }
        
        chatContainer.appendChild(clone);
        scrollToBottom();
        return chatContainer.lastElementChild;
    }

    function addBotMessage(text, animate = true) {
        const clone = document.importNode(botTemplate.content, true);
        const messageText = clone.querySelector('.message-text');
        
        // Format the text with proper spacing and line breaks
        const formattedText = text
            .split('\n\n')  // Split by double newlines for paragraphs
            .map(paragraph => {
                // Handle bullet points
                if (paragraph.trim().startsWith('-')) {
                    return paragraph.split('\n')
                        .map(line => line.trim())
                        .join('\n');
                }
                return paragraph.trim();
            })
            .join('\n\n')
            .replace(/\n/g, '<br>');  // Convert remaining newlines to <br>
        
        messageText.innerHTML = formattedText;
        
        if (!animate) {
            clone.querySelector('.message-enter').classList.remove('message-enter');
        }
        
        chatContainer.appendChild(clone);
        scrollToBottom();
        return chatContainer.lastElementChild;
    }

    function addTypingIndicator() {
        const clone = document.importNode(typingTemplate.content, true);
        chatContainer.appendChild(clone);
        scrollToBottom();
        return chatContainer.lastElementChild;
    }

    function addWelcomeMessage() {
        const clone = document.importNode(welcomeTemplate.content, true);
        chatContainer.appendChild(clone);
        scrollToBottom();
        return chatContainer.lastElementChild;
    }

    function scrollToBottom() {
        chatContainer.scrollTop = chatContainer.scrollHeight;
    }

    // Helper function to escape HTML
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
});