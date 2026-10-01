// ============================================================
// warehouse-scripts.js
// Client-side JS shared across all warehouse pages.
// ============================================================


// Native Canvas Donut Drawer for Primary Warehouse Card (3 Clean Segments)
document.addEventListener("DOMContentLoaded", function() {
    const canvas = document.getElementById('donut-single-wh');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    // Parse the zone data securely from the HTML data attribute
    let zones = [];
    try {
        zones = JSON.parse(canvas.dataset.zones || '[]');
    } catch (e) {
        console.error("Failed to load zone data for donut chart.");
        return;
    }
    
    const classColors = { 'refrigerated': '#0d9488', 'freezer': '#2563eb', 'ambient': '#6b7280' };
    
    // 1. AGGREGATE THE ZONES BY CLASSIFICATION
    let aggregatedData = {
        'refrigerated': { cap: 0 },
        'freezer': { cap: 0 },
        'ambient': { cap: 0 }
    };
    
    let totalCapacity = 0;
    
    zones.forEach(z => {
        let cls = z.Classification ? z.Classification.toLowerCase() : '';
        let cap = parseInt(z.CapacityVolume) || 0;
        
        if (aggregatedData[cls]) {
            aggregatedData[cls].cap += cap;
        }
        totalCapacity += cap;
    });
    
    // Geometry constraints
    const centerX = 60, centerY = 60;
    const radius = 45; 
    const thickness = 14; 
    
    // Failsafe: If warehouse is empty, draw a neutral ring
    if (totalCapacity === 0) {
        ctx.beginPath();
        ctx.arc(centerX, centerY, radius, 0, 2 * Math.PI);
        ctx.lineWidth = thickness;
        ctx.strokeStyle = '#e5e7eb';
        ctx.stroke();
        return;
    }
    
    let currentAngle = -Math.PI / 2; // Start drawing at the top (12 o'clock)
    ctx.lineWidth = thickness;
    
    // 2. DRAW EXACTLY 3 SOLID ARCS (No faded free-space)
    const classifications = ['refrigerated', 'freezer', 'ambient'];
    
    classifications.forEach(cls => {
        let data = aggregatedData[cls];
        if (data.cap === 0) return; // Skip if this warehouse doesn't have this type
        
        let solidHex = classColors[cls];
        let capAngle = (data.cap / totalCapacity) * 2 * Math.PI;
        
        // Draw the solid portion representing the mix
        if (capAngle > 0) {
            ctx.beginPath();
            ctx.arc(centerX, centerY, radius, currentAngle, currentAngle + capAngle);
            ctx.strokeStyle = solidHex;
            ctx.stroke();
            currentAngle += capAngle;
        }
    });
    
    // 3. DRAW WHITE GAPS ONLY BETWEEN THE 3 MAIN CLASSIFICATIONS
    let gapAngle = -Math.PI / 2;
    ctx.lineWidth = 3;
    ctx.strokeStyle = '#ffffff';
    
    classifications.forEach(cls => {
        let data = aggregatedData[cls];
        if (data.cap === 0) return;
        
        let totalAngle = (data.cap / totalCapacity) * 2 * Math.PI;
        gapAngle += totalAngle;
        
        ctx.beginPath();
        ctx.moveTo(centerX + (radius - thickness) * Math.cos(gapAngle), centerY + (radius - thickness) * Math.sin(gapAngle));
        ctx.lineTo(centerX + (radius + thickness) * Math.cos(gapAngle), centerY + (radius + thickness) * Math.sin(gapAngle));
        ctx.stroke();
    });
});

// ---- Routing map -------------------------------------------------
// Where should each match type send the user when they click it?
// Adjust here when new pages exist or destinations change. Each
// function receives the result row from search-ajax.php and returns
// a URL string.
// ----------------------------------------------------------------
const WAREHOUSE_SEARCH_ROUTES = {
    Shipment: function(item) {
        return 'wshipment-details.php?sid=' + encodeURIComponent(item.ID);
    },
    Batch: function(item) {
        return 'wbatchvendor.php?vendor=' + encodeURIComponent(item.VendorID)
             + '&batch=' + encodeURIComponent(item.ID);
    },
    Vendor: function(item) {
        return 'wbatchvendor.php?vendor=' + encodeURIComponent(item.ID);
    },
    Clinic: function(item) {
        // Warehouse interface has no standalone clinic view, so route to the
        // shipments page filtered to shipments destined for this clinic.
        return 'wshipments.php?clinic=' + encodeURIComponent(item.ID);
    },
    Warehouse: function(item) {
        return 'warehouse-details.php?wid=' + encodeURIComponent(item.ID);
    }
};

