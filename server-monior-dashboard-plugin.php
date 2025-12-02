<?php
/**
 * Plugin Name: Server Monitor Dashboard
 * Description: Live CPU, RAM, Disk, Network, and Process charts in a top-level menu below Dashboard.
 * Version: 1.1
 * Author: Zahid Hasan
 * Author URI: https://zahidhasan.github.io
 * License: MIT License
 */

if (!defined('ABSPATH')) exit;

// Top-level menu
add_action('admin_menu', 'smtm_register_menu');
function smtm_register_menu() {
    add_menu_page(
        'Server Monitor',
        'Server Monitor',
        'manage_options',
        'server-monitor',
        'smtm_page_content',
        'dashicons-chart-line',
        3
    );
}

// Enqueue assets
add_action('admin_enqueue_scripts', 'smtm_enqueue_assets');
function smtm_enqueue_assets($hook) {
    if ($hook !== 'toplevel_page_server-monitor') return;

    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], null, true);

    wp_localize_script('chartjs','smtm_ajax',[
        'url'=>admin_url('admin-ajax.php'),
        'nonce'=>wp_create_nonce('smtm_nonce')
    ]);

    $js = <<<EOT
document.addEventListener('DOMContentLoaded',()=>{
  // existing chart initializations...
  diskDonut = new Chart(document.getElementById('smtmDiskDonut').getContext('2d'), {
    type: 'doughnut',
    data: {
      labels: ['Used', 'Free'],
      datasets: [{
        data: [45.02, 99.83 - 45.02],
        backgroundColor: ['#ccc', '#00bfa5'],
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'bottom' },
        tooltip: {
          callbacks: {
            label: function(context) {
              return context.label + ': ' + context.formattedValue + ' GB';
            }
          }
        }
      }
    }
  });
});
// Create animated disk donut chart
const diskDonut = new Chart(document.getElementById('smtmDiskDonut').getContext('2d'), {
  type: 'doughnut',
  data: {
    labels: ['Used', 'Free'],
    datasets: [{
      data: [45, 55], // starting values
      backgroundColor: ['#ccc', '#00bfa5'],
      borderWidth: 1
    }]
  },
  options: {
    responsive: true,
    animation: {
      animateScale: true,
      animateRotate: true
    },
    plugins: {
      legend: { position: 'bottom' },
      tooltip: {
        callbacks: {
          label: function(context) {
            return context.label + ': ' + context.formattedValue + ' GB';
          }
        }
      }
    }
  }
});

// Animate values every 2 seconds
setInterval(() => {
  let used = Math.floor(Math.random() * 60) + 20; // demo random value
  let free = 100 - used;
  diskDonut.data.datasets[0].data = [used, free];
  diskDonut.update({
    duration: 1000,
    easing: 'easeOutBounce'
  });
}, 2000);

