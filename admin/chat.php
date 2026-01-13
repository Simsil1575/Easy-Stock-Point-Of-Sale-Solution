<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Sales Assistant</title>
    <script src="../navigation.js" async></script>
    <script src="chat.js" defer></script>
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link href="../src/output.css" rel="stylesheet">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@2.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        .sidebar {
            position: fixed;
            height: 100%;
        }
        .content {
            margin-left: 250px;
            margin-top: 50px;
        }
        .typing-indicator span {
            animation: blink 1.4s infinite both;
        }
        .typing-indicator span:nth-child(2) {
            animation-delay: 0.2s;
        }
        .typing-indicator span:nth-child(3) {
            animation-delay: 0.4s;
        }
        @keyframes blink {
            0% { opacity: 0.1; }
            20% { opacity: 1; }
            100% { opacity: 0.1; }
        }
        .chat-container {
            scrollbar-width: thin;
            scrollbar-color: rgba(156, 163, 175, 0.5) transparent;
            height: 60vh;
        }
        .chat-container::-webkit-scrollbar {
            width: 6px;
        }
        .chat-container::-webkit-scrollbar-track {
            background: transparent;
        }
        .chat-container::-webkit-scrollbar-thumb {
            background-color: rgba(156, 163, 175, 0.5);
            border-radius: 3px;
        }
        .message-enter {
            animation: message-fade-in 0.3s ease-out forwards;
            opacity: 0;
            transform: translateY(10px);
        }
        @keyframes message-fade-in {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .pulse {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        .gradient-bg {
            background: linear-gradient(135deg, #10b981 0%, #0d9488 100%);
        }
        .user-bubble {
            background: linear-gradient(135deg, #ecfdf5 0%, #e6fffa 100%);
            margin-left: auto;
        }
        .bot-bubble {
            background-color:rgb(240, 240, 240);
            margin-right: auto;
        }
        .bot-bubble .message-text {
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .bot-bubble .message-text br {
            margin-bottom: 0.5em;
        }
        .bot-bubble .message-text ul {
            margin: 0.5em 0;
            padding-left: 1.5em;
            list-style-type: none;
        }
        .bot-bubble .message-text li {
            margin: 0.25em 0;
            position: relative;
            padding-left: 1em;
        }
        .bot-bubble .message-text li:before {
            content: "•";
            position: absolute;
            left: 0;
            color: #10b981;
        }
        .question-card {
            transition: all 0.2s ease-in-out;
            cursor: pointer;
            border: 1px solid #e5e7eb;
        }
        .question-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.15);
            border-color: #10b981;
        }
        .cards-fade-out {
            animation: fade-out 0.3s ease-out forwards;
        }
        @keyframes fade-out {
            to {
                opacity: 0;
                transform: translateY(-10px);
            }
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <div class="sidebar">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 content mt-1">
            <div class="container mx-auto p-10">
                <!-- Header with Back Button and Title -->
                <div class="flex items-center justify-between mb-6">
                    <a href="home" class="inline-flex items-center px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back to Dashboard
                    </a>
                </div>

                <!-- Main chat content container -->
                <div>
                    <div class="bg-white rounded-2xl shadow-xl overflow-hidden border border-gray-100">
                        <!-- Chat Header -->
                        <div class="border-b bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/60">
                            <div class="flex h-16 items-center px-6">
                                <div class="flex items-center gap-4">
                                    <div class="relative">
                                        <div class="h-14 w-14 rounded-full ring-2 ring-teal-500/20">
                                            <img src="../props/Helvi.png" alt="Helvi" class="h-14 w-14 rounded-full object-cover">
                                        </div>
                                    </div>
                                    <div>
                                        <h1 class="text-lg font-semibold text-gray-900">Helvi</h1>
                                        <p class="text-sm text-gray-500">Sales Assistant</p>
                                    </div>
                                </div>
                                <div class="ml-auto flex items-center gap-2">
                                    <button id="clear-chat" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-9 px-4">
                                        <i class="ri-refresh-line mr-2"></i>
                                        Clear Chat
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Chat Container -->
                        <div id="chat-container" class="chat-container overflow-y-auto px-6 py-8 space-y-4">
                            <!-- Messages will be inserted here by JavaScript -->
                        </div>

                        <!-- Input Area -->
                        <div class="border-t p-4 bg-gray-50">
                            <div id="chat-form" class="flex items-center gap-3 w-full">
                                <div class="relative flex-1">
                                    <input type="text" id="message-input" 
                                           class="w-full px-4 py-3 text-gray-700 bg-white border border-gray-200 rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500 transition-all duration-200"
                                           placeholder="Ask about today's sales...">
                                </div>
                                <button id="send-message" 
                                        class="flex items-center justify-center h-12 w-16 text-white gradient-bg rounded-xl hover:opacity-90 focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 transition-all duration-200 shadow-md">
                                    <i class="ri-send-plane-fill text-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Templates for message elements -->
    <template id="bot-message-template">
        <div class="flex space-x-3 message-enter">
            <div class="flex-shrink-0 h-10 w-10 rounded-full gradient-bg flex items-center justify-center text-white shadow-lg">
                <img src="../props/Helvi.png" alt="Helvi" class="h-10 w-10 rounded-full object-cover">
            </div>
            <div class="bot-bubble rounded-2xl p-4 shadow-sm max-w-[80%]">
                <p class="text-gray-700 message-text"></p>
            </div>
        </div>
    </template>

    <template id="user-message-template">
        <div class="flex space-x-3 message-enter">
            <div class="user-bubble rounded-2xl p-4 shadow-sm max-w-[80%]">
                <p class="text-gray-800 message-text"></p>
            </div>
            <div class="flex-shrink-0 h-10 w-10 rounded-full gradient-bg flex items-center justify-center text-white shadow-lg">
                <i class="ri-user-line"></i>
            </div>
        </div>
    </template>

    <template id="typing-indicator-template">
        <div class="flex space-x-3 message-enter" id="typing-indicator">
            <div class="flex-shrink-0 h-10 w-10 rounded-full gradient-bg flex items-center justify-center text-white shadow-lg pulse">
                <img src="../props/Helvi.png" alt="Helvi" class="h-10 w-10 rounded-full object-cover">
            </div>
            <div class="bot-bubble rounded-2xl p-4 shadow-sm typing-indicator">
                <p class="text-gray-500 flex items-center">
                    Thinking<span>.</span><span>.</span><span>.</span>
                </p>
            </div>
        </div>
    </template>

    <template id="welcome-message-template">
      
    </template>

    <template id="question-cards-template">
        <div id="question-cards" class="flex flex-col space-y-3 mt-4 message-enter">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="question-card bg-gradient-to-br from-teal-50 to-purple-50 rounded-xl p-4 shadow-sm hover:shadow-md" data-question="What products are selling the most?">
                    <div class="flex items-center space-x-3">
                        <div class="flex-shrink-0">
                            <i class="ri-shopping-basket-line text-2xl text-gray-600"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-900">Popular Items</h3>
                            <p class="text-xs text-gray-500">Best Selling Snacks</p>
                        </div>
                    </div>
                </div>
                
                <div class="question-card bg-gradient-to-br from-cyan-50 to-blue-50 rounded-xl p-4 shadow-sm hover:shadow-md" data-question="Which items need to be restocked?">
                    <div class="flex items-center space-x-3">
                        <div class="flex-shrink-0">
                            <i class="ri-stock-line text-2xl text-gray-600"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-900">Stock Check</h3>
                            <p class="text-xs text-gray-500">Need Restocking</p>
                        </div>
                    </div>
                </div>
                
                <div class="question-card bg-gradient-to-br from-teal-50 to-teal-50 rounded-xl p-4 shadow-sm hover:shadow-md" data-question="How much money did we make today?">
                    <div class="flex items-center space-x-3">
                        <div class="flex-shrink-0">
                            <i class="ri-money-dollar-circle-line text-2xl text-gray-600"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-900">Today's Sales</h3>
                            <p class="text-xs text-gray-500">Daily Earnings</p>
                        </div>
                    </div>
                </div>
                
                <div class="question-card bg-gradient-to-br from-amber-50 to-orange-50 rounded-xl p-4 shadow-sm hover:shadow-md" data-question="Which items are about to expire?">
                    <div class="flex items-center space-x-3">
                        <div class="flex-shrink-0">
                            <i class="ri-time-line text-2xl text-gray-600"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-900">Expiry Alert</h3>
                            <p class="text-xs text-gray-500">Check Expiry Dates</p>
                        </div>
                    </div>
                </div>
                
                <div class="question-card bg-gradient-to-br from-blue-50 to-cyan-50 rounded-xl p-4 shadow-sm hover:shadow-md" data-question="What are our most profitable items?">
                    <div class="flex items-center space-x-3">
                        <div class="flex-shrink-0">
                            <i class="ri-line-chart-line text-2xl text-gray-600"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-900">Best Profit</h3>
                            <p class="text-xs text-gray-500">Most Profitable Items</p>
                        </div>
                    </div>
                </div>
                
                <div class="question-card bg-gradient-to-br from-violet-50 to-fuchsia-50 rounded-xl p-4 shadow-sm hover:shadow-md" data-question="Who owes the business money?and check for past due dates">
                    <div class="flex items-center space-x-3">
                        <div class="flex-shrink-0">
                            <i class="ri-bank-card-line text-2xl text-gray-600"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-gray-900">Creditors</h3>
                            <p class="text-xs text-gray-500">Outstanding Payments</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <script>
        // Enhanced chat functionality with question cards
        class SalesChat {
            constructor() {
                this.chatContainer = document.getElementById('chat-container');
                this.messageInput = document.getElementById('message-input');
                this.sendButton = document.getElementById('send-message');
                this.clearButton = document.getElementById('clear-chat');
                this.isWaitingForResponse = false;
                this.questionCardsVisible = false;
                
                this.init();
            }

            init() {
                this.showWelcomeMessage();
                this.setupEventListeners();
            }

            setupEventListeners() {
                this.sendButton.addEventListener('click', () => this.sendMessage());
                this.messageInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.sendMessage();
                    }
                });
                this.clearButton.addEventListener('click', () => this.clearChat());
            }

            showWelcomeMessage() {
                const welcomeTemplate = document.getElementById('welcome-message-template');
                const welcomeMessage = welcomeTemplate.content.cloneNode(true);
                this.chatContainer.appendChild(welcomeMessage);
                
                // Only show question cards if there are no other messages
                if (this.chatContainer.children.length <= 1) {
                    setTimeout(() => {
                        this.showQuestionCards();
                    }, 500);
                }
                
                this.scrollToBottom();
            }

            showQuestionCards() {
                // Don't show cards if there are already messages or if cards are already visible
                if (this.questionCardsVisible || this.chatContainer.children.length > 1) return;
                
                const cardsTemplate = document.getElementById('question-cards-template');
                const questionCards = cardsTemplate.content.cloneNode(true);
                
                // Add click event listeners to each card
                const cards = questionCards.querySelectorAll('.question-card');
                cards.forEach(card => {
                    card.addEventListener('click', () => {
                        const question = card.getAttribute('data-question');
                        this.handleQuestionCardClick(question);
                    });
                });
                
                this.chatContainer.appendChild(questionCards);
                this.questionCardsVisible = true;
                this.scrollToBottom();
            }

            handleQuestionCardClick(question) {
                // Hide question cards with animation
                this.hideQuestionCards();
                
                // Send the question as if user typed it
                setTimeout(() => {
                    this.messageInput.value = question;
                    this.sendMessage();
                }, 300);
            }

            hideQuestionCards() {
                const questionCardsElement = document.getElementById('question-cards');
                if (questionCardsElement) {
                    questionCardsElement.classList.add('cards-fade-out');
                    setTimeout(() => {
                        questionCardsElement.remove();
                        this.questionCardsVisible = false;
                    }, 300);
                }
            }

            sendMessage() {
                if (this.isWaitingForResponse) return;
                
                const message = this.messageInput.value.trim();
                if (!message) return;

                // Hide question cards if visible
                if (this.questionCardsVisible) {
                    this.hideQuestionCards();
                }

                this.addUserMessage(message);
                this.messageInput.value = '';
                this.showTypingIndicator();
                this.isWaitingForResponse = true;

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
                    this.removeTypingIndicator();
                    if (data.status === 'success') {
                        this.addBotMessage(data.message);
                    } else {
                        this.addBotMessage("Sorry, I encountered an error while processing your request.");
                        console.error('API Error:', data.message);
                    }
                    this.isWaitingForResponse = false;
                })
                .catch(error => {
                    this.removeTypingIndicator();
                    this.addBotMessage("Sorry, I couldn't connect to the server. Please try again later.");
                    console.error('Fetch Error:', error);
                    this.isWaitingForResponse = false;
                });
            }

            addUserMessage(message) {
                const template = document.getElementById('user-message-template');
                const messageElement = template.content.cloneNode(true);
                messageElement.querySelector('.message-text').textContent = message;
                this.chatContainer.appendChild(messageElement);
                this.scrollToBottom();
            }

            addBotMessage(message) {
                const template = document.getElementById('bot-message-template');
                const messageElement = template.content.cloneNode(true);
                messageElement.querySelector('.message-text').textContent = message;
                this.chatContainer.appendChild(messageElement);
                this.scrollToBottom();
            }

            showTypingIndicator() {
                const template = document.getElementById('typing-indicator-template');
                const indicator = template.content.cloneNode(true);
                this.chatContainer.appendChild(indicator);
                this.scrollToBottom();
            }

            removeTypingIndicator() {
                const indicator = document.getElementById('typing-indicator');
                if (indicator) {
                    indicator.remove();
                }
            }

            clearChat() {
                this.chatContainer.innerHTML = '';
                this.questionCardsVisible = false;
                this.isWaitingForResponse = false;
                this.showWelcomeMessage();
            }

            scrollToBottom() {
                setTimeout(() => {
                    this.chatContainer.scrollTop = this.chatContainer.scrollHeight;
                }, 100);
            }
        }

        // Initialize chat when DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            new SalesChat();
        });
    </script>
</body>
</html>