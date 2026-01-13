<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Custom Search Test</title>
      <link href="src/output.css" rel="stylesheet"></head>
<body class="p-8">
    <div class="max-w-2xl mx-auto">
        <h1 class="text-2xl font-bold mb-4">Google Custom Search Test</h1>
        
        <div class="mb-4">
            <input type="text" id="searchInput" class="w-full border rounded px-4 py-2" placeholder="Enter search term...">
            <button onclick="search()" class="mt-2 bg-blue-500 text-white px-4 py-2 rounded">Search</button>
        </div>

        <div id="results" class="space-y-4"></div>
    </div>

    <script>
        async function search() {
            const searchTerm = document.getElementById('searchInput').value;
            const apiKey = 'AIzaSyBWT8oRowDhOfD9kD8jNCgd31Z0hiuGFaY';
            const cx = '017576662512468239146:omuauf_lfve'; // Added a sample Search Engine ID
            
            if (!searchTerm) {
                document.getElementById('results').innerHTML = 'Please enter a search term.';
                return;
            }

            try {
                const response = await fetch(
                    `https://www.googleapis.com/customsearch/v1?key=${apiKey}&cx=${cx}&q=${encodeURIComponent(searchTerm)}`
                );
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                displayResults(data.items);
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('results').innerHTML = `An error occurred while searching: ${error.message}`;
            }
        }

        function displayResults(items) {
            const resultsDiv = document.getElementById('results');
            resultsDiv.innerHTML = '';
            
            if (!items || items.length === 0) {
                resultsDiv.innerHTML = 'No results found.';
                return;
            }

            items.forEach(item => {
                const resultDiv = document.createElement('div');
                resultDiv.className = 'border p-4 rounded';
                resultDiv.innerHTML = `
                    <h2 class="text-xl font-semibold">
                        <a href="${item.link}" class="text-blue-600 hover:underline" target="_blank">
                            ${item.title}
                        </a>
                    </h2>
                    <p class="text-teal-700 text-sm">${item.link}</p>
                    <p class="mt-2">${item.snippet}</p>
                `;
                resultsDiv.appendChild(resultDiv);
            });
        }
    </script>
</body>
</html>