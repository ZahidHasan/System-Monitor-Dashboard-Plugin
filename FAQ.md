## WP Server Monitor Dashboard v2.0 

## ❓ Frequently Asked Questions (FAQ)

Q: **Why does the CPU load look lower than Task Manager?**
A: The dashboard reports average CPU usage across all cores, while Task Manager often shows per‑core peaks and updates at a different sampling interval. This makes Task Manager appear higher, especially during short spikes. The plugin gives a smoothed, trend‑focused view.

Q: **Why does RAM usage differ from Task Manager?**
A: Task Manager includes cached and reserved memory, while the plugin reports active allocated memory. This can make WordPress show lower values. Both are correct — they just measure different aspects of RAM.

Q: **What does the MySQL Buffer Pool chart show?**
A: It displays how much of the InnoDB buffer pool is currently used versus total allocated. High usage (>80%) means the buffer is well utilized; low usage (<30%) may indicate under‑allocation or underutilization.

Q: **Why do I see “unexpected output during activation”?**
A: This happens if there are stray spaces, BOM characters, or a closing  tag in the plugin file. Removing extra whitespace and ensuring the file starts with  and ends cleanly fixes it.

Q: **Can I monitor multiple servers with this plugin?**
A: Currently, the plugin monitors the server where WordPress is installed. Multi‑server support is planned for a future release (see Roadmap).

Q: **How often are charts updated?**
A: Charts refresh based on the polling interval set in the plugin (default: every two seconds). This provides near real‑time monitoring without overloading the server.

Q: **Does the plugin affect site performance?**
A: Resource usage is minimal. Charts are generated with lightweight queries and JavaScript rendering. For very high‑traffic sites, you can increase the polling interval to reduce overhead.

Q: **Why does the Network chart still show upload/download even when Wi‑Fi is off?**
The chart reads system network counters directly from the OS, not just the active Wi‑Fi adapter. That means:
- Other interfaces (Ethernet, loopback, virtual adapters like Docker, VPN, Hyper‑V) may still be active and reporting traffic.
- Background services (local processes talking to , database connections, or inter‑process communication) generate network I/O that shows up even without external internet.
- Cached or buffered data: The OS may report residual bytes from recent activity until counters reset.
- Polling method: The plugin queries cumulative counters, so even if Wi‑Fi is disabled, other adapters keep incrementing values.
👉 In short: the chart reflects all network interfaces, not just Wi‑Fi. If you want to isolate Wi‑Fi only, you’d need adapter‑specific monitoring.
