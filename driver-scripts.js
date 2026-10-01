// Driver Dashboard Scripts

function drawSparkline(canvasId, data, minT, maxT) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !data || data.length < 2) return;
    
    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;
    
    const minData = Math.min.apply(null, data);
    const maxData = Math.max.apply(null, data);
    
    let range = maxData - minData;
    if (range === 0) range = 1; 

    ctx.clearRect(0, 0, width, height);
    ctx.lineWidth = 2;

    function getY(val) {
        if (maxData === minData) return height / 2;
        return 2 + (height - 4) - ((val - minData) / range) * (height - 4);
    }

    for (let i = 0; i < data.length - 1; i++) {
        const x1 = (i / (data.length - 1)) * width;
        const y1 = getY(data[i]);
        const x2 = ((i + 1) / (data.length - 1)) * width;
        const y2 = getY(data[i+1]);

        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);

        if (data[i] < minT || data[i] > maxT) {
            ctx.strokeStyle = '#e53e3e'; 
        } else {
            ctx.strokeStyle = '#3182ce'; 
        }
        
        ctx.stroke(); 
    }
}

// Ensure the page is fully loaded before drawing charts
document.addEventListener("DOMContentLoaded", function() {
    
    // Check if the canvas and our Data Bridge exist on this specific page
    const canvas = document.getElementById('tempChart');
    
    if (canvas && window.PharmaCoolChartData) {
        // Grab the data passed from PHP
        const data = window.PharmaCoolChartData;
        const ctx = canvas.getContext('2d');

        // Generate straight horizontal lines for our min and max limits
        const minLine = Array(data.labels.length).fill(data.minTemp);
        const maxLine = Array(data.labels.length).fill(data.maxTemp);

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Internal Temperature (°C)',
                        data: data.dataPoints,
                        borderColor: '#a0aec0', // Neutral grey line
                        borderWidth: 2,
                        pointBackgroundColor: data.pointColors, // Dynamic red/blue array
                        pointBorderColor: data.pointColors,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: false,
                        tension: 0.2 // Slight curve to the line
                    },
                    {
                        label: 'Max Allowed Limit',
                        data: maxLine,
                        borderColor: 'rgba(229, 62, 62, 0.5)', // Faded red
                        borderWidth: 2,
                        borderDash: [5, 5], // Dashed line
                        pointRadius: 0,
                        fill: false
                    },
                    {
                        label: 'Min Allowed Limit',
                        data: minLine,
                        borderColor: 'rgba(49, 130, 206, 0.5)', // Faded blue
                        borderWidth: 2,
                        borderDash: [5, 5], // Dashed line
                        pointRadius: 0,
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        title: {
                            display: true,
                            text: 'Temperature (°C)'
                        },
                        // Add padding so the graph doesn't cramp against the edges
                        suggestedMin: data.minTemp - 2,
                        suggestedMax: data.maxTemp + 2
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
});

document.addEventListener('click', function(e) {
    const searchBox = document.getElementById('globalSearch');
    const resultsDiv = document.getElementById('searchResults');
    if (resultsDiv && searchBox) {
        if (e.target !== searchBox && e.target !== resultsDiv) {
            resultsDiv.style.display = 'none';
        }
    }
});

function liveSearch(query) {
    const resultsDiv = document.getElementById('searchResults');
    
    if (query.length < 2) {
        resultsDiv.style.display = 'none';
        return;
    }

    fetch('search-ajax.php?q=' + encodeURIComponent(query))
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            resultsDiv.innerHTML = ''; 
            
            if (data.length > 0) {
                data.forEach(function(item) {
                    const row = document.createElement('div');
                    row.className = 'search-item';
                    
                    row.innerHTML = `
                        <span><strong>${item.Text}</strong></span>
                        <span class="search-type-badge">${item.Type}</span>
                    `;
                    
                    row.onclick = function() {
                        window.location.href = 'shp-details.php?id=' + encodeURIComponent(item.ShipmentID);
                    };
                    
                    resultsDiv.appendChild(row);
                });
                resultsDiv.style.display = 'block';
            } else {
                resultsDiv.innerHTML = '<div class="search-item text-muted">No matches found.</div>';
                resultsDiv.style.display = 'block';
            }
        })
        .catch(function(error) {
            console.error('Search failed:', error);
        });
}


document.addEventListener("DOMContentLoaded", function() {
    
    // --- 1. Settings Dropdown Logic ---
    const settingsBtn = document.getElementById('settings-btn');
    const settingsDropdown = document.getElementById('settings-dropdown');

    if (settingsBtn && settingsDropdown) {
        // Toggle menu when clicking the gear
        settingsBtn.addEventListener('click', function(e) {
            e.stopPropagation(); // Stops the window click event from instantly closing it
            settingsDropdown.classList.toggle('show');
        });

        // Close the menu if the user clicks anywhere else on the screen
        window.addEventListener('click', function(e) {
            if (!settingsDropdown.contains(e.target)) {
                settingsDropdown.classList.remove('show');
            }
        });
    }

    // --- 2. Dark Mode Logic ---
    const toggleBtn = document.getElementById('theme-toggle');
    const body = document.body;

    if (toggleBtn) {
        // Check memory on load
        if (localStorage.getItem('PharmaCoolTheme') === 'dark') {
            body.classList.add('dark-mode');
            toggleBtn.innerHTML = '☀️ Light Mode';
        }

        // Listen for the toggle click inside the dropdown
        toggleBtn.addEventListener('click', (e) => {
            // e.stopPropagation(); // Uncomment this line if you want the menu to stay open after clicking Dark Mode
            body.classList.toggle('dark-mode');
            
            if (body.classList.contains('dark-mode')) {
                localStorage.setItem('PharmaCoolTheme', 'dark');
                toggleBtn.innerHTML = '☀️ Light Mode';
            } else {
                localStorage.setItem('PharmaCoolTheme', 'light');
                toggleBtn.innerHTML = '🌙 Dark Mode';
            }
        });
    }
});