# Seed demo data

Use the `ibexa:firewall:seed-demo` console command to populate `server_metrics` and `http_request_logs` with realistic dummy data. This is intended for local development, UI design reviews, and demos — not for production.

## Prerequisites

- Database tables created (see [README — Database setup](../README.md#6-database-setup)):
  - `server_metrics`
  - `http_request_logs`
- Redis available (same `cache.redis` pool the bundle uses in production)
- Bundle registered and console commands visible:

  ```bash
  php bin/console list ibexa:firewall
  ```

## Quick start

```bash
php bin/console ibexa:firewall:seed-demo --clear
```

Then open **Content → Firewall → Dashboard** in the Ibexa admin UI.

`--clear` deletes all existing rows in both tables before seeding. Omit it to append demo data to whatever is already stored.

## What gets generated

### `server_metrics`

Time-series samples spanning the configured history window (default: 7 days, one point every 5 minutes).

Each row includes:

| Column | Demo behaviour |
|--------|----------------|
| `cpu` | Sine-wave baseline with random noise and occasional spikes |
| `memory` | Similar pattern, offset from CPU |
| `redis_mem` | Low, slowly varying service memory share |
| `apache2_mem` | Moderate Apache footprint |
| `varnish_mem` | Moderate Varnish footprint |
| `mysql_mem` | Higher baseline with variation |
| `os_disk` | Slowly increasing disk usage |
| `data_disk` | Slowly increasing data volume usage |
| `timestamp` | Evenly spaced from `now - metrics-days` to `now` |

This gives enough density to exercise the metrics chart widget at **3h**, **12h**, **1d**, and **1w** ranges.

### `http_request_logs`

A configurable number of request rows (default: 800) with mixed traffic types:

| Traffic type | Approx. share | Flags set |
|--------------|---------------|-----------|
| Normal | ~78% | all flags off |
| Legitimate bot | ~7% | `isBotAgent` |
| Banned / fake bot | ~5% | `isBotAgent`, `isBannedBot` |
| JS challenge | ~5% | `isChallenge` |
| Rate limited | ~5% | `isRateLimited` |

Additional realism:

- **Paths** weighted toward `/`, `/media/*`, `/search`, `/login`, `/api/*`, and honeypot-style paths like `/wp-login.php`
- **User agents** include browsers, crawlers, and scripted clients
- **Timestamps** skewed so ~75% of rows fall within the last 24 hours (dashboard stat cards use a 24h window)
- **IPs** mix IPv4 and IPv6
- **Timing** random `firewallTime` and `responseTime` values per row

### Redis cache

Unless `--no-cache` is passed, the command writes the latest `server_metrics` row to the `haeretici_server_metrics` Redis key so the dashboard summary cards load immediately without waiting for a live cron run.

## Command reference

```
php bin/console ibexa:firewall:seed-demo [options]
```

| Option | Default | Description |
|--------|---------|-------------|
| `--clear` | off | `DELETE FROM http_request_logs` and `DELETE FROM server_metrics` before inserting |
| `--metrics-days=N` | `7` | How many days of metrics history to generate |
| `--metrics-interval=N` | `5` | Minutes between each metrics sample |
| `--requests=N` | `800` | Number of `http_request_logs` rows to insert |
| `--seed=N` | random | Fix the RNG seed for reproducible datasets |
| `--no-cache` | off | Do not update the `haeretici_server_metrics` Redis key |

### Examples

**Standard demo reset**

```bash
php bin/console ibexa:firewall:seed-demo --clear
```

**Heavier dataset for chart and table stress-testing**

```bash
php bin/console ibexa:firewall:seed-demo --clear \
  --metrics-days=14 \
  --metrics-interval=2 \
  --requests=2000
```

**Reproducible UI snapshots (same numbers every run)**

```bash
php bin/console ibexa:firewall:seed-demo --clear --seed=42
```

**DB only — skip Redis write**

```bash
php bin/console ibexa:firewall:seed-demo --clear --no-cache
```

**Append extra rows without wiping existing data**

```bash
php bin/console ibexa:firewall:seed-demo --requests=200
```

## Dashboard areas fed by this data

| Dashboard section | Source |
|-------------------|--------|
| CPU / Memory cards | Latest row from Redis or `server_metrics` |
| Service memory cards | Same latest row |
| Metrics chart widget | `server_metrics` via `/haeretici_firewall/metrics` AJAX endpoint |
| Past 24h request stats | Aggregates on `http_request_logs` |
| Top paths | `GROUP BY path` over last 24h |
| Recent requests table | Last 10 rows by `timestamp DESC` |

After seeding, try different chart ranges in the **Server Metrics Over Time** widget to see how downsampling behaves at 12h, 1d, and 1w.

## Relationship to `ibexa:firewall:store`

| Command | Purpose |
|---------|---------|
| `ibexa:firewall:store` | Production cron: collect live server stats, flush Redis request buffers to DB, prune old rows |
| `ibexa:firewall:seed-demo` | Development/demo: insert synthetic rows directly into the database |

Do not schedule `seed-demo` in crontab. Running `store` after seeding will append real metrics and may prune demo request logs older than 7 days.

## Troubleshooting

**Command not found**

Clear cache and confirm the bundle is enabled:

```bash
php bin/console cache:clear
php bin/console list ibexa:firewall
```

**Dashboard still empty**

- Confirm tables exist and contain rows: `SELECT COUNT(*) FROM server_metrics;`
- Re-run with `--clear` and without `--no-cache`
- Hard-refresh the admin page after seeding

**Chart shows flat or sparse lines**

Increase history density:

```bash
php bin/console ibexa:firewall:seed-demo --clear --metrics-days=7 --metrics-interval=1
```

**Stats cards show zeros**

Most demo requests are within 24h, but if server clock or timezone differs, check:

```sql
SELECT COUNT(*) FROM http_request_logs
WHERE timestamp >= NOW() - INTERVAL 1 DAY;
```

## Sample paths and agents

The command uses fixed weighted paths and a small agent pool defined in `Command/SeedDemoDataCommand.php`. Edit those constants if you need scenario-specific demos (e.g. heavy API traffic, crawl storms, or honeypot hits).