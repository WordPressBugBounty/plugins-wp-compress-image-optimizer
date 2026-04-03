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

    // Normalize: API returns {success, data: {...}} but sample returns just the data object
    if ($stats && isset($stats->data) && is_object($stats->data)) {
        $stats = $stats->data;
    }

    // If stats is still empty or has no date entries, fall back to sample data
    if (empty($stats) || (is_object($stats) && count((array)$stats) === 0)) {
        $statsclass = new wps_ic_stats();
        $stats = $statsclass->fetch_sample_stats();
    }

    if ($stats) {
        $has_nonzero = false;
        foreach ($stats as $date => $value) {
            if (!is_object($value) || !isset($value->original)) continue;
            if ($value->original > 0 || $value->compressed > 0) $has_nonzero = true;
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

        // All values are zero — show sample data instead of empty chart
        if (!$has_nonzero) {
            $labels = [];
        }
    }

    // Final safety net — if labels is still empty, always show sample data
    if (empty($labels)) {
        $statsclass = new wps_ic_stats();
        $stats = $statsclass->fetch_sample_stats();
        if ($stats) {
            foreach ($stats as $date => $value) {
                if (!is_object($value) || !isset($value->original)) continue;
                $index = date('d-m-Y', strtotime($date));
                $labels[$index]['date'] = date('m/d/Y', strtotime($date));
                $labels[$index]['total_input'] = $value->original;
                $labels[$index]['total_output'] = $value->compressed;
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

    var labelBottom = "<?php echo esc_js(__('After Optimization', WPS_IC_TEXTDOMAIN)); ?>";
    var labelTop    = "<?php echo esc_js(__('Savings', WPS_IC_TEXTDOMAIN)); ?>";
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
    labelBottom = "<?php echo esc_js(__('Cached Traffic', WPS_IC_TEXTDOMAIN)); ?>";
    labelTop = "<?php echo esc_js(__('Non-Cached', WPS_IC_TEXTDOMAIN)); ?>";
    isCloudflareMode = true;
    <?php } ?>

    // ============================================================
    // BRAND-AWARE CHART COLOR HELPERS
    // ============================================================
    // Read brand color from CSS custom property (set by whitelabel ZIP)
    // Ignore default plugin blue (#3b82f6) — only override for custom brand colors
    var wpcBrandRaw = getComputedStyle(document.documentElement).getPropertyValue('--wpc-brand-primary').trim();
    if (wpcBrandRaw === '#3b82f6') wpcBrandRaw = '';

    // Convert hex to RGB components
    function hexToRgb(hex) {
        hex = hex.replace('#', '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        var n = parseInt(hex, 16);
        return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
    }
    // Shade a hex color: amount > 0 = lighter, < 0 = darker
    function shadeColor(hex, amount) {
        var c = hexToRgb(hex);
        var t = amount > 0 ? 255 : 0;
        var p = Math.abs(amount);
        return 'rgb(' +
            Math.round((t - c.r) * p + c.r) + ',' +
            Math.round((t - c.g) * p + c.g) + ',' +
            Math.round((t - c.b) * p + c.b) + ')';
    }

    // Dark palette (cached/optimized bars) — brand or solid #2e3caa
    var chartDarkBase  = wpcBrandRaw ? wpcBrandRaw : '#2e3caa';
    var chartDarkLight = wpcBrandRaw ? shadeColor(wpcBrandRaw, -0.15) : '#2e3caa';
    var chartDarkMid   = wpcBrandRaw ? wpcBrandRaw : '#2e3caa';
    var chartDarkBright= wpcBrandRaw ? shadeColor(wpcBrandRaw, 0.10) : '#2e3caa';
    var chartDarkHover1= wpcBrandRaw ? shadeColor(wpcBrandRaw, -0.25) : '#232e88';
    var chartDarkHover2= wpcBrandRaw ? shadeColor(wpcBrandRaw, -0.05) : '#232e88';

    // Light palette (non-cached/original bars) — brand tint or solid #51acf6
    var chartLightBase = wpcBrandRaw ? shadeColor(wpcBrandRaw, 0.45) : '#51acf6';
    var chartLight1    = wpcBrandRaw ? shadeColor(wpcBrandRaw, 0.40) : '#51acf6';
    var chartLightMid  = wpcBrandRaw ? shadeColor(wpcBrandRaw, 0.45) : '#51acf6';
    var chartLightBright=wpcBrandRaw ? shadeColor(wpcBrandRaw, 0.50) : '#51acf6';
    var chartLightHov1 = wpcBrandRaw ? shadeColor(wpcBrandRaw, 0.35) : '#3d9be8';
    var chartLightHov2 = wpcBrandRaw ? shadeColor(wpcBrandRaw, 0.45) : '#3d9be8';

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
                            return chartDarkBase;
                        }
                        const ctx = context.chart.ctx;
                        const gradient = ctx.createLinearGradient(0, context.chart.chartArea.bottom, 0, context.chart.chartArea.top);
                        gradient.addColorStop(0, chartDarkLight);
                        gradient.addColorStop(0.5, chartDarkMid);
                        gradient.addColorStop(1, chartDarkBright);
                        return gradient;
                    },
                    hoverBackgroundColor: function(context) {
                        const ctx = context.chart.ctx;
                        const gradient = ctx.createLinearGradient(0, context.chart.chartArea.bottom, 0, context.chart.chartArea.top);
                        gradient.addColorStop(0, chartDarkHover1);
                        gradient.addColorStop(1, chartDarkHover2);
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
                            return chartLightBase;
                        }
                        const ctx = context.chart.ctx;
                        const gradient = ctx.createLinearGradient(0, context.chart.chartArea.bottom, 0, context.chart.chartArea.top);
                        gradient.addColorStop(0, chartLight1);
                        gradient.addColorStop(0.5, chartLightMid);
                        gradient.addColorStop(1, chartLightBright);
                        return gradient;
                    },
                    hoverBackgroundColor: function(context) {
                        const ctx = context.chart.ctx;
                        const gradient = ctx.createLinearGradient(0, context.chart.chartArea.bottom, 0, context.chart.chartArea.top);
                        gradient.addColorStop(0, chartLightHov1);
                        gradient.addColorStop(1, chartLightHov2);
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
                    // Stagger all render modes EXCEPT hover — hover must be instant
                    if (context.type === 'data' && context.mode !== 'active') {
                        return context.dataIndex * 80 + context.datasetIndex * 40;
                    }
                    return 0;
                }
            },
            animations: {
                y: {
                    duration: 900,
                    easing: 'easeInOutCubic',
                    from: function(context) {
                        // Always grow bars from the x-axis baseline
                        if (context.chart && context.chart.scales && context.chart.scales.y) {
                            return context.chart.scales.y.getPixelForValue(0);
                        }
                        return 0;
                    }
                }
            },
            transitions: {
                resize: { animation: { duration: 0 } },
                show:   { animation: { duration: 0 } },
                hide:   { animation: { duration: 0 } }
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

                        const originalLabel = isCloudflareMode ? '<?php echo esc_js(__('Total Traffic', WPS_IC_TEXTDOMAIN)); ?>' : '<?php echo esc_js(__('Original', WPS_IC_TEXTDOMAIN)); ?>';
                        const afterLabel = isCloudflareMode ? '<?php echo esc_js(__('Cached Traffic', WPS_IC_TEXTDOMAIN)); ?>' : '<?php echo esc_js(__('After Optimization', WPS_IC_TEXTDOMAIN)); ?>';

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
                                        <span class="wpc-saved-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="14" height="14" style="vertical-align:middle;fill:currentColor"><path d="M32 32a32 32 0 1 1 64 0 32 32 0 1 1 -64 0zM448 160a32 32 0 1 1 64 0 32 32 0 1 1 -64 0zm32 256a32 32 0 1 1 0 64 32 32 0 1 1 0-64zM167 153c-9.4-9.4-9.4-24.6 0-33.9l8.3-8.3c16.7-16.7 27.2-38.6 29.8-62.1l3-27.4C209.6 8.2 221.5-1.3 234.7 .1s22.7 13.3 21.2 26.5l-3 27.4c-3.8 34.3-19.2 66.3-43.6 90.7L201 153c-9.4 9.4-24.6 9.4-33.9 0zM359 311l8.2-8.2c24.4-24.4 56.4-39.8 90.7-43.6l27.4-3c13.2-1.5 25 8 26.5 21.2s-8 25-21.2 26.5l-27.4 3c-23.5 2.6-45.4 13.1-62.1 29.8L393 345c-9.4 9.4-24.6 9.4-33.9 0s-9.4-24.6 0-33.9zM506.3 8.5c8.6 10.1 7.3 25.3-2.8 33.8l-10 8.5c-14.8 12.5-33.7 19.1-53 18.6-16.6-.4-30.6 12.4-31.6 29l-1.8 30c-2.5 42.5-38.3 75.3-80.8 74.2-7.6-.2-15 2.4-20.7 7.3l-10 8.5c-10.1 8.6-25.3 7.3-33.8-2.8s-7.3-25.3 2.8-33.8l10-8.5c14.8-12.5 33.7-19.1 53-18.6 16.6 .4 30.6-12.4 31.6-29l1.8-30c2.5-42.5 38.3-75.3 80.8-74.2 7.6 .2 15-2.4 20.7-7.3l10-8.5c10.1-8.6 25.3-7.3 33.8 2.8zM150.6 201.4l160 160c7.7 7.7 11 18.8 8.6 29.4s-9.9 19.4-20 23.2L259.5 428.9 83.1 252.5 98 212.8c3.8-10.2 12.6-17.7 23.2-20s21.7 1 29.4 8.6zM48.2 345.6l22.6-60.2 155.8 155.8-60.2 22.6-118.2-118.2zM35.9 378.5l97.6 97.6-90.3 33.8c-11.7 4.4-25 1.5-33.9-7.3S-2.4 480.5 2 468.8l33.8-90.3z"/></svg></span>
                                        <span class="wpc-saved-label"><?php echo esc_js(__('Saved', WPS_IC_TEXTDOMAIN)); ?></span>
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
                        display: window.innerWidth > 480,
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
        // Defer chart creation until dashboard tab is visible
        // Prevents bars animating from left when initialized while canvas is hidden
        window.wpcInitChart = function(forceRecreate) {
            var $canvas = $('#wpc-canvas');
            if (!$canvas.length) return;
            // On advanced settings, defer until the tab is visible; on lite/simple, canvas is always visible
            var $tab = $canvas.closest('.wpc-tab-content');
            if ($tab.length && !$tab.is(':visible')) return;

            // Destroy existing chart if forcing recreate (tab switch scenario)
            if (forceRecreate && window.myLine) {
                window.myLine.destroy();
                window.myLine = null;
            }

            if (!window.myLine) {
                var ctx = document.getElementById("wpc-canvas").getContext("2d");
                window.myLine = new Chartwpc(ctx, config);
            }
        };

        setTimeout(function() {
            window.wpcInitChart();
        }, 200);
        <?php } ?>
    });
</script>

<style>
    /* ============================================================
       PREMIUM ENTERPRISE TOOLTIP - IMPROVED VERSION
       ============================================================ */

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
        background: var(--wpc-brand-primary, linear-gradient(135deg, #2d3ba8 0%, #3c4cdf 50%, #4e5ef0 100%));
    }

    .wpc-badge-light {
        background: var(--wpc-brand-primary-light, linear-gradient(135deg, #42a5f5 0%, #64b5f6 50%, #81c9fa 100%));
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
        background: var(--wpc-brand-primary, linear-gradient(135deg, #222d80 0%, #2e3caa 50%, #4a56be 100%));
    }

    .wp-compress-pre-subheader .legend-original {
        background: var(--wpc-brand-primary-light, linear-gradient(135deg, #3d9be8 0%, #51acf6 50%, #74c0f9 100%));
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