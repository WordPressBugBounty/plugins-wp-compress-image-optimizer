<div class="wp-compress-pre-wrapper-v4">
    <div class="wp-compress-pre-subheader">
        <div class="col-6">
            <ul>
                <li>
                    <h3><?php echo esc_html__('Compression Report', WPS_IC_TEXTDOMAIN); ?> <span class="wpc-sample-badge" style="display:none;"><?php echo esc_html__('SAMPLE DATA', WPS_IC_TEXTDOMAIN); ?><span class="wpc-sample-tooltip"><?php echo esc_html__('Currently showing sample data, live stats will appear automatically within 1–2 hours.', WPS_IC_TEXTDOMAIN); ?></span></span></h3>
                </li>
            </ul>
        </div>
        <div class="col-6 last">
            <ul>
                <li><span class="legend-original"></span><?php echo esc_html__('Original Size', WPS_IC_TEXTDOMAIN); ?></li>
                <li><span class="legend-after"></span><?php echo esc_html__('After Optimization', WPS_IC_TEXTDOMAIN); ?></li>
            </ul>
        </div>
    </div>

    <div class="wp-compress-chart" style="height: 400px;">
     <canvas id="wpc-canvas"></canvas>
    </div>

</div>