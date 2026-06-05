# Server setup checklist — koszykomat.pl

One-time setup for the DirectAdmin VPS (46.29.21.135) + Supabase + Cloudflare + GitHub Actions
pipeline. Companion to `deploy/setup-server.sh` (run that first) and
`context/deployment/deploy-plan.md` (the approved plan this implements).

Conventions below: `<da-user>` = the DirectAdmin account owning koszykomat.pl;
`$BASE` = `/home/<da-user>/domains/koszykomat.pl`.

## 1. Supabase project (dashboard, human-only)

1. Create a project: region **Frankfurt (`eu-central-1`)**, free tier.
2. From **Connect → Connection pooling**, note BOTH connection variants:
   - **Transaction pooler** — port **6543** → `.env` `DB_PORT` (web requests).
   - **Session pooler** — port **5432** → `.env` `DB_SESSION_PORT` (migrations, long jobs).
   - Host must be the pooler host (`aws-0-eu-central-1.pooler.supabase.com`);
     username looks like `postgres.<project-ref>`.
3. Fill `DB_USERNAME` / `DB_PASSWORD` in `$BASE/shared/.env`.

> ⚠️ **Free-tier pause rule**: 7 days without database queries → project is paused and the
> app is fully down until you click *Restore* in the dashboard. The nightly ingestion cron
> (added with feature work) doubles as the heartbeat — its failure alerting must be loud.
> Unpause runbook: dashboard → project → Restore (~30 s).

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
vi $BASE/shared/.env   # fill Supabase DB_USERNAME / DB_PASSWORD
```

The script creates `releases/`, `shared/storage/`, and a `shared/.env` template with a
generated `APP_KEY` (never overwrites an existing `.env`).

## 4. Docroot → `current/public`

DirectAdmin serves `public_html` (HTTP) and `private_html` (HTTPS). Point both at the
live release:

```bash
cd $BASE
rm -rf public_html private_html        # fresh domain — only the DA placeholder inside
ln -sfn ./current/public public_html
ln -sfn ./public_html private_html
```

> Check first that `public_html` contains nothing but the DirectAdmin placeholder before
> removing it. (`current` will dangle until the first successful deploy — that's fine.)

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
<da-user> ALL=(root) NOPASSWD: /usr/bin/systemctl reload php-fpm83
```

and set `OPCACHE_STRATEGY=fpm-reload` (workflow env or `authorized_keys` `environment=`).
Verify the exact FPM service name first: `systemctl list-units 'php*'`.

## 6. Outbound connectivity check (VPS → Supabase)

```bash
# From the VPS — both pooler ports must be reachable outbound:
timeout 5 bash -c 'cat < /dev/null > /dev/tcp/aws-0-eu-central-1.pooler.supabase.com/6543' && echo "6543 OK"
timeout 5 bash -c 'cat < /dev/null > /dev/tcp/aws-0-eu-central-1.pooler.supabase.com/5432' && echo "5432 OK"
```

If blocked: open outbound TCP 5432 + 6543 in the server firewall (CSF/iptables).

## 7. Cron (DirectAdmin's own cron UI — survives panel rebuilds)

DirectAdmin → User Level → **Cron Jobs** → add (single entry, `flock`-guarded per the
risk register):

```
* * * * * flock -n /tmp/koszykomat-sched.lock /usr/local/php83/bin/php /home/<da-user>/domains/koszykomat.pl/current/artisan schedule:run >> /dev/null 2>&1
```

Verify the PHP CLI path first: `ls /usr/local/php83/bin/php` (adjust if the server names
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
- When the nightly ingestion lands: scheduler `emailOutputOnFailure` + heartbeat row, so a
  dead cron cannot silently count down to the Supabase free-tier pause.
