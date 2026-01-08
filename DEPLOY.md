# GitHub Deployment Guide

## Quick Setup (5 minutes)

### 1. Create a GitHub repo
- Go to github.com → New repository
- Name: `emailexpert-news-agent` (or whatever)
- **Private** recommended
- Don't initialize with README (you already have one)

### 2. Push this code
```bash
cd emailexpert-news-agent
git init
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin git@github.com:YOUR_USERNAME/emailexpert-news-agent.git
git push -u origin main
```

### 3. Customize your config
Edit `config/watchers.yml` with your actual sources before pushing, or edit it directly in GitHub.

### 4. That's it!
The workflow runs every **Friday at 8am CET**. 

You can also trigger it manually:
- Go to Actions tab → "Weekly News Report" → "Run workflow"

### 5. Get your reports
After each run, download the report from:
- Actions → Latest run → Artifacts → "weekly-report-xxx"

---

## Optional: Email delivery

If you want reports emailed to you automatically, add these secrets in GitHub:

**Settings → Secrets and variables → Actions → New repository secret**

| Secret | Example |
|--------|---------|
| `SMTP_SERVER` | `smtp.gmail.com` |
| `SMTP_PORT` | `587` |
| `SMTP_USERNAME` | `your-email@gmail.com` |
| `SMTP_PASSWORD` | `your-app-password` |
| `EMAIL_TO` | `andrew@emailexpert.com` |
| `EMAIL_FROM` | `News Agent <your-email@gmail.com>` |

Then add a **repository variable** (not secret):
- `EMAIL_ENABLED` = `true`

---

## Notes

- The SQLite database doesn't persist between runs (GitHub Actions are ephemeral)
- Each run is a fresh crawl of the last 7 days
- If you need historical tracking, consider a cheap VPS instead
- Free tier gives you 2,000 minutes/month - this workflow uses ~2-5 mins per run
