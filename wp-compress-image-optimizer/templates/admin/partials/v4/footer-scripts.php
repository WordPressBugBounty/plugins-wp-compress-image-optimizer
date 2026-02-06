<script type="text/javascript">

    <?php
    $labels = [];
    $in_traffic_sum = '';
    $in_traffic = '';
    $out_traffic = '';
    $images_charts = '';
    $date_format = 'm/d/Y';  //Date format in dashboard chart

    function format_KB($value)
    {
        $value = $value / 1000;
        $value = $value / 1000;

        return round($value, 2);
    }

    $labels_dates = [];
    $limit = 10;

    // Calculate offset
    $item = 0;

    // Initialize
    $statsclass = new wps_ic_stats();
    $stats = false;
    $use_cloudflare = false;
    $data_source = 'none'; // For debugging/logging

    // Check if Cloudflare is connected
    $cf = get_option(WPS_IC_CF);
    $use_cloudflare = !empty($cf) && !empty($cf['token']);

    if ($use_cloudflare) {

        // Try to get Cloudflare stats
        $statsclass = new wps_ic_stats();
        $stats = $statsclass->fetch_cloudflare_stats(7);

        if (!$stats) {
            // CF fetch failed, fallback to regular stats
            $use_cloudflare = false;
            if (empty($gui::$stats_live)) {
                $stats = $statsclass->fetch_sample_stats();
            } else {
                $stats = $gui::$stats_live;
            }
        }
    } elseif (empty($gui::$stats_live)) {
        // No CF, use sample data
        $statsclass = new wps_ic_stats();
        $stats = $statsclass->fetch_sample_stats();
    } else {
        // Use live stats
        $stats = $gui::$stats_live;
    }

    if ($stats) {
        foreach ($stats as $date => $value) {
            $index = date('d-m-Y', strtotime($date));
            $labels[$index]['date'] = date('m/d/Y', strtotime($date));
            $labels[$index]['total_input'] = $value->original;
            $labels[$index]['total_output'] = $value->compressed;

            if ($labels[$index]['total_input'] < 0) {
                $labels[$index]['total_input'] = 0;
            }

            if ($labels[$index]['total_output'] < 0) {
                $labels[$index]['total_output'] = 0;
            }
        }
    }

    asort($labels);

    $count_labels = count($labels);
    if ($count_labels == 4) {
        $catpercentage = 0.20;
    } elseif ($count_labels == 3) {
        $catpercentage = 0.12;
    } elseif ($count_labels <= 2) {
        $catpercentage = 0.05;
    } elseif ($count_labels >= 5 && $count_labels <= 8) {
        $catpercentage = 0.2;
    } elseif ($count_labels >= 8 && $count_labels <= 10) {
        $catpercentage = 0.4;
    } else {
        $catpercentage = 0.55;
    }

    // Parse to javascript
    $labels_js = '';
    $biggestY = 0;
    if ($labels) {
        foreach ($labels as $date => $data) {
            $labels_js .= '"' . date($date_format, strtotime($data['date'])) . '",';
            $in_traffic .= format_KB($data['total_input'] - $data['total_output']) . ',';
            $out_traffic .= format_KB($data['total_output']) . ',';
            $in_traffic_sum .= format_KB($data['total_input']) . ',';

            $kbIN = format_KB($data['total_input']);
            $kbOUT = format_KB($data['total_output']);

            if ($kbIN > $kbOUT) {
                if ($biggestY < $kbIN) {
                    $biggestY = $kbIN;
                }
            } else {
                if ($biggestY < $kbOUT) {
                    $biggestY = $kbOUT;
                }
            }
        }
    }

    // Calculate Max
    $biggestY = ceil($biggestY);
    $fig = (int)str_pad('1', 2, '0');
    $maxY = ceil((ceil($biggestY * $fig) / $fig));

    $stepSize = $maxY / 10;

    if ($maxY <= 10) {
        $stepSize = 1;
    } elseif ($maxY <= 100) {
        $stepSize = 10;
    } elseif ($maxY <= 500) {
        $stepSize = 25;
    } elseif ($maxY <= 1000) {
        $stepSize = 100;
    } elseif ($maxY <= 2000) {
        $stepSize = 200;
    } else {
        $stepSize = 500;
    }

    if (!empty($labels) && !empty($stats)) {

    $out_traffic = rtrim($out_traffic, ',');
    $in_traffic = rtrim($in_traffic, ',');
    $images_charts = rtrim($images_charts, ',');
    $labels_js = rtrim($labels_js, ',');

    ?>

    // ============================================================
    // CHART DATA SETUP
    // ============================================================
    var chartLabels = [];
    var dataBottom  = []; // Bottom stack (darker blue)
    var dataTop     = []; // Top stack (lighter blue)
    var dataTotal   = []; // Total for tooltips
    var trafficSum  = ''; // For tooltip calculations

    var labelBottom = "After Optimization";
    var labelTop    = "Savings";
    var isCloudflareMode = false;
    var dataSource = '<?php echo $data_source; ?>'; // Track data source

    <?php
    $chart_labels = [];
    $data_bottom  = [];
    $data_top     = [];
    $data_total   = [];
    $traffic_sum_array = [];

    function format_KB_clean($value) {
        return round($value / 1000000, 2);
    }

    if ($stats) {
        foreach ($stats as $date => $value) {
            // Remove year from date format
            $formatted_date = date('m/d', strtotime($date));
            $chart_labels[] = $formatted_date;

            $input  = max(0, $value->original);
            $output = max(0, $value->compressed);

            $data_bottom[] = format_KB_clean($output);
            $data_top[]    = format_KB_clean($input - $output);
            $data_total[]  = format_KB_clean($input);
            $traffic_sum_array[] = format_KB_clean($input);
        }
    }
    ?>

    chartLabels = <?php echo json_encode($chart_labels); ?>;
    dataBottom  = <?php echo json_encode($data_bottom); ?>;
    dataTop     = <?php echo json_encode($data_top); ?>;
    dataTotal   = <?php echo json_encode($data_total); ?>;
    trafficSum  = <?php echo json_encode($traffic_sum_array); ?>.join(',');

    <?php if ($use_cloudflare) { ?>
    labelBottom = "Cached Traffic";
    labelTop = "Non-Cached";
    isCloudflareMode = true;
    <?php } ?>

    // ============================================================
    // PREMIUM ENTERPRISE CHART CONFIGURATION
    // ============================================================
    var config = {
        type: 'bar',
        data: {
            labels: chartLabels,
            datasets: [
                {
                    label: labelBottom,
                    backgroundColor: function(context) {
                        if (!context.chart.chartArea) {
                            return '#3c4cdf';
                        }
                        const ctx = context.chart.ctx;
                        const gradient = ctx.createLinearGradient(0, context.chart.chartArea.bottom, 0, context.chart.chartArea.top);
                        gradient.addColorStop(0, '#2d3ba8');
                        gradient.addColorStop(0.5, '#3c4cdf');
                        gradient.addColorStop(1, '#4e5ef0');
                        return gradient;
                    },
                    hoverBackgroundColor: function(context) {
                        const ctx = context.chart.ctx;
                        const gradient = ctx.createLinearGradient(0, context.chart.chartArea.bottom, 0, context.chart.chartArea.top);
                        gradient.addColorStop(0, '#253096');
                        gradient.addColorStop(1, '#4251d9');
                        return gradient;
                    },
                    borderColor: 'rgba(255, 255, 255, 0)',
                    borderWidth: 3,
                    borderSkipped: false,
                    borderRadius: {
                        topLeft: 100,
                        topRight: 100,
                        bottomLeft: 100,
                        bottomRight: 100
                    },
                    barThickness: 16,
                    minBarLength: 8,
                    data: dataBottom
                },
                {
                    label: labelTop,
                    backgroundColor: function(context) {
                        if (!context.chart.chartArea) {
                            return '#64b5f6';
                        }
                        const ctx = context.chart.ctx;
                        const gradient = ctx.createLinearGradient(0, context.chart.chartArea.bottom, 0, context.chart.chartArea.top);
                        gradient.addColorStop(0, '#42a5f5');
                        gradient.addColorStop(0.5, '#64b5f6');
                        gradient.addColorStop(1, '#81c9fa');
                        return gradient;
                    },
                    hoverBackgroundColor: function(context) {
                        const ctx = context.chart.ctx;
                        const gradient = ctx.createLinearGradient(0, context.chart.chartArea.bottom, 0, context.chart.chartArea.top);
                        gradient.addColorStop(0, '#3a9ae8');
                        gradient.addColorStop(1, '#72bdfa');
                        return gradient;
                    },
                    borderColor: 'rgba(255, 255, 255, 0)',
                    borderWidth: 3,
                    borderSkipped: false,
                    borderRadius: {
                        topLeft: 100,
                        topRight: 100,
                        bottomLeft: 100,
                        bottomRight: 100
                    },
                    barThickness: 16,
                    minBarLength: 8,
                    data: dataTop
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 900,
                easing: 'easeInOutCubic',
                delay: function(context) {
                    let delay = 0;
                    if (context.type === 'data' && context.mode === 'default') {
                        delay = context.dataIndex * 80 + context.datasetIndex * 40;
                    }
                    return delay;
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    enabled: false, // We'll use external tooltip for full control
                    external: function(context) {
                        // Get or create tooltip element
                        let tooltipEl = document.getElementById('wpc-chartjs-tooltip');

                        if (!tooltipEl) {
                            tooltipEl = document.createElement('div');
                            tooltipEl.id = 'wpc-chartjs-tooltip';
                            tooltipEl.style.position = 'absolute';
                            tooltipEl.style.pointerEvents = 'none';
                            tooltipEl.style.transition = 'opacity 0.25s cubic-bezier(0.4, 0, 0.2, 1), transform 0.25s cubic-bezier(0.4, 0, 0.2, 1)';
                            document.body.appendChild(tooltipEl);
                        }

                        const tooltipModel = context.tooltip;

                        // Hide with smooth fade out
                        if (tooltipModel.opacity === 0) {
                            tooltipEl.style.opacity = 0;
                            tooltipEl.style.transform = 'translateY(-8px) scale(0.95)';
                            return;
                        }

                        // Get data
                        const dataIndex = tooltipModel.dataPoints[0].dataIndex;
                        const trafficTotal = trafficSum.split(',');
                        const original = parseFloat(trafficTotal[dataIndex]);
                        const after = parseFloat(dataBottom[dataIndex]);
                        const saved = parseFloat(dataTop[dataIndex]);
                        const percent = original > 0 ? ((saved / original) * 100).toFixed(1) : 0;

                        const originalLabel = isCloudflareMode ? 'Total Traffic' : 'Original';
                        const afterLabel = isCloudflareMode ? 'Cached Traffic' : 'After Optimization';

                        // Format date as "Month Day" (e.g., "January 7")
                        const rawDate = tooltipModel.title[0];
                        const [month, day] = rawDate.split('/');
                        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                            'July', 'August', 'September', 'October', 'November', 'December'];
                        const formattedDate = `${monthNames[parseInt(month) - 1]} ${parseInt(day)}`;

                        // Prepare formatted strings for Tooltip
                        var afterStr    = after >= 1000 ? (after/1000).toFixed(2) + ' GB' : after.toFixed(2) + ' MB';
                        var originalStr = original >= 1000 ? (original/1000).toFixed(2) + ' GB' : original.toFixed(2) + ' MB';
                        var savedStr    = saved >= 1000 ? (saved/1000).toFixed(2) + ' GB' : saved.toFixed(2) + ' MB';

                        // Build HTML
                        let innerHtml = `
                            <div class="wpc-tooltip-container">
                                <div class="wpc-tooltip-header">
                                    <span class="wpc-tooltip-date">${formattedDate}</span>
                                </div>
                                <div class="wpc-tooltip-body">
                                    <div class="wpc-tooltip-row">
                                        <div class="wpc-tooltip-badge wpc-badge-dark"></div>
                                        <div class="wpc-tooltip-content">
                                            <span class="wpc-tooltip-label">${afterLabel}</span>
                                            <span class="wpc-tooltip-value">${afterStr}</span>
                                        </div>
                                    </div>
                                    <div class="wpc-tooltip-row">
                                        <div class="wpc-tooltip-badge wpc-badge-light"></div>
                                        <div class="wpc-tooltip-content">
                                            <span class="wpc-tooltip-label">${originalLabel}</span>
                                            <span class="wpc-tooltip-value">${originalStr}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="wpc-tooltip-divider"></div>
                                <div class="wpc-tooltip-footer">
                                    <div class="wpc-tooltip-saved">
                                        <span class="wpc-saved-icon">🎉</span>
                                        <span class="wpc-saved-label">Saved</span>
                                        <span class="wpc-saved-value">${savedStr}</span>
                                        <span class="wpc-saved-percent">(${percent}%)</span>
                                    </div>
                                </div>
                            </div>
                        `;

                        tooltipEl.innerHTML = innerHtml;

                        // Position tooltip
                        const position = context.chart.canvas.getBoundingClientRect();
                        const tooltipWidth = 240;
                        const tooltipHeight = 180;

                        let left = position.left + window.pageXOffset + tooltipModel.caretX - (tooltipWidth / 2);
                        let top = position.top + window.pageYOffset + tooltipModel.caretY - tooltipHeight - 20;

                        // Keep tooltip in viewport
                        if (left < 10) left = 10;
                        if (left + tooltipWidth > window.innerWidth - 10) {
                            left = window.innerWidth - tooltipWidth - 10;
                        }
                        if (top < 10) {
                            top = position.top + window.pageYOffset + tooltipModel.caretY + 20;
                            tooltipEl.style.transformOrigin = 'top center';
                        } else {
                            tooltipEl.style.transformOrigin = 'bottom center';
                        }

                        // Show with smooth fade in
                        tooltipEl.style.opacity = 1;
                        tooltipEl.style.transform = 'translateY(0) scale(1)';
                        tooltipEl.style.left = left + 'px';
                        tooltipEl.style.top = top + 'px';
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    border: {
                        display: false
                    },
                    ticks: {
                        color: '#8393a9',
                        font: {
                            size: 12,
                            weight: '600',
                            family: '"proxima_regular", "Proxima Nova", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif'
                        },
                        padding: 12,
                        autoSkip: true,
                        maxRotation: 0,
                        minRotation: 0
                    }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    grid: {
                        color: function(context) {
                            if (context.tick.value === 0) {
                                return 'rgba(0, 0, 0, 0)';
                            }
                            return 'rgba(226, 232, 240, 0.4)';
                        },
                        drawBorder: false,
                        lineWidth: 1,
                        borderDash: [4, 4]
                    },
                    border: {
                        display: false,
                        dash: [4, 4]
                    },
                    ticks: {
                        color: '#8393a9',
                        font: {
                            size: 12,
                            weight: '600',
                            family: '"proxima_regular", "Proxima Nova", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif'
                        },
                        padding: 14,
                        callback: function(value) {
                            if (value >= 1000) {
                                return (value / 1000).toFixed(2) + ' GB';
                            }
                            return value + ' MB';
                        }
                    }
                }
            }
        },
        plugins: [{
            id: 'hoverEffect',
            afterDatasetsDraw: function(chart) {
                const ctx = chart.ctx;
                const activeElements = chart.getActiveElements();

                if (activeElements.length > 0) {
                    activeElements.forEach(element => {
                        const {x, y, width, height} = element;

                        ctx.save();
                        ctx.shadowColor = 'rgba(60, 76, 223, 0.25)';
                        ctx.shadowBlur = 12;
                        ctx.shadowOffsetX = 0;
                        ctx.shadowOffsetY = 3;
                        ctx.restore();
                    });
                }
            }
        }]
    };

    <?php } ?>
</script>



<script type="text/javascript">
    jQuery(document).ready(function ($) {
        <?php if (!empty($labels) && !empty($stats)) { ?>
        setTimeout(function () {
            if ($('#wpc-canvas').length) {
                var ctx = document.getElementById("wpc-canvas").getContext("2d");
                window.myLine = new Chartwpc(ctx, config);

                // Optional: Log data source in console for debugging
                if (window.console && console.log) {
                    console.log('WP Compress Chart - Data Source: ' + dataSource);
                }
            }
        }, 200);
        <?php } ?>
    });
</script>

<style>
    /* ============================================================
       PREMIUM ENTERPRISE TOOLTIP - IMPROVED VERSION
       ============================================================ */

    /* Premium font loading */
    @import url('https://use.typekit.net/ixj5hkr.css');

    /* Data source notice */
    .wpc-data-notice {
        display: flex;
        align-items: center;
        gap: 8px;
        animation: slideInNotice 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes slideInNotice {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Enterprise-grade custom tooltip with subtle gradient */
    #wpc-chartjs-tooltip {
        background: linear-gradient(145deg, #ffffff 0%, #fafbfc 100%);
        border: 1px solid rgba(226, 232, 240, 0.5);
        border-radius: 14px;
        padding: 0;
        font-family: 'Proxima Nova', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        /* Softer, more refined shadow */
        box-shadow:
                0 12px 28px rgba(15, 23, 42, 0.08),
                0 4px 12px rgba(15, 23, 42, 0.04),
                0 0 0 1px rgba(15, 23, 42, 0.02);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        /* Smooth transitions */
        transition: opacity 0.25s cubic-bezier(0.4, 0, 0.2, 1),
        transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        /* Initial state for animation */
        opacity: 0;
        transform: translateY(-8px) scale(0.95);
    }

    .wpc-tooltip-container {
        position: relative;
        width: 240px;
    }

    /* Tooltip header with subtle gradient */
    .wpc-tooltip-header {
        padding: 14px 16px 12px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.5);
        background: linear-gradient(180deg, rgba(249, 250, 251, 0.6) 0%, rgba(255, 255, 255, 0) 100%);
        border-radius: 14px 14px 0 0;
    }

    .wpc-tooltip-date {
        font-family: 'proxima_bold', sans-serif !important;
        font-size: 12px !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.05em !important;
        color: #1e293b !important;
        margin: 0 !important;
    }

    /* Tooltip body with metrics */
    .wpc-tooltip-body {
        padding: 12px 16px;
        background: rgba(255, 255, 255, 0.5);
    }

    .wpc-tooltip-row {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
    }

    .wpc-tooltip-row:last-child {
        margin-bottom: 0;
    }

    /* Premium rounded square badges with subtle glow */
    .wpc-tooltip-badge {
        width: 10px;
        height: 10px;
        border-radius: 3px;
        flex-shrink: 0;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .wpc-tooltip-row:hover .wpc-tooltip-badge {
        transform: scale(1.1);
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    }

    .wpc-badge-dark {
        background: linear-gradient(135deg, #2d3ba8 0%, #3c4cdf 50%, #4e5ef0 100%);
    }

    .wpc-badge-light {
        background: linear-gradient(135deg, #42a5f5 0%, #64b5f6 50%, #81c9fa 100%);
    }

    /* Content layout */
    .wpc-tooltip-content {
        display: flex;
        align-items: baseline;
        gap: 6px;
        flex: 1;
        min-width: 0;
    }

    .wpc-tooltip-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #64748b;
        white-space: nowrap;
    }

    .wpc-tooltip-value {
        font-size: 12px;
        font-weight: 600;
        color: #1e293b;
        margin-left: auto;
        font-feature-settings: 'tnum';
        letter-spacing: -0.01em;
    }

    /* Softer divider */
    .wpc-tooltip-divider {
        height: 1px;
        background: linear-gradient(90deg,
        rgba(226, 232, 240, 0) 0%,
        rgba(226, 232, 240, 0.6) 20%,
        rgba(226, 232, 240, 0.6) 80%,
        rgba(226, 232, 240, 0) 100%
        );
        margin: 0 16px;
    }

    /* Footer with savings highlight */
    .wpc-tooltip-footer {
        padding: 12px 16px 14px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, rgba(249, 250, 251, 0.6) 100%);
        border-radius: 0 0 14px 14px;
    }

    .wpc-tooltip-saved {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .wpc-saved-icon {
        font-size: 14px;
        line-height: 1;
    }

    .wpc-saved-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #64748b;
        white-space: nowrap;
    }

    .wpc-saved-value {
        font-size: 12px;
        font-weight: 800;
        color: #1e293b;
        margin-left: auto;
        font-feature-settings: 'tnum';
        letter-spacing: -0.01em;
    }

    .wpc-saved-percent {
        font-size: 11px;
        font-weight: 700;
        color: #64748b;
        font-feature-settings: 'tnum';
    }

    /* Chart canvas */
    #wpc-canvas {
        filter: drop-shadow(0 1px 4px rgba(60, 76, 223, 0.06));
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    #wpc-canvas:hover {
        filter: drop-shadow(0 3px 8px rgba(60, 76, 223, 0.1));
    }

    /* Chart container */
    .wp-compress-chart {
        background: #ffffff;
        border-radius: 8px;
        padding: 3px 10px 2px;
        box-shadow:
                0 1px 3px rgba(0, 0, 0, 0.04),
                0 0 0 1px rgba(0, 0, 0, 0.02);
        position: relative;
        overflow: hidden;
    }

    .wp-compress-chart::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
        transition: left 0.5s ease-in-out;
    }

    .wp-compress-chart:hover::before {
        left: 100%;
    }

    /* Fade-in animation */
    .wp-compress-pre-wrapper-v4 {
        animation: fadeInChart 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes fadeInChart {
        from {
            opacity: 0;
            transform: translateY(20px) scale(0.98);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    /* Legend styling */
    .wp-compress-pre-subheader {
        margin-bottom: 24px;
        font-family: 'Proxima Nova', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    .wp-compress-pre-subheader .legend-original,
    .wp-compress-pre-subheader .legend-after {
        width: 10px !important;
        height: 10px !important;
        border-radius: 3px !important;
        margin-right: 10px !important;
        display: inline-block !important;
        transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94) !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .wp-compress-pre-subheader li:hover .legend-original,
    .wp-compress-pre-subheader li:hover .legend-after {
        transform: scale(1.2);
    }

    .wp-compress-pre-subheader .legend-after {
        background: linear-gradient(135deg, #2d3ba8 0%, #3c4cdf 50%, #4e5ef0 100%);
    }

    .wp-compress-pre-subheader .legend-original {
        background: linear-gradient(135deg, #42a5f5 0%, #64b5f6 50%, #81c9fa 100%);
    }

    .wp-compress-pre-subheader h3 {
        font-family: 'Proxima Nova', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        font-weight: 600;
        letter-spacing: -0.01em;
    }

    .wp-compress-pre-subheader .col-6.last ul li {
        font-family: 'proxima_bold', 'Proxima Nova', sans-serif !important;
        font-size: 11px !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.05em !important;
        color: #64748b !important;
        cursor: pointer !important;
        display: inline-flex !important;
        align-items: center !important;
        margin-left: 24px !important;
        padding: 4px 0 !important;
        transition: color 0.2s ease;
    }

    .wp-compress-pre-subheader .col-6.last ul li:hover {
        color: #475569 !important;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .wp-compress-chart {
            padding: 16px;
            border-radius: 12px;
        }

        .wp-compress-pre-subheader .col-6.last ul li {
            margin-left: 16px !important;
        }

        #wpc-chartjs-tooltip {
            width: 220px;
        }

        .wpc-tooltip-container {
            width: 220px;
        }
    }
</style>