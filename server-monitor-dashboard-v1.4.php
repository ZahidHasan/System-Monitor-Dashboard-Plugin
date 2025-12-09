<?php
/**
 * Plugin Name: Server Monitor Dashboard
 * Description: Live CPU, RAM, Disk, Network, and Process charts in a top-level menu below Dashboard.
 * Version: 1.4
 * Author: Zahid Hasan
 * Author URI: https://zahidhasan.github.io
 * License: MIT License
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Server_Monitor_Dashboard')) {

class Server_Monitor_Dashboard {

    private static $instance = null;
    private $option_name = 'smd_options';
    private $defaults = [
        'refresh_interval' => 2,          // seconds
        'use_real_metrics' => 0,          // 0 = simulate, 1 = try real
        'alert_cpu' => 80,
        'alert_ram' => 80,
        'alert_disk' => 90,
        'cache_ttl' => 2
    ];

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_smd_get_stats', [$this, 'ajax_get_stats']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function register_menu() {
        add_menu_page(
            'Server Monitor',
            'Server Monitor',
            'manage_options',
            'server-monitor-dashboard',
            [$this, 'render_page'],
            'dashicons-chart-line',
            3
        );

        add_submenu_page(
            'server-monitor-dashboard',
            'Server Monitor Settings',
            'Settings',
            'manage_options',
            'server-monitor-dashboard-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting($this->option_name, $this->option_name, [$this, 'validate_options']);
        add_settings_section('smd_main', 'Main Settings', null, $this->option_name);

        add_settings_field('refresh_interval', 'Refresh Interval (seconds)', [$this, 'field_refresh_interval'], $this->option_name, 'smd_main');
        add_settings_field('use_real_metrics', 'Use real system metrics (when available)', [$this, 'field_use_real_metrics'], $this->option_name, 'smd_main');
        add_settings_field('alert_thresholds', 'Alert thresholds (%)', [$this, 'field_alert_thresholds'], $this->option_name, 'smd_main');
    }

    public function validate_options($input) {
        $valid = wp_parse_args(get_option($this->option_name, []), $this->defaults);
        $valid['refresh_interval'] = max(1, (int)($input['refresh_interval'] ?? $valid['refresh_interval']));
        $valid['use_real_metrics'] = !empty($input['use_real_metrics']) ? 1 : 0;
        $valid['alert_cpu'] = min(100, max(1, (int)($input['alert_cpu'] ?? $valid['alert_cpu'])));
        $valid['alert_ram'] = min(100, max(1, (int)($input['alert_ram'] ?? $valid['alert_ram'])));
        $valid['alert_disk'] = min(100, max(1, (int)($input['alert_disk'] ?? $valid['alert_disk'])));
        $valid['cache_ttl'] = max(1, (int)($input['cache_ttl'] ?? $valid['cache_ttl']));
        add_settings_error($this->option_name, 'smd_saved', 'Settings saved.', 'updated');
        return $valid;
    }

    public function field_refresh_interval() {
        $opts = get_option($this->option_name, $this->defaults);
        $val = esc_attr($opts['refresh_interval']);
        echo "<input type='number' min='1' name='{$this->option_name}[refresh_interval]' value='{$val}' />";
    }

    public function field_use_real_metrics() {
        $opts = get_option($this->option_name, $this->defaults);
        $checked = $opts['use_real_metrics'] ? 'checked' : '';
        echo "<label><input type='checkbox' name='{$this->option_name}[use_real_metrics]' value='1' {$checked} /> Try to read real system metrics (Linux friendly)</label>";
    }

    public function field_alert_thresholds() {
        $opts = get_option($this->option_name, $this->defaults);
        $cpu = esc_attr($opts['alert_cpu']);
        $ram = esc_attr($opts['alert_ram']);
        $disk = esc_attr($opts['alert_disk']);
        echo "CPU: <input style='width:70px' type='number' min='1' max='100' name='{$this->option_name}[alert_cpu]' value='{$cpu}' /> &nbsp; ";
        echo "RAM: <input style='width:70px' type='number' min='1' max='100' name='{$this->option_name}[alert_ram]' value='{$ram}' /> &nbsp; ";
        echo "Disk: <input style='width:70px' type='number' min='1' max='100' name='{$this->option_name}[alert_disk]' value='{$disk}' />";
    }

    public function enqueue_assets($hook) {
        if (!in_array($hook, ['toplevel_page_server-monitor-dashboard', 'server-monitor-dashboard_page_server-monitor-dashboard-settings'])) return;

        wp_enqueue_script('smd-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], null, true);

        $opts = wp_parse_args(get_option($this->option_name, []), $this->defaults);
        wp_localize_script('smd-chartjs', 'smdConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restBase' => esc_url_raw(rest_url('system-monitor/v1/stats')),
            'nonce' => wp_create_nonce('smd_nonce'),
            'refresh' => (int)$opts['refresh_interval'],
            'alerts' => [
                'cpu' => (int)$opts['alert_cpu'],
                'ram' => (int)$opts['alert_ram'],
                'disk' => (int)$opts['alert_disk']
            ]
        ]);

        $js = $this->get_inline_js();
        wp_add_inline_script('smd-chartjs', $js, 'after');

        $css = "
            .smd-wrap{max-width:1200px;margin:20px auto;padding:18px;background:#fff;border-radius:10px;}
            .smd-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;margin-bottom:18px;}
            .smd-card{padding:12px;border:1px solid #ccc;border-radius:8px;background:#f0f0f0;position:relative;}
            .smd-chart{height:200px}
            #smdSpiderChart{height:300px !important;}
            .smd-label{position:absolute;top:10px;right:12px;font-weight:600;color:#222}
            .smd-info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:18px;}
            .smd-info-card{padding:10px;border-radius:6px;background:#f8f8f8;border:1px solid #eee}
            .smd-alert{padding:6px 10px;border-radius:4px;font-weight:600}
            .smd-alert.ok{background:#e6ffea;color:#1f7a1f}
            .smd-alert.warn{background:#fff4e6;color:#7a5f1f}
            .smd-alert.crit{background:#ffe6e6;color:#7a1f1f}
            
            
        ";
        wp_add_inline_style('wp-admin', $css);
    }

    private function get_inline_js() {
        return <<<JS
(function(){
    const cfg = window.smdConfig || {};
    const refresh = cfg.refresh || 2;
    const ajaxUrl = cfg.ajaxUrl;
    const nonce = cfg.nonce;
    const alerts = cfg.alerts || {cpu:80, ram:80, disk:90};

    // helper - line chart
    function makeChart(elId, color, max=100){
        const ctx = document.getElementById(elId)?.getContext('2d');
        if(!ctx) return null;
        return new Chart(ctx, {
            type:'line',
            data:{labels:Array(60).fill(''), datasets:[{data:Array(60).fill(0), borderColor:color, backgroundColor:color.replace('1)', '0.12)'), tension:0.3, borderWidth:2, pointRadius:0, fill:true}]},
            options:{responsive:true, animation:false, plugins:{legend:{display:false}, tooltip:{enabled:false}}, scales:{x:{grid:{color:'rgba(150,150,150,0.2)'}}, y:{min:0, max:max, grid:{color:'rgba(150,150,150,0.3)'}}}}
        });
    }

    // helper - pie/donut chart
    function makePieChart(elId, used, free){
        const ctx = document.getElementById(elId)?.getContext('2d');
        if(!ctx) return null;
        return new Chart(ctx, {
            type:'doughnut',
            data:{
                labels:['Used','Free'],
                datasets:[{
                    data:[used, free],
                    backgroundColor:['rgba(255,99,132,0.8)','rgba(75,192,192,0.8)'],
                    borderWidth:1
                }]
            },
            options:{
                responsive:true,
                plugins:{legend:{position:'bottom'}}
            }
        });
    }

    // helper - spider/radar chart
function makeSpiderChart(elId, data){
    const ctx = document.getElementById(elId)?.getContext('2d');
    if(!ctx) return null;
    return new Chart(ctx, {
        type:'radar',
        data:{
            labels:["CPU","RAM","Disk","Net Up","Net Down"],
            datasets:[{
                label:"System Metrics",
                data:data,
                backgroundColor:"rgba(54,162,235,0.2)",
                borderColor:"rgba(54,162,235,1)",
                borderWidth:2
            }]
        },
        options:{
            responsive:true,
            scales:{ r:{ suggestedMin:0, suggestedMax:100 } }
        }
    });
}



    let charts = {
        cpu: makeChart('smdCpuChart','rgba(0,123,255,1)'),
        ram: makeChart('smdRamChart','rgba(40,200,120,1)'),
        disk: makeChart('smdDiskChart','rgba(255,140,0,1)'),
        netUp: makeChart('smdNetUpChart','rgba(0,150,255,1)', 100),
        netDown: makeChart('smdNetDownChart','rgba(200,0,150,1)', 200),
        diskPie: makePieChart('smdDiskPieChart', 0, 100),
        spider: makeSpiderChart('smdSpiderChart', [0,0,0,0,0])


    };

    function push(arr, val, maxLen=60){ arr.push(val); if(arr.length>maxLen) arr.shift(); return arr; }
    let dataStore = {cpu:[], ram:[], disk:[], netUp:[], netDown:[]};

    function fetchStats(){
        fetch(cfg.restBase, {headers:{'X-WP-NONCE': nonce}})
        .then(r=>{ if(!r.ok) throw null; return r.json(); })
        .catch(()=> {
            const fd = new FormData();
            fd.append('action','smd_get_stats');
            fd.append('nonce', nonce);
            return fetch(ajaxUrl, {method:'POST', body: fd}).then(r=>r.json()).then(j=> j.success ? j.data : Promise.reject('ajax fail'));
        })
        .then(d=>{
            // labels
            document.getElementById('smdCpuLabel').textContent = d.cpu + '%';
            document.getElementById('smdRamLabel').textContent = d.ram_percent + '%';
            document.getElementById('smdDiskLabel').textContent = d.disk_percent + '%';
            document.getElementById('smdNetUpLabel').textContent = d.net_up + ' Mbps';
            document.getElementById('smdNetDownLabel').textContent = d.net_down + ' Mbps';
            document.getElementById('smdUptime').textContent = d.uptime || '-';
            document.getElementById('smdLoadAvg').textContent = d.load_avg ? d.load_avg.join(', ') : '-';
            document.getElementById('smdDbSize').textContent = d.db_size || '-';
            document.getElementById('smdDiskPieLabel').textContent = d.disk_percent + '%';

            // alerts
            applyAlert('smdCpuAlert', d.cpu, alerts.cpu);
            applyAlert('smdRamAlert', d.ram_percent, alerts.ram);
            applyAlert('smdDiskAlert', d.disk_percent, alerts.disk);

            // line charts
            pushAndUpdate('cpu', d.cpu);
            pushAndUpdate('ram', d.ram_percent);
            pushAndUpdate('disk', d.disk_percent);
            pushAndUpdate('netUp', d.net_up);
            pushAndUpdate('netDown', d.net_down);

            // pie chart
            if(charts.diskPie){
                charts.diskPie.data.datasets[0].data = [d.disk_percent, Math.max(0, 100 - d.disk_percent)];
                charts.diskPie.update();
            }
            if(charts.spider){
                charts.spider.data.datasets[0].data = [
                    d.cpu,
                    d.ram_percent,
                    d.disk_percent,
                    d.net_up,
                    d.net_down
            ];
            charts.spider.update();
}


        })
        .catch(()=>{ /* no-op */ });
    }

    function applyAlert(elId, value, threshold){
        const el = document.getElementById(elId);
        if(!el) return;
        el.classList.remove('ok','warn','crit');
        if(value >= Math.max(95, threshold + 10)) el.classList.add('crit');
        else if(value >= threshold) el.classList.add('warn');
        else el.classList.add('ok');
    }

    function pushAndUpdate(key, val){
        dataStore[key] = push(dataStore[key], Number(val||0));
        const ch = charts[key];
        if(!ch) return;
        ch.data.datasets[0].data = dataStore[key].slice(-60);
        ch.update();
    }

    // start
    fetchStats();
    setInterval(fetchStats, Math.max(1000, refresh*1000));
})();
JS;
    }

    public function register_rest_routes() {
        register_rest_route('system-monitor/v1', '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_stats'],
            'permission_callback' => function() { return current_user_can('manage_options'); }
        ]);
    }

    public function rest_get_stats($request) {
        return rest_ensure_response($this->collect_stats());
    }

    public function ajax_get_stats() {
        check_ajax_referer('smd_nonce', 'nonce');
        $data = $this->collect_stats();
        wp_send_json_success($data);
    }

    private function collect_stats() {
        $opts = wp_parse_args(get_option($this->option_name, []), $this->defaults);
        $cache_ttl = max(1, (int)$opts['cache_ttl']);
        $cached = get_transient('smd_stats_cache');
        if ($cached) return $cached;

        $use_real = !empty($opts['use_real_metrics']);

        // CPU: try sys_getloadavg, convert to percent by cores
        $cpu_percent = 0;
        $load = null;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg(); // [1,5,15]
            $cores = $this->get_cpu_cores();
            if ($cores > 0) {
                $cpu_percent = min(100, round(($load[0] / $cores) * 100, 1));
            } else {
                $cpu_percent = min(100, round($load[0] * 10, 1));
            }
        } else {
            $cpu_percent = rand(5, 30);
        }

        // Memory: try /proc/meminfo on Linux
        $ram_percent = 0;
        $ram_total_mb = 0;
        $ram_used_mb = 0;
        if ($use_real && file_exists('/proc/meminfo')) {
            $mem = @file_get_contents('/proc/meminfo');
            if ($mem !== false) {
                preg_match('/MemTotal:\s+(\d+) kB/i', $mem, $m1);
                preg_match('/MemAvailable:\s+(\d+) kB/i', $mem, $m2);
                if (!empty($m1[1]) && !empty($m2[1])) {
                    $ram_total_kb = (int)$m1[1];
                    $ram_avail_kb = (int)$m2[1];
                    $ram_used_kb = $ram_total_kb - $ram_avail_kb;
                    $ram_total_mb = round($ram_total_kb / 1024, 1);
                    $ram_used_mb = round($ram_used_kb / 1024, 1);
                    $ram_percent = round(($ram_used_kb / $ram_total_kb) * 100, 1);
                }
            }
        }

        // fallback / simulation if not found
        if ($ram_percent === 0) {
            $php_used_mb = round(memory_get_usage(true) / (1024*1024), 1);
            $php_limit = $this->php_memory_limit_mb();
            $ram_percent = $php_limit > 0 ? round(($php_used_mb / $php_limit) * 100, 1) : rand(20,70);
            $ram_total_mb = $php_limit;
            $ram_used_mb = $php_used_mb;
        }

        // Disk usage for WP root
        $path = ABSPATH;
        $disk_total = @disk_total_space($path);
        $disk_free = @disk_free_space($path);
        $disk_percent = 0;
        if ($disk_total > 0 && $disk_free !== false) {
            $disk_percent = round((($disk_total - $disk_free) / $disk_total) * 100, 1);
            $disk_total_gb = round($disk_total / (1024*1024*1024), 2);
        } else {
            $disk_percent = rand(10,60);
            $disk_total_gb = '-';
        }

        // Uptime and load average
        $uptime = $this->get_uptime();
        $load_avg = $load ?? (function_exists('sys_getloadavg') ? sys_getloadavg() : null);

        // Network (simulated unless you implement real collection)
        if ($use_real) {
            $net_up = rand(1,40);
            $net_down = rand(1,120);
        } else {
            $net_up = rand(0,40);
            $net_down = rand(0,120);
        }

        // DB size approx (INFORMATION_SCHEMA)
        global $wpdb;
        $db_size = $this->get_db_size();

        // WordPress counts
        $posts = wp_count_posts()->publish ?? 0;
        $pages = wp_count_posts('page')->publish ?? 0;
        $users = count_users()['total_users'] ?? 0;

        // Top processes (Linux best-effort)
        $top_procs = $this->get_top_processes();

        $out = [
            'cpu' => $cpu_percent,
            'load_avg' => $load_avg,
            'ram_percent' => $ram_percent,
            'ram_total_mb' => $ram_total_mb,
            'ram_used_mb' => $ram_used_mb,
            'php_memory_mb' => round(memory_get_usage(true)/(1024*1024),1),
            'disk_percent' => $disk_percent,
            'disk_total_gb' => $disk_total_gb ?? '-',
            'uptime' => $uptime,
            'net_up' => $net_up,
            'net_down' => $net_down,
            'db_size' => $db_size,
            'posts' => $posts,
            'pages' => $pages,
            'users' => $users,
            'top_processes' => $top_procs,
        ];

        set_transient('smd_stats_cache', $out, $cache_ttl);

        return $out;
    }

    private function get_cpu_cores() {
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                preg_match_all('/^processor/m', $cpuinfo, $m);
                if (!empty($m[0])) return count($m[0]);
            }
        }
        if (function_exists('shell_exec')) {
            $n = @shell_exec('nproc 2>/dev/null');
            if ($n !== null) return (int)trim($n);
        }
        return 1;
    }

    private function php_memory_limit_mb() {
        $ml = ini_get('memory_limit');
        if (!$ml) return 0;
        if ($ml === '-1') return 0;
        $last = strtolower(substr($ml, -1));
        $val = (int)$ml;
        switch($last) {
            case 'g': return $val * 1024;
            case 'm': return $val;
            case 'k': return round($val / 1024, 1);
            default: return $val;
        }
    }

    private function get_uptime() {
        if (function_exists('shell_exec')) {
            $out = @shell_exec('uptime -p 2>/dev/null');
            if ($out) return trim($out);
        }
        if (is_readable('/proc/uptime')) {
            $u = file_get_contents('/proc/uptime');
            if ($u) {
                $secs = (int)floor(floatval(explode(' ', $u)[0]));
                return $this->seconds_to_human($secs);
            }
        }
        return 'N/A';
    }

    private function seconds_to_human($s) {
        $d = floor($s/86400); $s -= $d*86400;
        $h = floor($s/3600); $s -= $h*3600;
        $m = floor($s/60); $s -= $m*60;
        $out = [];
        if ($d) $out[] = $d.'d';
        if ($h) $out[] = $h.'h';
        if ($m) $out[] = $m.'m';
        if (!$out) $out[] = $s.'s';
        return implode(' ', $out);
    }

    private function get_db_size() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $tables = $wpdb->get_results("SHOW TABLE STATUS LIKE '{$prefix}%'", ARRAY_A);
        if (!$tables) return '-';
        $size = 0;
        foreach ($tables as $t) {
            $size += ($t['Data_length'] + $t['Index_length']);
        }
        return round($size / (1024*1024), 2) . ' MB';
    }

    private function get_top_processes($limit = 5) {
        $out = [];
        if (function_exists('shell_exec')) {
            $cmd = "ps -eo pid,ppid,cmd,%mem,%cpu --sort=-%mem | head -n " . (int)($limit+1) . " 2>/dev/null";
            $txt = @shell_exec($cmd);
            if ($txt) {
                $lines = array_filter(array_map('trim', explode(PHP_EOL, $txt)));
                if (count($lines) > 1) array_shift($lines);
                foreach (array_slice($lines, 0, $limit) as $ln) {
                    $parts = preg_split('/\s+/', $ln, 5);
                    if (count($parts) >= 5) {
                        $out[] = ['pid' => $parts[0], 'mem' => $parts[3], 'cpu' => $parts[4] ?? '', 'cmd' => $parts[4]];
                    }
                }
            }
        }
        return $out;
    }

    public function render_page() {
        $stats = $this->collect_stats();
        $opts = wp_parse_args(get_option($this->option_name, []), $this->defaults);
        ?>
        <div class="smd-wrap">
            <h1>Server Monitor Dashboard</h1>

            <div class="smd-info-grid">
                <div class="smd-info-card"><strong>PHP Version:</strong> <?php echo phpversion(); ?></div>
                <div class="smd-info-card"><strong>MySQL Version:</strong> <?php global $wpdb; echo esc_html($wpdb->db_version()); ?></div>
                <div class="smd-info-card"><strong>WordPress:</strong> <?php echo esc_html(get_bloginfo('version')); ?></div>
                <div class="smd-info-card"><strong>Memory Limit:</strong> <?php echo esc_html(ini_get('memory_limit')); ?></div>
                <div class="smd-info-card"><strong>Max Upload:</strong> <?php echo esc_html(ini_get('upload_max_filesize')); ?></div>
                <div class="smd-info-card"><strong>DB Size:</strong> <span id="smdDbSize"><?php echo esc_html($stats['db_size']); ?></span></div>
            </div>

            <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px;">
                <div class="smd-alert <?php echo ($stats['cpu'] >= $opts['alert_cpu'] ? 'warn' : 'ok'); ?>" id="smdCpuAlert">CPU</div>
                <div class="smd-alert <?php echo ($stats['ram_percent'] >= $opts['alert_ram'] ? 'warn' : 'ok'); ?>" id="smdRamAlert">RAM</div>
                <div class="smd-alert <?php echo ($stats['disk_percent'] >= $opts['alert_disk'] ? 'warn' : 'ok'); ?>" id="smdDiskAlert">Disk</div>
                <div style="margin-left:auto">Uptime: <strong id="smdUptime"><?php echo esc_html($stats['uptime']); ?></strong> &nbsp; Load: <strong id="smdLoadAvg"><?php echo is_array($stats['load_avg']) ? implode(', ', $stats['load_avg']) : '-'; ?></strong></div>
            </div>

            <div class="smd-grid">
                <div class="smd-card"><h4>Overall Metrics</h4><canvas id="smdSpiderChart" class="smd-chart"></canvas></div>
                <div class="smd-card"><h4>CPU <span class="smd-label" id="smdCpuLabel"><?php echo esc_html($stats['cpu']); ?>%</span></h4><canvas id="smdCpuChart" class="smd-chart"></canvas></div>
                <div class="smd-card"><h4>RAM <span class="smd-label" id="smdRamLabel"><?php echo esc_html($stats['ram_percent']); ?>%</span></h4><canvas id="smdRamChart" class="smd-chart"></canvas></div>
                <div class="smd-card"><h4>Disk <span class="smd-label" id="smdDiskLabel"><?php echo esc_html($stats['disk_percent']); ?>%</span></h4><canvas id="smdDiskChart" class="smd-chart"></canvas></div>
                <div class="smd-card"><h4>Net Up <span class="smd-label" id="smdNetUpLabel"><?php echo esc_html($stats['net_up']); ?> Mbps</span></h4><canvas id="smdNetUpChart" class="smd-chart"></canvas></div>
                <div class="smd-card"><h4>Net Down <span class="smd-label" id="smdNetDownLabel"><?php echo esc_html($stats['net_down']); ?> Mbps</span></h4><canvas id="smdNetDownChart" class="smd-chart"></canvas></div>
                <div class="smd-card"><h4>Disk Usage Pie <span class="smd-label" id="smdDiskPieLabel"><?php echo esc_html($stats['disk_percent']); ?>%</span></h4><canvas id="smdDiskPieChart" class="smd-chart"></canvas></div>
            </div>

            <h3 style="margin-top:18px">WordPress</h3>
            <div class="smd-info-grid" style="margin-bottom:10px;">
                <div class="smd-info-card">Posts: <?php echo esc_html($stats['posts']); ?></div>
                <div class="smd-info-card">Pages: <?php echo esc_html($stats['pages']); ?></div>
                <div class="smd-info-card">Users: <?php echo esc_html($stats['users']); ?></div>
                <div class="smd-info-card">Top processes: <?php
                    if (!empty($stats['top_processes'])) {
                        echo '<ul style="margin:6px 0;padding-left:18px;">';
                        foreach ($stats['top_processes'] as $p) {
                            printf('<li style="font-size:12px">PID %s — %s</li>', esc_html($p['pid'] ?? ''), esc_html($p['cmd'] ?? ''));
                        }
                        echo '</ul>';
                    } else { echo '-'; }
                ?></div>
            </div>
        </div>
        <?php
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Server Monitor Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                do_settings_sections($this->option_name);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

} // end class

// bootstrap
Server_Monitor_Dashboard::instance();

} // endif class exists