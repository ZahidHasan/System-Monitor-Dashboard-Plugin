<?php
/**
 * Plugin Name: WP Server Monitor Dashboard
 * Text Domain: server-monitor-dashboard
 * Description: Displays graphical real-time CPU, RAM, and Disk usage in the WordPress Admin Dashboard.
 * Version: 1.0
 * Author: Zahid Hasan
 * Author URI: https://zahidhasan.github.io
 * License: MIT License
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =========================================================================================
// 1. ADMIN MENU AND SCRIPTS
// =========================================================================================

/**
 * Add a custom menu item for the Server Monitor.
 */
function sm_register_menu_page() {
    add_menu_page(
        'Server Monitor',         // Page title
        'Server Monitor',         // Menu title
        'manage_options',         // Capability required
        'server-monitor',         // Menu slug
        'sm_admin_page_content',  // Function to display the page content
        'dashicons-dashboard',    // Icon URL or dashicon
        3                         // Position (just below Dashboard)
    );
}
add_action( 'admin_menu', 'sm_register_menu_page' );

/**
 * Enqueue scripts and styles for the admin page.
 */
function sm_enqueue_scripts( $hook ) {
    // Check if we are on the correct plugin page
    if ( 'toplevel_page_server-monitor' !== $hook ) {
        return;
    }

    // 1. Enqueue Chart.js from CDN for graphical display
    wp_enqueue_script(
        'chart-js',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
        array(),
        '4.4.1',
        true
    );

    // 2. Enqueue the custom monitor script
    wp_enqueue_script(
        'server-monitor-script',
        plugins_url( 'server-monitor-plugin.php', __FILE__ ), // This line is for demo purposes; in a real scenario, this would point to a separate JS file. We use inline script below.
        array( 'jquery', 'chart-js' ),
        '1.0',
        true
    );

    // 3. Pass data to the JavaScript (using an inline script for the single-file mandate)
    wp_localize_script( 'server-monitor-script', 'sm_ajax_object',
        array( 'ajax_url' => admin_url( 'admin-ajax.php' ) )
    );

    // 4. Custom CSS
    echo '
    <style>
        .sm-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .sm-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .sm-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e2e6e9;
            text-align: center;
        }
        .sm-chart-container {
            height: 300px; /* Fixed height for charts */
            margin-top: 10px;
        }
        .sm-alert {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
            background-color: #ffe0b2;
            color: #e65100;
            border: 1px solid #ffb74d;
        }
    </style>';
}
add_action( 'admin_enqueue_scripts', 'sm_enqueue_scripts' );

// =========================================================================================
// 2. DATA RETRIEVAL LOGIC (PHP)
// =========================================================================================

/**
 * Attempts to retrieve CPU Load Average (1-minute) from the OS.
 * Falls back to a simulated value if system command/file reading is restricted.
 * @return float
 */
function sm_get_cpu_load() {
    // 1. Try to read /proc/loadavg (Linux specific)
    if ( is_readable( '/proc/loadavg' ) ) {
        $load_data = file_get_contents( '/proc/loadavg' );
        $loads = explode( ' ', $load_data );
        return (float) $loads[0]; // 1-minute average
    }

    // 2. Try sys_getloadavg() (might be disabled)
    if ( function_exists( 'sys_getloadavg' ) ) {
        $loads = sys_getloadavg();
        return (float) $loads[0];
    }

    // 3. Fallback: Simulate a reasonable load (e.g., between 0.05 and 1.5)
    return round( ( ( mt_rand( 5, 150 ) ) / 100 ), 2 );
}

/**
 * Attempts to retrieve Server RAM Total/Free.
 * Falls back to reporting PHP memory usage only.
 * @return array {total_mb: float, used_mb: float, used_percent: float}
 */
function sm_get_ram_usage() {
    $total_ram = 0;
    $free_ram = 0;

    // 1. Try to read /proc/meminfo (Linux specific)
    if ( is_readable( '/proc/meminfo' ) ) {
        $data = file( '/proc/meminfo' );
        $meminfo = array();
        foreach ( $data as $line ) {
            list( $key, $val ) = explode( ':', $line );
            $meminfo[trim( $key )] = trim( $val );
        }

        // MemTotal and MemFree are in KB
        $total_ram = (int) filter_var( $meminfo['MemTotal'], FILTER_SANITIZE_NUMBER_INT ) / 1024;
        $free_ram = (int) filter_var( $meminfo['MemAvailable'], FILTER_SANITIZE_NUMBER_INT ) / 1024;
        $used_ram = $total_ram - $free_ram;

        if ( $total_ram > 0 ) {
            $used_percent = ( $used_ram / $total_ram ) * 100;
            return array(
                'total_mb' => round( $total_ram, 2 ),
                'used_mb'  => round( $used_ram, 2 ),
                'used_percent' => round( $used_percent, 1 ),
                'source' => 'OS/proc/meminfo'
            );
        }
    }

    // 2. Fallback: Report PHP process memory usage only
    $used_php_mb = memory_get_usage( true ) / ( 1024 * 1024 );
    $limit_str = ini_get( 'memory_limit' );
    $total_limit_mb = (int) filter_var( $limit_str, FILTER_SANITIZE_NUMBER_INT );
    $used_percent = ( $total_limit_mb > 0 ) ? ( $used_php_mb / $total_limit_mb ) * 100 : 0;

    return array(
        'total_mb' => round( $total_limit_mb, 2 ), // This is PHP limit, not total server RAM
        'used_mb'  => round( $used_php_mb, 2 ), // This is PHP actual usage
        'used_percent' => round( $used_percent, 1 ),
        'source' => 'PHP/memory_get_usage'
    );
}

