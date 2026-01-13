document.addEventListener('DOMContentLoaded', function() {
    const inboxList = document.getElementById('inbox-list');
    const emptyState = document.getElementById('empty-state');
    const notification = document.getElementById('notification');
    const filterStatus = document.getElementById('filter-status');
    const refreshButton = document.getElementById('refresh-inbox');
    const clearButton = document.getElementById('clear-inbox');
    const searchInput = document.getElementById('search-input');
    const prevPageButton = document.getElementById('prev-page');
    const nextPageButton = document.getElementById('next-page');
    const paginationInfo = document.getElementById('pagination-info');
    const alertDialog = document.getElementById('alert-dialog');
    const alertCancel = document.getElementById('alert-cancel');
    const alertConfirm = document.getElementById('alert-confirm');

    // Pagination state
    let currentPage = 1;
    const itemsPerPage = 5;
    let totalItems = 0;
    let filteredMessages = [];

    // Load messages
    function loadMessages() {
        fetch('chat_api.php?inbox=1')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    filteredMessages = data.messages;
                    totalItems = data.messages.length;
                    updatePagination();
                    displayMessages();
                    
                    // Mark all unread messages as read in database only
                    const unreadMessages = filteredMessages.filter(msg => !msg.is_read);
                    if (unreadMessages.length > 0) {
                        unreadMessages.forEach(message => {
                            fetch(`chat_api.php?mark_read=${message.id}`)
                                .then(response => response.json())
                                .catch(error => {
                                    if (notification) {
                                        showNotification('Error marking messages as read', 'error');
                                    }
                                });
                        });
                    }
                }
            })
            .catch(error => {
                if (notification) {
                    showNotification('Error loading messages', 'error');
                }
            });
    }

    // Display messages
    function displayMessages() {
        if (!inboxList) return;
        
        inboxList.innerHTML = '';
        
        if (filteredMessages.length === 0) {
            emptyState.classList.remove('hidden');
            return;
        }

        emptyState.classList.add('hidden');
        
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = Math.min(startIndex + itemsPerPage, filteredMessages.length);
        const currentPageMessages = filteredMessages.slice(startIndex, endIndex);
        
        currentPageMessages.forEach(message => {
            const tr = document.createElement('tr');
            tr.className = 'border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted';
            
            // From cell (Helvi)
            const fromCell = document.createElement('td');
            fromCell.className = 'p-4 align-middle';
            fromCell.innerHTML = `
                <div class="flex items-center gap-2">
                    <img src="../props/Helvi.png" alt="Helvi" class="h-12 w-12 rounded-full object-cover object-center aspect-square border border-slate-200 shadow-sm">
                    <span class="font-medium">Helvi</span>
                </div>
            `;
            // Status cell
            const statusCell = document.createElement('td');
            statusCell.className = 'p-4 align-middle';
            const statusBadge = document.createElement('span');
            statusBadge.className = `inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${message.is_read === 1 ? 'bg-slate-100 text-slate-700' : 'bg-blue-100 text-blue-700'}`;
            statusBadge.textContent = message.is_read === 1 ? 'Read' : 'Unread';
            statusCell.appendChild(statusBadge);
            
            // Message cell
            const messageCell = document.createElement('td');
            messageCell.className = 'p-4 align-middle';
            messageCell.textContent = message.message;
            
            // Time cell
            const timeCell = document.createElement('td');
            timeCell.className = 'p-4 align-middle text-muted-foreground';
            timeCell.textContent = formatDate(new Date(message.created_at));
            
            // Actions cell
            const actionsCell = document.createElement('td');
            actionsCell.className = 'p-4 align-middle';
            if (!message.is_read) {
                const markReadBtn = document.createElement('button');
                markReadBtn.className = 'inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-8 w-8';
                markReadBtn.innerHTML = '<i data-lucide="check" class="h-4 w-4"></i>';
                markReadBtn.addEventListener('click', () => markAsRead(message.id));
                actionsCell.appendChild(markReadBtn);
            }
            
            // Add delete button
            const deleteBtn = document.createElement('button');
            deleteBtn.className = 'inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-destructive text-destructive-foreground hover:bg-destructive/90 h-8 w-8 ml-2';
            deleteBtn.innerHTML = '<i data-lucide="trash-2" class="h-4 w-4"></i>';
            deleteBtn.addEventListener('click', () => deleteMessage(message.id));
            actionsCell.appendChild(deleteBtn);
            
            tr.appendChild(fromCell);
            tr.appendChild(statusCell);
            tr.appendChild(messageCell);
            tr.appendChild(timeCell);
            tr.appendChild(actionsCell);
            
            inboxList.appendChild(tr);
        });

        // Reinitialize Lucide icons
        lucide.createIcons();
    }

    // Update pagination
    function updatePagination() {
        const startItem = (currentPage - 1) * itemsPerPage + 1;
        const endItem = Math.min(currentPage * itemsPerPage, totalItems);
        paginationInfo.textContent = `Showing ${startItem}-${endItem} of ${totalItems}`;
        
        prevPageButton.disabled = currentPage === 1;
        nextPageButton.disabled = currentPage * itemsPerPage >= totalItems;
    }

    // Search functionality
    function handleSearch() {
        const searchTerm = searchInput.value.toLowerCase();
        const statusFilter = filterStatus.value;
        
        // Get the original messages from the API
        fetch('chat_api.php?inbox=1')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    filteredMessages = data.messages.filter(message => {
                        const matchesSearch = message.message.toLowerCase().includes(searchTerm);
                        const matchesStatus = statusFilter === 'all' || 
                                            (statusFilter === 'read' && message.is_read) ||
                                            (statusFilter === 'unread' && !message.is_read);
                        return matchesSearch && matchesStatus;
                    });
                    
                    currentPage = 1;
                    totalItems = filteredMessages.length;
                    updatePagination();
                    displayMessages();
                }
            })
            .catch(error => {
                if (notification) {
                    showNotification('Error searching messages', 'error');
                }
            });
    }

    // Event listeners
    if (inboxList) {
        searchInput.addEventListener('input', handleSearch);
        filterStatus.addEventListener('change', handleSearch);
        prevPageButton.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                updatePagination();
                displayMessages();
            }
        });
        nextPageButton.addEventListener('click', () => {
            if (currentPage * itemsPerPage < totalItems) {
                currentPage++;
                updatePagination();
                displayMessages();
            }
        });
        refreshButton.addEventListener('click', loadMessages);
        clearButton.addEventListener('click', clearInbox);
        
        // Initial load
        loadMessages();
    }

    // Mark message as read
    function markAsRead(messageId) {
        fetch(`chat_api.php?mark_read=${messageId}`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    // Update the message's read status in the filtered messages array
                    const messageIndex = filteredMessages.findIndex(msg => msg.id === messageId);
                    if (messageIndex !== -1) {
                        filteredMessages[messageIndex].is_read = 1;
                    }
                    // Refresh the display
                    displayMessages();
                    if (notification) {
                        showNotification('Message marked as read');
                    }
                }
            })
            .catch(error => {
                if (notification) {
                    showNotification('Error marking message as read', 'error');
                }
            });
    }

    // Clear all messages
    function clearInbox() {
        alertDialog.classList.remove('hidden');
        
        // Handle cancel
        alertCancel.addEventListener('click', () => {
            alertDialog.classList.add('hidden');
        });

        // Handle confirm
        alertConfirm.addEventListener('click', () => {
            fetch('chat_api.php?clear_inbox=1')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        loadMessages();
                        if (notification) {
                            showNotification('Inbox cleared');
                        }
                    }
                })
                .catch(error => {
                    if (notification) {
                        showNotification('Error clearing inbox', 'error');
                    }
                })
                .finally(() => {
                    alertDialog.classList.add('hidden');
                });
        });
    }

    // Show notification
    function showNotification(message, type = 'success') {
        if (!notification) return; // Exit if we're not on the inbox page
        
        const notificationElement = notification.cloneNode(true);
        notificationElement.classList.remove('hidden');
        
        if (type === 'error') {
            notificationElement.querySelector('.bg-teal-100').classList.replace('bg-teal-100', 'bg-red-100');
            notificationElement.querySelector('.text-teal-600').classList.replace('text-teal-600', 'text-red-600');
            notificationElement.querySelector('.text-slate-900').textContent = 'Error';
        }
        
        notificationElement.querySelector('.notification-message').textContent = message;
        document.body.appendChild(notificationElement);
        
        // Add close button functionality
        notificationElement.querySelector('button').addEventListener('click', () => {
            notificationElement.remove();
        });
        
        // Auto remove after 3 seconds
        setTimeout(() => {
            notificationElement.remove();
        }, 3000);
    }

    // Format date
    function formatDate(date) {
        const now = new Date();
        const diff = now - date;
        
        // Less than 24 hours
        if (diff < 24 * 60 * 60 * 1000) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        
        // Less than 7 days
        if (diff < 7 * 24 * 60 * 60 * 1000) {
            return date.toLocaleDateString([], { weekday: 'short' });
        }
        
        // Otherwise show full date
        return date.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' });
    }

    // Add delete message function
    function deleteMessage(messageId) {
        fetch(`chat_api.php?delete_message=${messageId}`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    // Remove the message from filtered messages
                    filteredMessages = filteredMessages.filter(msg => msg.id !== messageId);
                    totalItems = filteredMessages.length;
                    updatePagination();
                    displayMessages();
                    if (notification) {
                        showNotification('Message deleted');
                    }
                }
            })
            .catch(error => {
                if (notification) {
                    showNotification('Error deleting message', 'error');
                }
            });
    }
}); 