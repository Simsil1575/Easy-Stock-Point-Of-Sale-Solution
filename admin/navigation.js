function showLoader() {
    const loader = document.createElement('div');
    loader.id = 'loader';
    loader.className = 'fixed inset-0 flex items-center justify-center z-50';
    loader.innerHTML = `
        <div class="spinner"></div>
        <style>
            .spinner {
                width: 56px;
                height: 56px;
                border-radius: 50%;
                border: 4px solid;
                border-color: #f3ec78;
                border-right-color: #ffbb00;
                animation: spinner-d3wgkg 0.5s infinite linear;
            }

            @keyframes spinner-d3wgkg {
                to {
                    transform: rotate(1turn);
                }
            }
        </style>
    `;
    document.body.appendChild(loader);
}

function hideLoader() {
    const loader = document.getElementById('loader');
    if (loader) {
        loader.remove();
    }
}