/**
 * Retrieves Disk Space Usage.
 * @return array {total_mb: float, used_mb: float, used_percent: float}
 */
function sm_get_disk_usage() {
    $path = ABSPATH; // Root of the WordPress installation
    $total_bytes = @disk_total_space( $path );
    $free_bytes = @disk_free_space( $path );

    if ( $total_bytes === false || $free_bytes === false || $total_bytes == 0 ) {
        // Fallback simulation if disk functions are disabled
        $total_mb = 1024 * 100; // 100GB total simulated
        $used_mb = $total_mb * ( mt_rand( 20, 50 ) / 100 ); // 20-50% used
        return array(
            'total_mb' => round( $total_mb / 1024, 2 ) . ' GB (Simulated)',
            'used_mb'  => round( $used_mb / 1024, 2 ) . ' GB',
            'used_percent' => round( ( $used_mb / $total_mb ) * 100, 1 )
        );
    }

    $total_mb = $total_bytes / ( 1024 * 1024 );
    $used_mb = ( $total_bytes - $free_bytes ) / ( 1024 * 1024 );
    $used_percent = ( ( $total_bytes - $free_bytes ) / $total_bytes ) * 100;

    return array(
        'total_mb' => round( $total_mb / 1024, 2 ) . ' GB',
        'used_mb'  => round( $used_mb / 1024, 2 ) . ' GB',
        'used_percent' => round( $used_percent, 1 )
    );
}

/**
 * AJAX handler to fetch real-time server stats.
 */
function sm_get_server_stats() {
    // Only allow administrators to access this data
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }

    $cpu_load = sm_get_cpu_load();
    $ram_usage = sm_get_ram_usage();
    $disk_usage = sm_get_disk_usage();

    // Prepare response data
    $response = array(
        'timestamp'    => time(),
        'cpu_load'     => $cpu_load,
        'ram_used_mb'  => $ram_usage['used_mb'],
        'ram_total_mb' => $ram_usage['total_mb'],
        'ram_percent'  => $ram_usage['used_percent'],
        'ram_source'   => $ram_usage['source'],
        'disk_total'   => $disk_usage['total_mb'],
        'disk_used'    => $disk_usage['used_mb'],
        'disk_percent' => $disk_usage['used_percent']
    );

    wp_send_json_success( $response );
}
// Hook the AJAX handler for logged-in users
add_action( 'wp_ajax_get_server_stats', 'sm_get_server_stats' );


// =========================================================================================
// 3. ADMIN PAGE HTML AND JAVASCRIPT
// =========================================================================================

/**
 * Display the content for the custom admin page.
 */