// ============================================================
// OPT-W-1: Zone Breach Frequency Ranking Chart
// ============================================================
document.addEventListener("DOMContentLoaded", function() {
    const canvas = document.getElementById('zoneBreachChart');
    if (!canvas || typeof Chart === 'undefined') return;

    let rawData = [];
    let phiThreshold = 5;

    // Securely parse the data from the HTML attributes
    try {
        rawData = JSON.parse(canvas.dataset.breaches || '[]');
        phiThreshold = parseInt(canvas.dataset.phi, 10) || 5;
    } catch (e) {
        console.error("Failed to load breach data for chart.");
        return;
    }

    if (rawData.length === 0) return;

    // Map the JSON array into Chart.js format
    const labels = rawData.map(d => d.label);
    const dataPoints = rawData.map(d => d.count);
    const bgColors = rawData.map(d => {
        if (d.class === 'refrigerated') return '#0d9488'; // Teal
        if (d.class === 'freezer') return '#2563eb';      // Blue
        return '#6b7280';                                 // Grey (Ambient)
    });

    // Custom Chart.js Plugin to draw the Phi threshold line
    const referenceLinePlugin = {
        id: 'referenceLine',
        afterDraw: (chart) => {
            const ctx = chart.ctx;
            const xAxis = chart.scales.x;
            const yAxis = chart.scales.y;

            // Only draw the line if the threshold is within the visible graph range
            if (phiThreshold >= xAxis.min && phiThreshold <= xAxis.max) {
                const xPos = xAxis.getPixelForValue(phiThreshold);
                
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(xPos, yAxis.top);
                ctx.lineTo(xPos, yAxis.bottom);
                ctx.lineWidth = 2;
                ctx.strokeStyle = '#e53e3e'; // Alert Red
                ctx.setLineDash([6, 6]);
                ctx.stroke();

                // Label the line
                ctx.fillStyle = '#e53e3e';
                ctx.font = 'bold 12px Arial';
                ctx.fillText('Threshold (φ=' + phiThreshold + ')', xPos + 8, yAxis.top + 15);
                ctx.restore();
            }
        }
    };

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Total Excursions',
                data: dataPoints,
                backgroundColor: bgColors,
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y', // Flips it to a horizontal bar chart
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 } // Forces whole numbers on the x-axis
                }
            },
            plugins: {
                legend: { display: false } // Hides default legend, colors match classifications
            }
        },
        plugins: [referenceLinePlugin]
    });
});


// ─────────────────────────────────────────────────────────────────────────────
// OPT-W-2: Average Lot Dwell Time by Zone Classification
// Reads data from data-* attributes on the canvas element, matching the
// same pattern used by zoneBreachChart in whome.php.
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('dwellChart');
    if (!canvas) return;

    const results   = JSON.parse(canvas.dataset.results   || '[]');
    const threshold = parseFloat(canvas.dataset.threshold || 60);

    if (!results.length) return;

    const labels   = results.map(function(r) { return r.class.charAt(0).toUpperCase() + r.class.slice(1); });
    const avgs     = results.map(function(r) { return parseFloat(r.avg_days); });
    const stddevs  = results.map(function(r) { return parseFloat(r.stddev_days) || 0; });

    drawDwellChart('dwellChart', labels, avgs, stddevs, threshold);
});

