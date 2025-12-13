## WP Server Monitor Dashboard v2.0 

### 📊 Chart Descriptions
- **CPU Usage Chart**
Displays real‑time processor utilization as a percentage. Helps identify spikes in load and potential bottlenecks.
- **RAM Usage Chart**
Shows current memory consumption in MB and percentage of total available RAM. Useful for monitoring memory leaks or heavy processes.
- **Disk Usage Chart**
Tracks disk space usage and I/O activity. Indicates storage pressure and helps plan capacity.
- **Network Throughput Chart**
Monitors upload and download bandwidth in KB/s or MB/s. Useful for spotting unusual traffic or saturation.
- **Process Count Chart**
Displays the number of active processes running on the system. Helps detect runaway tasks or abnormal activity.
- **MySQL Buffer Pool Chart**
Reports InnoDB buffer pool usage in MB and percentage. Shows how much of the buffer is occupied by cached data pages, helping optimize database performance and detect memory pressure.
- **Spider Chart** (System Overview)
A six‑axis radar chart combining CPU, RAM, Disk, Network Up/Down, and MySQL Buffer metrics. Provides a holistic view of system health at a glance.


## 📊 Chart Interpretation Guide

**CPU Usage Chart**
- High values (>80%): System under heavy load, may cause slow response times.
- Low values (<20%): Idle or underutilized system.
- Spikes: Short bursts are normal, sustained spikes suggest optimization needed.

**RAM Usage Chart**
- High usage (>85%): Risk of swapping, performance degradation.
- Moderate usage (50–70%): Healthy utilization.
- Low usage (<30%): Plenty of free memory, but may indicate under‑allocation.
 	
**Disk Usage Chart**
- High usage (>90%): Storage nearing capacity, cleanup or expansion required.
- Frequent I/O spikes: Heavy read/write operations, check database or logging activity.
- Stable moderate usage: Normal operation.
 	
**Network Throughput Chart**
- Consistently high upload/download: Possible data transfer, backups, or suspicious traffic.
- Sudden spikes: Could indicate large file transfers or attacks (DDoS).
- Low baseline: Normal for idle servers.
 	
**Process Count Chart**
- High process count: May indicate runaway tasks or misconfigured services.
- Stable count: Normal operation.
- Sudden increase: Investigate background jobs or cron tasks.
 	
**MySQL Buffer Pool Chart**
- High usage (>80%): Buffer pool is well utilized, but may need tuning if performance drops.
- Low usage (<30%): Buffer pool underutilized, consider resizing.
- Balanced usage (40–70%): Healthy caching behavior.
  
**Spider Chart** (System Overview)
- Balanced shape: Indicates stable system health across all metrics.
- One axis stretched: Pinpoints the resource under stress (e.g., CPU spike).
- Collapsed shape: Suggests underutilization or misconfiguration.


## 🛠 Troubleshooting Checklist

**CPU Usage > 90%**
→ Check running processes (/).
→ Kill or optimize runaway tasks.
→ Consider upgrading CPU or redistributing workload.

**RAM Usage > 85%**
→ Identify memory‑heavy processes.
→ Restart services with leaks.
→ Increase PHP memory limit or add physical RAM.

**Disk Usage > 90%**
→ Clear logs, temp files, and caches.
→ Archive or delete unused data.
→ Expand disk capacity if persistent.

**Network Throughput unusually high**
→ Inspect active connections ().
→ Check for large transfers or suspicious traffic.
→ Apply firewall rules or rate limits if needed.

**Process Count spikes suddenly**
→ Review cron jobs or background tasks.
→ Kill duplicate or zombie processes.
→ Audit service configurations.

**MySQL Buffer Pool consistently > 80%**
→ Increase buffer pool size in .
→ Optimize queries and indexes.
→ Monitor for slow queries.
 	
**Spider Chart shows one axis stretched**
→ Focus troubleshooting on that resource (e.g., CPU spike → check processes).
→ If multiple axes are stressed, consider scaling resources or load balancing.