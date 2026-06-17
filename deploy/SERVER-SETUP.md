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
`httpd.conf`**, then:

- **CUSTOM1** (appears before the variables are set):

  ```
  |?DOCROOT=`HOME`/domains/`DOMAIN`/current/public|
  ```

- **CUSTOM4** (the very last entry) — Apache must read Laravel's `public/.htaccess`
  (front-controller rewrite, or `/up` 404s) and follow the `current` symlink:

  ```apache
  <Directory "/home/ebizo/domains/koszykomat.pl/current/public">
      AllowOverride All
      Options +SymLinksIfOwnerMatch
      Require all granted
  </Directory>
  ```

Save → DirectAdmin rewrites the vhosts and reloads Apache. Confirm both vhosts now show
`DocumentRoot ".../current/public"` and that the extra `<Directory>` block is present.

> Why not a `public_html → current/public` symlink? It works on a pure-nginx box, but
> DirectAdmin can regenerate `public_html` on account operations, and the token override is
> panel-native (survives rebuilds). Use the symlink **only** as a fallback if `DOCROOT`
> token overrides are unavailable:
> ```bash
> cd $BASE && rm -rf public_html private_html
> ln -sfn ./current/public public_html && ln -sfn ./public_html private_html
> ```

## 5. Opcache strategy (root, one-time — pick ONE)

PHP-FPM caches resolved realpaths: after a symlink swap it can keep serving **old code**
(risk register: "stale code via opcache"). Two options; `deploy/release.sh` supports both
via the `OPCACHE_STRATEGY` env var (default `realpath`).

**Option A — nginx `$realpath_root` (preferred, zero per-deploy action):**
as root, customize the DirectAdmin nginx template so FastCGI params use `$realpath_root`:

```bash
mkdir -p /usr/local/directadmin/data/templates/custom
cp /usr/local/directadmin/data/templates/nginx_php.conf /usr/local/directadmin/data/templates/custom/
# In the custom copy replace $document_root with $realpath_root for
# SCRIPT_FILENAME and DOCUMENT_ROOT, then rebuild configs:
sed -i 's/\$document_root/\$realpath_root/g' /usr/local/directadmin/data/templates/custom/nginx_php.conf
echo "action=rewrite&value=httpd" >> /usr/local/directadmin/data/task.queue
/usr/local/directadmin/custombuild/build rewrite_confs   # or wait for the task queue
```

**Option B — FPM reload per deploy:** sudoers entry for the deploy user:

```
# /etc/sudoers.d/koszykomat-deploy
<da-user> ALL=(root) NOPASSWD: /usr/bin/systemctl reload php-fpm85
```

and set `OPCACHE_STRATEGY=fpm-reload` (workflow env or `authorized_keys` `environment=`).
Verify the exact FPM service name first: `systemctl list-units 'php*'`.

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