// ─────────────────────────────────────────────────────────────────────────────
// drawDwellChart
//
// canvasId   – id of the <canvas> element
// labels     – zone classification labels  ['Refrigerated', 'Freezer', 'Ambient']
// avgs       – average dwell in days per zone
// stddevs    – standard deviation in days per zone (error bars ±1 SD)
// threshold  – configurable dwell threshold (horizontal dashed reference line)
// ─────────────────────────────────────────────────────────────────────────────
function drawDwellChart(canvasId, labels, avgs, stddevs, threshold) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !labels || labels.length === 0) return;

    // Size canvas to fill its container (matches zoneBreachChart behaviour)
    canvas.width  = canvas.parentElement.offsetWidth  || 700;
    canvas.height = canvas.parentElement.offsetHeight || 400;

    const ctx = canvas.getContext('2d');
    const W   = canvas.width;
    const H   = canvas.height;

    const PAD_LEFT   = 60;
    const PAD_RIGHT  = 80; // room for threshold label
    const PAD_TOP    = 30;
    const PAD_BOTTOM = 50;

    const chartW = W - PAD_LEFT - PAD_RIGHT;
    const chartH = H - PAD_TOP  - PAD_BOTTOM;

    // Scale — ceiling is max(avg + stddev, threshold) + 10% headroom
    var maxVal = threshold;
    avgs.forEach(function(a, i) {
        var top = a + (stddevs[i] || 0);
        if (top > maxVal) maxVal = top;
    });
    maxVal = maxVal * 1.15 || 1;

    function toY(v) { return PAD_TOP + chartH - (v / maxVal) * chartH; }

    // Bar geometry
    var barW    = Math.min(80, Math.floor(chartW / labels.length * 0.5));
    var barStep = chartW / labels.length;
    function barX(i) { return PAD_LEFT + i * barStep + (barStep - barW) / 2; }

    // Zone colours
    var colours = { 'Refrigerated': '#0d9488', 'Freezer': '#2563eb', 'Ambient': '#6b7280' };

    ctx.clearRect(0, 0, W, H);

    // ── Y-axis gridlines + labels ─────────────────────────────────────────
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth   = 1;
    ctx.fillStyle   = '#718096';
    ctx.font        = '11px Inter, "Segoe UI", sans-serif';
    ctx.textAlign   = 'right';
    ctx.setLineDash([]);

    var tickCount = 5;
    for (var t = 0; t <= tickCount; t++) {
        var v = (maxVal / tickCount) * t;
        var y = toY(v);
        ctx.beginPath();
        ctx.moveTo(PAD_LEFT, y);
        ctx.lineTo(PAD_LEFT + chartW, y);
        ctx.stroke();
        ctx.fillText(v.toFixed(1) + 'd', PAD_LEFT - 6, y + 4);
    }

    // ── Bars ─────────────────────────────────────────────────────────────
    avgs.forEach(function(avg, i) {
        var x      = barX(i);
        var yTop   = toY(avg);
        var yBase  = toY(0);
        var height = Math.max(yBase - yTop, 1);
        var colour = colours[labels[i]] || '#718096';

        ctx.fillStyle = (avg > threshold) ? 'rgba(229, 62, 62, 0.75)' : colour;

        ctx.beginPath();
        if (ctx.roundRect) {
            ctx.roundRect(x, yTop, barW, height, [4, 4, 0, 0]);
        } else {
            ctx.rect(x, yTop, barW, height);
        }
        ctx.fill();

        // Value label above bar
        ctx.fillStyle = '#2d3748';
        ctx.font      = 'bold 11px Inter, "Segoe UI", sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(avg.toFixed(1) + 'd', x + barW / 2, yTop - 20);

        // ── Error bars (±1 SD) ────────────────────────────────────────────
        var sd = stddevs[i] || 0;
        if (sd > 0) {
            var yHigh = toY(avg + sd);
            var yLow  = toY(Math.max(avg - sd, 0));
            var cx    = x + barW / 2;
            var capW  = barW * 0.25;

            ctx.strokeStyle = '#4a5568';
            ctx.lineWidth   = 1.5;
            ctx.setLineDash([]);

            ctx.beginPath(); ctx.moveTo(cx, yHigh); ctx.lineTo(cx, yLow); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(cx - capW, yHigh); ctx.lineTo(cx + capW, yHigh); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(cx - capW, yLow);  ctx.lineTo(cx + capW, yLow);  ctx.stroke();

            ctx.fillStyle = '#718096';
            ctx.font      = '10px Inter, "Segoe UI", sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('\u00b1' + sd.toFixed(1) + 'd', cx, yHigh - 5);
        }
    });

    // ── X-axis labels ─────────────────────────────────────────────────────
    ctx.fillStyle = '#2d3748';
    ctx.font      = '12px Inter, "Segoe UI", sans-serif';
    ctx.textAlign = 'center';
    ctx.setLineDash([]);

    labels.forEach(function(label, i) {
        ctx.fillText(label, barX(i) + barW / 2, PAD_TOP + chartH + 20);
    });

    // ── Threshold reference line ──────────────────────────────────────────
    var threshY = toY(threshold);

    ctx.save();
    ctx.setLineDash([6, 4]);
    ctx.strokeStyle = '#e53e3e';
    ctx.lineWidth   = 1.5;
    ctx.beginPath();
    ctx.moveTo(PAD_LEFT, threshY);
    ctx.lineTo(PAD_LEFT + chartW, threshY);
    ctx.stroke();
    ctx.restore();

    ctx.setLineDash([]);
    ctx.fillStyle = '#e53e3e';
    ctx.font      = 'bold 11px Inter, "Segoe UI", sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('threshold: ' + threshold + 'd', PAD_LEFT + chartW + 4, threshY + 4);

    // ── Axis lines ────────────────────────────────────────────────────────
    ctx.strokeStyle = '#cbd5e0';
    ctx.lineWidth   = 1;
    ctx.setLineDash([]);

    ctx.beginPath(); ctx.moveTo(PAD_LEFT, PAD_TOP); ctx.lineTo(PAD_LEFT, PAD_TOP + chartH); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(PAD_LEFT, PAD_TOP + chartH); ctx.lineTo(PAD_LEFT + chartW, PAD_TOP + chartH); ctx.stroke();

    // ── Y-axis title ──────────────────────────────────────────────────────
    ctx.save();
    ctx.fillStyle   = '#718096';
    ctx.font        = '11px Inter, "Segoe UI", sans-serif';
    ctx.textAlign   = 'center';
    ctx.translate(14, PAD_TOP + chartH / 2);
    ctx.rotate(-Math.PI / 2);
    ctx.fillText('Average Dwell (days)', 0, 0);
    ctx.restore();
}

