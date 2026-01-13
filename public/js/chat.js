// Scroll chat to bottom on load
document.addEventListener('DOMContentLoaded', function() {
    scrollToBottom();
    
    // Add message-enter class to the last two messages for animation
    const messages = document.querySelectorAll('.message-enter');
    if (messages.length > 0) {
        messages.forEach(message => {
            message.classList.remove('message-enter');
            void message.offsetWidth; // Force reflow
            message.classList.add('message-enter');
        });
    }
});

// Scroll to bottom function
function scrollToBottom() {
    const chatContainer = document.getElementById('chat-container');
    chatContainer.scrollTop = chatContainer.scrollHeight;
}

// Form submission handler
document.getElementById('chat-form').addEventListener('submit', function() {
    // Create and append user message immediately
    const userMessage = document.getElementById('message').value;
    const chatContainer = document.getElementById('chat-container');
    
    const userDiv = document.createElement('div');
    userDiv.className = 'flex flex-row-reverse space-x-3 space-x-reverse message-enter';
    userDiv.innerHTML = `
        <div class="flex-shrink-0 h-10 w-10 rounded-full bg-gradient-to-r from-teal-600 to-emerald-500 flex items-center justify-center text-white shadow-lg">
            <i class="ri-user-line"></i>
        </div>
        <div class="bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl p-4 shadow-sm max-w-[80%]">
            <p class="text-gray-800">${escapeHtml(userMessage)}</p>
        </div>
    `;
    
    chatContainer.appendChild(userDiv);
    
    // Show typing indicator
    document.getElementById('typing-indicator').classList.remove('hidden');
    
    // Clear input
    document.getElementById('message').value = '';
    
    // Scroll to bottom
    scrollToBottom();
});

// Helper function to escape HTML
function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
} 