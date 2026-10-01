/* =====================================================================
 * auto-charts.js
 *
 * Self-registering Chart.js initialization for warehouse pages.
 * Designed to keep chart logic OUT of PHP files so it can't get
 * accidentally orphaned during template edits or redeployments.
 *
 * USAGE
 * -----
 * 1. Load Chart.js, then this file, in your page <head>:
 *      <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
 *      <script src="auto-charts.js?v=<?php echo time(); ?>"></script>
 *
 * 2. Add a <canvas> with class="auto-chart" and a data-chart-type
 *    attribute. Per-chart-type data attributes are documented
 *    next to each INIT entry below.
 *
 *    Example (system occupancy donut):
 *      <canvas class="auto-chart"
 *              data-chart-type="occ-mix"
 *              data-refrig="54211"
 *              data-freezer="18234"
 *              data-ambient="73073"></canvas>
 *
 * 3. The chart renders on DOMContentLoaded. No PHP-side <script>
 *    block needed in the consuming page.
 *
 * ADDING A NEW CHART TYPE
 * -----------------------
 * Add a new entry to the INIT registry below. The function
 * receives the canvas element and must call `new Chart(canvas, ...)`.
 * That's it — no other wiring required.
 * ===================================================================== */

(function () {
    'use strict';

    var INIT = {};

    /* -----------------------------------------------------------------
     * Chart type: occ-mix
     *   Renders a 3-segment doughnut for system / scoped occupancy by
     *   zone classification (refrigerated / freezer / ambient).
     *   Uses 1:1 aspect ratio so it can never render oval.
     *
     * Required data attributes (all integers):
     *   data-refrig   — units stored in refrigerated zones
     *   data-freezer  — units stored in freezer zones
     *   data-ambient  — units stored in ambient zones
     *
     * Optional:
     *   data-empty-label — label shown in the tooltip when total is 0
     *                      (defaults to "No lots in storage")
     * ----------------------------------------------------------------- */
    INIT['occ-mix'] = function (canvas) {
        var refrig  = parseInt(canvas.dataset.refrig,  10) || 0;
        var freezer = parseInt(canvas.dataset.freezer, 10) || 0;
        var ambient = parseInt(canvas.dataset.ambient, 10) || 0;
        var total   = refrig + freezer + ambient;
        var emptyLabel = canvas.dataset.emptyLabel || 'No lots in storage';

        var data, labels, colors;
        if (total === 0) {
            data   = [1];
            labels = [emptyLabel];
            colors = ['#e5e7eb'];
        } else {
            data   = [refrig, freezer, ambient];
            labels = ['Refrigerated', 'Freezer', 'Ambient'];
            colors = ['#0d9488', '#2563eb', '#6b7280'];
        }

        return new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                cutout: '62%',
                responsive: true,
                maintainAspectRatio: true,
                aspectRatio: 1,
                animation: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (c) {
                                if (total === 0) return emptyLabel;
                                var pct = ((c.parsed / total) * 100).toFixed(1) + '%';
                                return c.label + ': ' + c.parsed + ' (' + pct + ')';
                            }
                        }
                    }
                }
            }
        });
INIT['weekly-volume'] = function (canvas) {
        const raw = JSON.parse(canvas.dataset.series);
        
        return new Chart(canvas, {
            type: 'bar',
            data: {
                labels: raw.weeks.map(w => 'Wk ' + w.substring(5, 10)),
                datasets: [
                    {
                        label: 'Outbound Vol',
                        data: raw.outbound,
                        backgroundColor: '#0d9488',
                        borderRadius: 4
                    },
                    {
                        label: 'Inbound Vol',
                        data: raw.inbound,
                        backgroundColor: '#2563eb',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                },
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Liters' } }
                }
            }
        });
    };
        
    };

    /* -----------------------------------------------------------------
     * Add future chart types here. One entry per type. Examples of
     * patterns we'll likely need later:
     *
     *   INIT['stale-histogram'] — bar chart, reads JSON from data-bins
     *   INIT['zone-temp-line']  — line chart, reads JSON from data-points
     *   INIT['vendor-breach']   — horizontal bar chart, reads from
     *                              data-vendors (JSON array)
     *
     * For chart types that need complex/array data, store it as a
     * JSON string in a single data attribute and JSON.parse() it
     * inside the init function. Simple integers go in their own
     * attributes (as occ-mix does above).
     * ----------------------------------------------------------------- */

    /* -----------------------------------------------------------------
     * Bootstrap — runs once on DOMContentLoaded (or immediately if
     * the DOM is already parsed). Finds every canvas.auto-chart and
     * dispatches to the appropriate INIT function.
     * ----------------------------------------------------------------- */
    function bootstrap() {
        if (typeof Chart === 'undefined') {
            console.warn('[auto-charts] Chart.js not loaded — no charts will render.');
            return;
        }

        var canvases = document.querySelectorAll('canvas.auto-chart');
        canvases.forEach(function (canvas) {
            var type = canvas.dataset.chartType;
            if (!type) {
                console.warn('[auto-charts] canvas.auto-chart has no data-chart-type:', canvas);
                return;
            }
            var initFn = INIT[type];
            if (!initFn) {
                console.warn('[auto-charts] Unknown chart type "' + type + '" on', canvas);
                return;
            }
            try {
                initFn(canvas);
            } catch (err) {
                console.error('[auto-charts] Failed to init "' + type + '":', err);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