// ---- Live search dropdown --------------------------------------
function liveSearch(query) {
    const resultsDiv = document.getElementById('searchResults');
    if (!resultsDiv) return;

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

            if (data.length === 0) {
                resultsDiv.innerHTML =
                    '<div class="search-item muted">No matches found.</div>';
                resultsDiv.style.display = 'block';
                return;
            }

            data.forEach(function(item) {
                const row = document.createElement('div');
                row.className = 'search-item';

                row.innerHTML =
                    '<span><strong>' + escapeHtml(item.Text) + '</strong></span>' +
                    '<span class="search-type-badge">' + escapeHtml(item.Type) + '</span>';

                row.onclick = function() {
                    const router = WAREHOUSE_SEARCH_ROUTES[item.Type];
                    if (router) {
                        window.location.href = router(item);
                    } else {
                        // Unknown type — log it but don't crash
                        console.warn('No route configured for match type:', item.Type);
                    }
                };

                resultsDiv.appendChild(row);
            });

            resultsDiv.style.display = 'block';
        })
        .catch(function(error) {
            console.error('Search failed:', error);
            resultsDiv.innerHTML =
                '<div class="search-item muted">Search error — try again.</div>';
            resultsDiv.style.display = 'block';
        });
}


// ---- Close dropdown when clicking outside ----------------------
document.addEventListener('click', function(e) {
    const searchBox  = document.getElementById('globalSearch');
    const resultsDiv = document.getElementById('searchResults');
    if (!searchBox || !resultsDiv) return;

    // If the click landed outside both the input and the dropdown, hide it
    if (e.target !== searchBox && !resultsDiv.contains(e.target)) {
        resultsDiv.style.display = 'none';
    }
});


// ---- Helpers ---------------------------------------------------
// Inserting AJAX-returned text via innerHTML without escaping would let a
// malicious vendor name (etc.) inject script tags. The endpoint returns
// data from the DB — even if it's "trusted", treat it as untrusted here.
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
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