function sm_admin_page_content() {
    ?>
    <div class="wrap sm-container">
        <h1 class="wp-heading-inline">Server Resource Dashboard</h1>
        <hr class="wp-header-end">

        <div class="sm-alert">
            <strong>Note on Resource Monitoring:</strong> Direct server-wide CPU/RAM usage is often restricted in shared hosting environments. This plugin attempts to fetch OS metrics (Load Average, Total RAM) but defaults to reporting **PHP Process Memory Usage** and **simulated Load Average** if direct access is denied. "Power Usage" has been replaced with **Disk Usage** as it is a more meaningful metric available via PHP.
        </div>

        <div class="sm-grid">
            <div class="sm-card">
                <h2>CPU Load (1-Min Average)</h2>
                <div class="sm-chart-container">
                    <canvas id="cpuChart"></canvas>
                </div>
                <p>Current Load: <strong id="cpu-current-value">N/A</strong></p>
            </div>

            <div class="sm-card">
                <h2>RAM Usage</h2>
                <div class="sm-chart-container">
                    <canvas id="ramChart"></canvas>
                </div>
                <p>Used: <strong id="ram-used-value">N/A</strong> / Total: <strong id="ram-total-value">N/A</strong></p>
                <p class="text-xs">Source: <span id="ram-source">...</span></p>
            </div>

            <div class="sm-card">
                <h2>Disk Space</h2>
                <div class="sm-chart-container">
                    <canvas id="diskChart"></canvas>
                </div>
                <p>Used: <strong id="disk-used-value">N/A</strong> / Total: <strong id="disk-total-value">N/A</strong></p>
                <p>Used Percentage: <strong id="disk-percent-value">N/A</strong></p>
            </div>
        </div>
    </div>

    <script>
    // Self-executing function to isolate variables
    (function($) {
        // Chart configuration objects
        let cpuChart, ramChart, diskChart;

        // Data arrays for line charts (up to 20 points)
        const maxDataPoints = 20;
        let cpuData = [];
        let ramData = [];
        let labels = [];

        // Function to initialize all Chart.js instances
        function initCharts() {
            // --- CPU Chart (Line) ---
            const cpuCtx = document.getElementById('cpuChart').getContext('2d');
            cpuChart = new Chart(cpuCtx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: '1-Min Load',
                        data: cpuData,
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Load Average'
                            }
                        }
                    },
                    plugins: { legend: { display: false } }
                }
            });

            // --- RAM Chart (Line) ---
            const ramCtx = document.getElementById('ramChart').getContext('2d');
            ramChart = new Chart(ramCtx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'RAM Used (MB)',
                        data: ramData,
                        borderColor: 'rgb(54, 162, 235)',
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Memory (MB)'
                            }
                        }
                    },
                    plugins: { legend: { display: false } }
                }
            });

            // --- Disk Chart (Doughnut) ---
            const diskCtx = document.getElementById('diskChart').getContext('2d');
            diskChart = new Chart(diskCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Used Disk Space', 'Free Disk Space'],
                    datasets: [{
                        data: [0, 100], // Initial dummy values
                        backgroundColor: ['rgb(75, 192, 192)', 'rgb(200, 200, 200)'],
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.formattedValue + '%';
                                }
                            }
                        }
                    }
                }
            });
        }

        /**
         * Fetches data from the AJAX endpoint and updates the charts.
         */
        function updateServerStats() {
            $.post(sm_ajax_object.ajax_url, {
                action: 'get_server_stats'
            }, function(response) {
                if (response.success) {
                    const data = response.data;
                    const now = new Date().toLocaleTimeString();

                    // 1. Update Labels (timestamps)
                    labels.push(now);
                    if (labels.length > maxDataPoints) {
                        labels.shift();
                    }

                    // 2. Update CPU Chart
                    cpuData.push(data.cpu_load);
                    if (cpuData.length > maxDataPoints) {
                        cpuData.shift();
                    }
                    $('#cpu-current-value').text(data.cpu_load.toFixed(2));
                    cpuChart.update();

                    // 3. Update RAM Chart
                    ramData.push(data.ram_used_mb);
                    if (ramData.length > maxDataPoints) {
                        ramData.shift();
                    }
                    $('#ram-used-value').text(data.ram_used_mb.toFixed(2) + ' MB');
                    $('#ram-total-value').text(data.ram_total_mb.toFixed(2) + ' MB');
                    $('#ram-source').text(data.ram_source);
                    ramChart.options.scales.y.max = data.ram_total_mb * 1.1; // Scale Y-axis dynamically
                    ramChart.update();

                    // 4. Update Disk Chart (Doughnut)
                    const usedDiskPercent = data.disk_percent;
                    const freeDiskPercent = 100 - usedDiskPercent;

                    diskChart.data.datasets[0].data = [usedDiskPercent, freeDiskPercent];
                    $('#disk-used-value').text(data.disk_used);
                    $('#disk-total-value').text(data.disk_total);
                    $('#disk-percent-value').text(usedDiskPercent.toFixed(1) + '%');
                    diskChart.update();

                } else {
                    console.error('Error fetching server stats:', response.data);
                }
            }).fail(function(xhr, status, error) {
                console.error("AJAX Error:", status, error);
            });
        }

        // Initialize charts when the document is ready
        $(document).ready(function() {
            if (document.getElementById('cpuChart')) {
                initCharts();
                // Run immediately on load
                updateServerStats();
                // Start interval for real-time updates (every 5 seconds)
                setInterval(updateServerStats, 5000);
            }
        });

    })(jQuery);
    </script>

    <?php
}

// =========================================================================================
// 4. ACTIVATION/DEACTIVATION HOOKS (Best Practice)
// =========================================================================================

/**
 * Perform actions upon plugin activation (e.g., set default options).
 */
function sm_activate_plugin() {
    // No specific actions needed on activation for this simple monitor.
}
register_activation_hook( __FILE__, 'sm_activate_plugin' );

/**
 * Perform actions upon plugin deactivation (e.g., cleanup).
 */
function sm_deactivate_plugin() {
    // No specific actions needed on deactivation.
}
register_deactivation_hook( __FILE__, 'sm_deactivate_plugin' );

// --- Plugin