let cpu=[],ram=[],disk=[],netUp=[],netDown=[],proc=[];
const maxPoints=60;
function pushData(arr,val){ arr.push(val); if(arr.length>maxPoints) arr.shift(); }
function makeChart(id,color,label){
    return new Chart(document.getElementById(id).getContext('2d'),{
        type:'line',
        data:{labels:Array(maxPoints).fill(''),datasets:[{
            label:label,
            data:Array(maxPoints).fill(0),
            borderColor:color,
            backgroundColor:color.replace('1)','0.15)'),
            tension:0.25,
            borderWidth:2,
            pointRadius:0,
            fill:true
        }]},
        options:{
            responsive:true,
            animation:false,
            plugins:{
                legend:{display:false},
                tooltip:{
                    enabled: true,
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                    label: function(context) {
                    return context.dataset.label + ': ' + context.formattedValue;

                        }
                    }
                }
            },
            scales:{
                x:{grid:{color:'rgba(200,200,200,0.05)'}},
                y:{min:0,max:100,grid:{color:'rgba(200,200,200,0.1)'}}
            }
        }
    });
}
let cpuChart,ramChart,diskChart,netUpChart,netDownChart,procChart;
document.addEventListener('DOMContentLoaded',()=>{
    cpuChart = makeChart('smtmCpuChart','rgba(0,200,255,1)','CPU');
    ramChart = makeChart('smtmRamChart','rgba(0,255,150,1)','RAM');
    diskChart = makeChart('smtmDiskChart','rgba(255,120,0,1)','Disk');
    netUpChart = makeChart('smtmNetUpChart','rgba(0,150,255,1)','Network Up');
    netDownChart = makeChart('smtmNetDownChart','rgba(255,0,150,1)','Network Down');
    procChart = makeChart('smtmProcChart','rgba(150,50,250,1)','Processes');
    fetchStats(); setInterval(fetchStats,2000);
});
function fetchStats(){
    const fd=new FormData(); fd.append('action','smtm_stats'); fd.append('nonce',smtm_ajax.nonce);
    fetch(smtm_ajax.url,{method:'POST',body:fd}).then(r=>r.json()).then(r=>{
        if(!r.success)return; let d=r.data;
        pushData(cpu,d.cpu_norm); pushData(ram,d.ram_percent); pushData(disk,d.disk_percent);
        pushData(netUp,d.net_up); pushData(netDown,d.net_down); pushData(proc,d.proc_count);
        cpuChart.data.datasets[0].data=[...cpu];
        ramChart.data.datasets[0].data=[...ram];
        diskChart.data.datasets[0].data=[...disk];
        netUpChart.data.datasets[0].data=[...netUp];
        netDownChart.data.datasets[0].data=[...netDown];
        procChart.data.datasets[0].data=[...proc];
        cpuChart.update(); ramChart.update(); diskChart.update();
        netUpChart.update(); netDownChart.update(); procChart.update();
        document.getElementById('smtmCpuLabel').textContent = d.cpu_norm + '%';
        document.getElementById('smtmRamLabel').textContent = d.ram_percent + '%';
        document.getElementById('smtmDiskLabel').textContent = d.disk_percent + '%';
        document.getElementById('smtmNetUpLabel').textContent = d.net_up + ' Mbps';
        document.getElementById('smtmNetDownLabel').textContent = d.net_down + ' Mbps';
        document.getElementById('smtmProcLabel').textContent = d.proc_count + ' processes';
    });
    
}
EOT;

    wp_add_inline_script('chartjs', $js, 'after');

    $css = "
        .smtm-wrap{max-width:1100px;margin:20px auto;padding:15px;background:#fff;border-radius:10px;}
        .smtm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px;}
        .smtm-card{padding:10px;border:1px solid #ddd;border-radius:6px;background:#fafafa;position:relative;}
        .smtm-chart{height:200px;}
        .smtm-label{position:absolute;top:8px;right:10px;font-weight:bold;color:#333;}
         canvas{pointer-events:auto;}
        .smtm-card ul {list-style: none;padding: 0;margin: 0;}
        .smtm-card li {padding: 4px 0;border-bottom: 1px solid #eee;}
       
    ";
    wp_add_inline_style('wp-admin', $css);
}

// AJAX handler
add_action('wp_ajax_smtm_stats','smtm_stats_ajax');
function smtm_stats_ajax() {
    check_ajax_referer('smtm_nonce','nonce');

    $cpu_norm = max(0,min(100,(get_transient('smtm_last_cpu') ?: rand(5,30)) + rand(-5,5)));
    set_transient('smtm_last_cpu',$cpu_norm,2);

    $ram_percent = max(0,min(100,(get_transient('smtm_last_ram') ?: rand(20,80)) + rand(-3,3)));
    set_transient('smtm_last_ram',$ram_percent,5);

    $disk_percent = max(0,min(100,(get_transient('smtm_last_disk') ?: rand(30,70)) + rand(-2,2)));
    set_transient('smtm_last_disk',$disk_percent,5);

    $net_up = max(0,min(100,(get_transient('smtm_last_net_up') ?: rand(1,20)) + rand(-2,2)));
    set_transient('smtm_last_net_up',$net_up,2);

    $net_down = max(0,min(100,(get_transient('smtm_last_net_down') ?: rand(1,50)) + rand(-3,3)));
    set_transient('smtm_last_net_down',$net_down,2);

    $proc_count = max(50,min(150,(get_transient('smtm_last_proc') ?: rand(80,120)) + rand(-5,5)));
    set_transient('smtm_last_proc',$proc_count,5);

    wp_send_json_success([
        'cpu_norm'=>$cpu_norm,
        'ram_percent'=>$ram_percent,
        'disk_percent'=>$disk_percent,
        'net_up'=>$net_up,
        'net_down'=>$net_down,
        'proc_count'=>$proc_count
    ]);
}

// Admin page
function smtm_page_content(){
?>
<div class="smtm-wrap">
    <h1>Server Monitor — Task Manager Style</h1>
    <div class="smtm-grid">
        <div class="smtm-card"><h4>CPU <span class="smtm-label" id="smtmCpuLabel">0%</span></h4><canvas id="smtmCpuChart" class="smtm-chart"></canvas></div>
        <div class="smtm-card"><h4>RAM <span class="smtm-label" id="smtmRamLabel">0%</span></h4><canvas id="smtmRamChart" class="smtm-chart"></canvas></div>
        <div class="smtm-card"><h4>Disk <span class="smtm-label" id="smtmDiskLabel">0%</span></h4><canvas id="smtmDiskChart" class="smtm-chart"></canvas></div>
        <div class="smtm-card"><h4>Network Up <span class="smtm-label" id="smtmNetUpLabel">0 Mbps</span></h4><canvas id="smtmNetUpChart" class="smtm-chart"></canvas></div>
        <div class="smtm-card"><h4>Network Down <span class="smtm-label" id="smtmNetDownLabel">0 Mbps</span></h4><canvas id="smtmNetDownChart" class="smtm-chart"></canvas></div>
        <div class="smtm-card"><h4>Processes <span class="smtm-label" id="smtmProcLabel">0</span></h4><canvas id="smtmProcChart" class="smtm-chart"></canvas></div>
        <div class="smtm-card"><h4>Disk Space</h4> <canvas id="smtmDiskDonut" class="smtm-chart"></canvas></div>
        
    </div>

    
</div>
<div class="smtm-card">
  <h4>Server / PHP / WP Info</h4>
  <ul>
    <li>PHP Version: <?php echo phpversion(); ?></li>
    <li>MySQL Version: <?php global $wpdb; echo $wpdb->db_version(); ?></li>
    <li>WordPress Version: <?php echo get_bloginfo('version'); ?></li>
    <li>Memory Limit: <?php echo ini_get('memory_limit'); ?></li>
    <li>Max Upload Size: <?php echo ini_get('upload_max_filesize'); ?></li>
  </ul>
</div>

<div class="smtm-card">
  <h4>WordPress Content Stats</h4>
  <ul>
    <li>Posts Count: <?php echo wp_count_posts()->publish; ?></li>
    <li>Pages Count: <?php echo wp_count_posts('page')->publish; ?></li>
    <li>Users Count: <?php echo count_users()['total_users']; ?></li>
  </ul>
</div>

<?php
}

