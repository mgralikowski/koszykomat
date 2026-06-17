# Server setup checklist — koszykomat.pl

One-time setup for the DirectAdmin VPS (46.29.21.135, with MySQL 8.0) + Cloudflare + GitHub
Actions pipeline. Companion to `deploy/setup-server.sh` (run that first) and
`context/deployment/deploy-plan.md` (the approved plan this implements).

Conventions below: `<da-user>` = the DirectAdmin account owning koszykomat.pl;
`$BASE` = `/home/<da-user>/domains/koszykomat.pl`.

## 1. MySQL database (DirectAdmin, human-only)

1. DirectAdmin → **Account Manager → MySQL Management → Create new Database**.
2. Create the database and a dedicated user (DirectAdmin prefixes both with the account
   name, e.g. `<da-user>_koszykomat`). Use a strong generated password.
3. Fill `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` in `$BASE/shared/.env` (host stays
   `127.0.0.1`, port `3306` — the DB is local to this VPS).

> ⚠️ **Backups are now your responsibility** — there is no managed-database safety net.
> Enable DirectAdmin's backup feature for this account, or add a `mysqldump` cron, before
> the app holds any data you care about. A server failure with no backup loses everything.

## 2. SSH for the deploy user (DirectAdmin + shell)

1. DirectAdmin → User → **SSH access: enabled** for `<da-user>` (key-only).
2. Generate a dedicated deploy keypair (on your workstation):
   ```bash
   ssh-keygen -t ed25519 -f koszykomat_deploy -C "gh-actions koszykomat" -N ""
   ```
3. Append the **public** key to `/home/<da-user>/.ssh/authorized_keys` with restrictions:
   ```
   no-port-forwarding,no-agent-forwarding,no-X11-forwarding ssh-ed25519 AAAA... gh-actions koszykomat
   ```
4. The **private** key goes into the GitHub secret `DEPLOY_SSH_KEY` (step 8). Delete the
   local copy afterwards.

## 3. Release layout + production .env

```bash
ssh <da-user>@46.29.21.135
bash <(curl -fsSL https://raw.githubusercontent.com/<owner>/<repo>/main/deploy/setup-server.sh)
# or: scp deploy/setup-server.sh to the server and run it
vi $BASE/shared/.env   # fill DB_DATABASE / DB_USERNAME / DB_PASSWORD from step 1
```

The script creates `releases/`, `shared/storage/`, and a `shared/.env` template with a
generated `APP_KEY` (never overwrites an existing `.env`).

## 4. Docroot → `current/public` (Apache, DocumentRoot token)

This server runs **Apache + PHP-FPM** (no nginx). Point the docroot at the release's
`public/` natively, via DirectAdmin's `DOCROOT` token override — it survives panel
rebuilds and covers both the HTTP and HTTPS vhosts at once. As `ebizo`:

**Account Manager → Domain Setup → koszykomat.pl → Customize configuration →
`httpd.conf` → CUSTOM1** (appears before the variables are set):

```
|?DOCROOT=`HOME`/domains/`DOMAIN`/current/public|
```

Save → DirectAdmin rewrites the vhosts and reloads Apache. Confirm both the `:80` and
`:443` vhosts now show `DocumentRoot ".../current/public"`. That's all that was needed on
this server — DA's default config already grants `AllowOverride All` (so Laravel's
`public/.htaccess` front-controller works → `/up` resolves) and `SymLinksIfOwnerMatch`
(so Apache follows the `current` symlink). **Verified: only CUSTOM1 was required.**

> Fallback — only if your DA template lacks a global `AllowOverride All`: after the token
> change, `/` serves the app but `/up` 404s. Add a `<Directory>` block in **CUSTOM4**:
> ```apache
> <Directory "/home/ebizo/domains/koszykomat.pl/current/public">
>     AllowOverride All
>     Options +SymLinksIfOwnerMatch
>     Require all granted
> </Directory>
> ```
>
> A `public_html → current/public` symlink also works (and is the only option on a
> pure-nginx box), but DirectAdmin can regenerate `public_html` on account operations, so
> the token override is preferred here.

## 5. Opcache after symlink swap (no action needed — here's why)

After a `current` symlink swap, PHP-FPM could in theory keep serving **old code** from
opcache/realpath cache (risk register: "stale code via opcache"). On this server it does
**not** require any setup, and we verified it:

- `opcache.validate_timestamps` is on (DirectAdmin default) → opcache revalidates by mtime;
  a new release's files are newer, so the code reloads on its own.
- `realpath_cache_ttl` (default 120 s) bounds any stale symlink resolution to ~2 minutes.
- **The deploy's `Verify` step is the real safety net**: it curls `/_version` and fails the
  CI run (loudly, not silently) if the live SHA ≠ the pushed SHA. A back-to-back deploy
  (`cdbf074` → `e7dda06`) flipped `/_version` immediately — confirmed, no reload needed.

