# DGV7.0 — Cloud AI & Background Jobs Deployment Guide

This guide provides the complete technical steps to activate the platform's intelligence layer and background automation jobs.

---

## 🚀 Quick Reference: All Cron Jobs

Copy these into your cPanel or VPS crontab. Replace `YOUR_USERNAME` with your actual server username.

| Frequency | File | Purpose |
|---|---|---|
| `* * * * *` | `cron/process_bulk_queue.php` | Process queued bulk airtime/data batches in the background |
| `*/5 * * * *` | `cron/aggregator_monitor.php` | Monitor API provider success rates |
| `0 7 * * *` | `cron/ai_daily_briefing.php` | AI Daily briefing emailed to vendors |
| `0 10 * * *` | `cron/dormant_user_alert.php` | Re-engage inactive users via email |
| `0 8 1 * *` | `cron/ai_monthly_blueprint.php` | Monthly full platform AI Audit |
| `0 8 * * 1` | `cron/ai_weekly_error_report.php` | Weekly AI health scan — error logs emailed to admin + AI enhancement plan |

---

## 🛠 Step 1: Automated Tasks (Cron Jobs)

1. Log into your **cPanel** → **Advanced** → **Cron Jobs**.
2. For each job, select the frequency and paste the command below:

### Bulk Airtime/Data Queue Processor (Every 1 minute)
```bash
* * * * * /usr/bin/php /home/YOUR_USERNAME/public_html/cron/process_bulk_queue.php >> /home/YOUR_USERNAME/logs/bulk_queue.log 2>&1
```
This is what allows bulk airtime/data batches to finish crediting recipients even if the customer's browser closes or their network drops mid-submission. The exact path for your install is also shown in **bc-admin → Account Settings → Developer Tools**.

### API Monitoring (Every 5 minutes)
```bash
*/5 * * * * /usr/bin/php /home/YOUR_USERNAME/public_html/cron/aggregator_monitor.php >> /home/YOUR_USERNAME/logs/agg_mon.log 2>&1
```

### AI Daily Briefing (7:00 AM)
```bash
0 7 * * * /usr/bin/php /home/YOUR_USERNAME/public_html/cron/ai_daily_briefing.php >> /home/YOUR_USERNAME/logs/daily.log 2>&1
```

### Dormant User Re-engagement (10:00 AM)
```bash
0 10 * * * /usr/bin/php /home/YOUR_USERNAME/public_html/cron/dormant_user_alert.php >> /home/YOUR_USERNAME/logs/dormant.log 2>&1
```

### AI Monthly Audit (1st of month)
```bash
0 8 1 * * /usr/bin/php /home/YOUR_USERNAME/public_html/cron/ai_monthly_blueprint.php >> /home/YOUR_USERNAME/logs/audit.log 2>&1
```

### AI Weekly Health Scan & Enhancement Plan (Every Monday 8:00 AM)
```bash
0 8 * * 1 /usr/bin/php /home/YOUR_USERNAME/public_html/cron/ai_weekly_error_report.php >> /home/YOUR_USERNAME/logs/weekly_health.log 2>&1
```
Scans PHP `error_log` files, the `logs/` directory, the DB audit log, failed transactions and password-reset brute-force attempts from the last 7 days. If errors are detected, the Cloud AI engine analyses them and emails a report to the configured admin email (`admin_email` option). The AI also reviews the health snapshot and, when enhancements are worthwhile, emails an implementation plan.

---

## 🧠 Step 2: Cloud AI Activation

The platform uses high-performance Cloud AI. No local software installation is required.

1. **Get API Key**: Obtain a key from [Google AI Studio](https://aistudio.google.com/).
2. **Configure Provider**: 
   - Login as Super Admin.
   - Go to **AI Manager**.
   - Select **Gemini** as the provider and paste your key.
3. **Verify**: Click **Test Connection** to ensure the platform can reach the cloud engine.

---

## 🧪 Step 3: System Validation

Once setup is complete, run the integration test via SSH to verify all connections:

```bash
php /home/YOUR_USERNAME/public_html/tests/ai_integration_test.php
```

---

## 📖 Live Guide
You can also access the **Integration Guide** directly from the Super Admin sidebar for one-click copyable commands tailored to your server path.