`deploy/release.sh` defaults to `OPCACHE_STRATEGY=none` (no-op) accordingly.

> **Optional hardening, no root/sudo** — only if you ever want to kill the ~120 s window:
> install [`cachetool`](https://github.com/gordalina/cachetool) (a phar) and set
> `OPCACHE_STRATEGY=cachetool` so `release.sh` runs
> `cachetool opcache:reset --fcgi=/usr/local/php85/sockets/ebizo.sock` — this resets the
> FPM pool's opcache directly over its socket, no privileges required. Alternatively set
> `opcache.revalidate_freq=0` + a low `realpath_cache_ttl` via DA's per-domain PHP settings.
> (`OPCACHE_STRATEGY=fpm-reload` exists too but needs a sudoers entry — not available to a
> plain DA user.)

## 6. Database connectivity check (local MySQL)

```bash
# From the VPS — confirm the app can reach the local MySQL with the .env credentials:
mysql -h 127.0.0.1 -P 3306 -u '<da-user>_koszykomat' -p '<da-user>_koszykomat' -e 'SELECT VERSION();'
```

Should print `8.0.x`. The DB is local, so no firewall change is needed; if it fails, the
DB/user/password from step 1 are wrong or the user lacks privileges on that database.

## 7. Cron (DirectAdmin's own cron UI — survives panel rebuilds)

DirectAdmin → User Level → **Cron Jobs** → add (single entry, `flock`-guarded per the
risk register):

```
* * * * * flock -n /tmp/koszykomat-sched.lock /usr/local/php85/bin/php /home/<da-user>/domains/koszykomat.pl/current/artisan schedule:run >> /dev/null 2>&1
```

Verify the PHP CLI path first: `ls /usr/local/php85/bin/php` (adjust if the server names
it differently). The scheduler is empty until feature work adds the nightly ingestion —
the entry is wired now so the pipe is live.

## 8. GitHub repository secrets

Repo → Settings → Secrets and variables → Actions:

| Secret | Value |
|---|---|
| `DEPLOY_SSH_KEY` | contents of the **private** key from step 2 |
| `DEPLOY_HOST` | `46.29.21.135` (the IP — Cloudflare proxy does not pass SSH) |
| `DEPLOY_USER` | `<da-user>` |
| `DEPLOY_PATH` | `/home/<da-user>/domains/koszykomat.pl` |
| `DEPLOY_PORT` | SSH port of the VPS (omit if standard 22) |

## 9. TLS + Cloudflare

1. DirectAdmin → SSL Certificates → **Let's Encrypt** for `koszykomat.pl` + `www`
   (HTTP-01 validation passes through the Cloudflare proxy).
2. Cloudflare dashboard → SSL/TLS → mode **Full (strict)** (anything less causes redirect
   loops or unverified origin traffic).
3. Cloudflare → SSL/TLS → Edge Certificates → **Always Use HTTPS: on**.
4. DNS: `koszykomat.pl` + `www` → A `46.29.21.135`, proxied (orange cloud).

## 10. fail2ban / firewall note

GitHub Actions runner IPs rotate constantly. Key-only auth produces no failed logins, so
default fail2ban setups are fine — but if aggressive per-IP connection-rate rules exist,
exempt the deploy user or relax the SSH jail before the first deploy "flakes".

## 11. First deploy + verification

1. Re-run the **Deploy** workflow (Actions → Deploy → *Run workflow*) or push to `main`.
2. Watch: `gh run watch`.
3. Verify (CI does this too): `curl -fsS https://koszykomat.pl/up` → 200,
   `curl https://koszykomat.pl/_version` → deployed commit SHA.
4. Push a trivial commit and confirm `/_version` changes — proves the opcache strategy.
5. **Rollback drill** (do it once now, not during an incident):
   ```bash
   ssh <da-user>@46.29.21.135
   cd $BASE && ls -1t releases/          # pick the previous release
   ln -sfn $BASE/releases/<previous> current_new && mv -T current_new current
   # OPCACHE_STRATEGY=fpm-reload only: sudo systemctl reload php-fpm83
   curl -fsS https://koszykomat.pl/_version   # old SHA
   ```
   Then re-point at the newest release (or re-run the workflow). NB: DB migrations do NOT
   auto-roll-back — destructive migrations need a manual `migrate:rollback` decision.

## 12. Recommended follow-up (not blocking)

- Free uptime monitor (e.g. UptimeRobot) on `https://koszykomat.pl/up`.
- **Database backups** (not optional): enable DirectAdmin account backups or a nightly
  `mysqldump` to off-server storage — the DB lives on the VPS with no managed snapshots.
- When the nightly ingestion lands: scheduler `emailOutputOnFailure` so a dead refresh cron
  surfaces loudly instead of silently serving stale prices.
