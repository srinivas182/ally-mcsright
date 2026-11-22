# 01 · Running RightAlly on a server: your WHM VPS now, DigitalOcean or AWS later (DP1)

RightAlly runs entirely in Docker: the app, two queue workers, the scheduler, MySQL and Redis, each with its own
memory and CPU limit. The only thing it exposes is one port on the server's own address (`127.0.0.1:8100`). In front:

```
Visitor ──HTTPS──▶ Cloudflare ──HTTPS──▶ WHM's Apache (rightally.app, *.rightally.app) ──▶ 127.0.0.1:8100 ──▶ RightAlly in Docker
```

Nothing else on the server changes: your other websites, WHM's own MySQL and PHP are not touched. It is the same
pattern as Team to Team Referrals. On DigitalOcean or AWS the same Docker files run unchanged; only the front door
changes (a load balancer instead of Apache).

## Addresses (all covered by Cloudflare's free certificate)

| Address                              | What it is                                                                        |
| ------------------------------------ | --------------------------------------------------------------------------------- |
| `rightally.app`, `www.rightally.app` | RightAlly's own website                                                           |
| `app.rightally.app`                  | RightAlly HQ                                                                      |
| `{brokerage}.rightally.app`          | Each brokerage's back office (`acme.rightally.app`)                               |
| `auth.rightally.app`                 | Google and Microsoft sign-in hand-off                                             |
| `mcp.rightally.app`                  | "Connect your AI" for brokers and agents                                          |
| `inbound.rightally.app`              | Forwarded lead emails (MX to the inbound email provider, later)                   |
| `{brokerage}.rightally.site`         | Brokerage public websites: needs `rightally.site` on Cloudflare too (see the end) |

## 1 · Cloudflare (rightally.app)

1. **DNS:** add `A  @  <server IP>`, `A  *  <server IP>` and `A  www  <server IP>`, all **Proxied** (orange cloud).
2. **SSL/TLS → Overview:** set **Full (strict)**.
3. **SSL/TLS → Origin Server → Create certificate:** hostnames `rightally.app` and `*.rightally.app`, 15 years.
   Keep the certificate and the private key; you paste them into WHM in step 2.
4. **SSL/TLS → Edge Certificates:** turn on **Always Use HTTPS**.
5. **Speed → Optimization:** turn **Rocket Loader off** (it rewrites the app's scripts).

## 2 · WHM and cPanel

1. **Account:** in WHM, create a cPanel account for `rightally.app` (or add it to an existing account as a domain).
   Call the account user `<cpuser>` below.
2. **Wildcard:** in that cPanel, **Domains → Create a new domain** → `*.rightally.app` (same document root).
3. **Certificate:** WHM → **SSL/TLS → Install an SSL Certificate on a Domain**: paste the Cloudflare origin
   certificate and key for `rightally.app`, then again for `*.rightally.app`.
   In cPanel → **SSL/TLS Status**, **exclude** both from AutoSSL (it would replace the Cloudflare certificate).
4. **Send the traffic to RightAlly.** Find the folder names cPanel uses for the two sites:

    ```bash
    ls /var/cpanel/userdata/<cpuser>/      # rightally.app and the wildcard: wildcard_safe.rightally.app
    ```

    cPanel names the wildcard's folder `wildcard_safe.rightally.app` (not `*.rightally.app`). **Install the
    certificates (step 3) first**: the secure (`ssl`) sites only exist once a certificate is installed. Then, for
    **each** of the two names, create the same file in both the `ssl` and `std` folders:

    ```bash
    for site in rightally.app wildcard_safe.rightally.app; do
      for kind in ssl std; do
        mkdir -p /etc/apache2/conf.d/userdata/$kind/2_4/<cpuser>/$site
        cat > /etc/apache2/conf.d/userdata/$kind/2_4/<cpuser>/$site/rightally.conf <<'CONF'
    ProxyPreserveHost On
    ProxyRequests Off
    RequestHeader set X-Forwarded-Proto "https"
    ProxyPass        / http://127.0.0.1:8100/ timeout=75
    ProxyPassReverse / http://127.0.0.1:8100/
    CONF
      done
    done
    /scripts/rebuildhttpdconf && /scripts/restartsrv_httpd
    grep -n "userdata/.*/<cpuser>" /etc/apache2/conf/httpd.conf     # 4 Include lines without a # in front
    curl -sI https://app.rightally.app/up | head -1                  # HTTP/2 200 once RightAlly is running
    ```

    Nothing needs opening in the firewall: port 8100 only listens on the server itself.

    **Keep AutoSSL away:** it does not trust Cloudflare's certificate and would replace it (Cloudflare then shows
    error 526). In cPanel › SSL/TLS Status exclude all three names, or:

    ```bash
    uapi --user=<cpuser> SSL add_autossl_excluded_domains domains='rightally.app,*.rightally.app,www.rightally.app'
    ```

    **Checking the certificate:** test against the server's public IP, not `127.0.0.1` (cPanel's secure sites
    listen on the public IP; `127.0.0.1` always shows the server's own certificate):

    ```bash
    echo | openssl s_client -connect <server IP>:443 -servername app.rightally.app 2>/dev/null | openssl x509 -noout -issuer
    ```

    It should name `CloudFlare Origin SSL Certificate Authority`.

## 3 · Start RightAlly (first time)

Docker and the compose plugin are already on the server (Team to Team uses them). As root:

```bash
git clone https://github.com/srinivas182/ally-mcsright.git /opt/rightally    # use a deploy key once the repo is private
cd /opt/rightally
cp .env.production.example .env
echo "base64:$(openssl rand -base64 32)"                                    # paste this into APP_KEY
nano .env                                                                    # fill in every CHANGE_ME
scripts/ops/deploy.sh
```

The first build takes 10–15 minutes. Then:

```bash
C="docker compose -f compose.prod.yaml exec app php artisan"
$C tenants:bootstrap-hq --admin-email=you@yourcompany.com     # emails you the HQ invitation
$C billing:starter-prices                                      # the starter packages and agent seats
$C ai:sync-features
$C launch:check                                                # the launch checklist
```

Open `https://app.rightally.app`, accept the invitation, and turn on two-step sign-in. Then add RightAlly's own
website pages (they need an HQ person to be their author):

```bash
docker compose -f compose.prod.yaml exec app php artisan marketing:starter
```

On this one server,
`launch:check` will show two items as not done, both expected until the move: **files on shared storage** (set
Cloudflare R2 in `.env` if you want it now) and **a separate brokerage database** (set up and tested at the move).

## AI

`ai:sync-features` links every AI feature to a model, so it needs a provider and models first. In **HQ › AI console**:

1. **Providers:** add Anthropic (Claude), recommended. Add OpenAI too if you want voice notes (speech to text).
2. **API key:** paste the key. RightAlly checks it with the provider and saves it encrypted; nobody sees it again.
3. **Edit:** confirm zero retention and no training (check your provider account really gives these), add a note,
   set **Active**.
4. **Models:** add the main model (chat, json, vision) and, for voice notes, a speech-to-text model (transcribe), with
   the provider's prices.
5. Run `docker compose -f compose.prod.yaml exec app php artisan ai:sync-features`.

## Email

RightAlly's own email (invitations, sign-in codes, notices, Watchtower) is set up in **HQ › Outside services ›
Platform email**: Brevo (recommended), Postmark or Amazon SES. Verify the sending domain with the provider and add its
DNS records in Cloudflare (DNS only), paste the keys, put your own address in **Test contacts**, run **Check
connection**, and switch to **Test**: every email then goes only to you, with the real recipient in the subject. Switch
to **Live** when it works. Until then nothing is really sent: `tenants:bootstrap-hq` prints the HQ invitation link
for you to open.

## 4 · Every night: backups

```bash
crontab -e
15 3 * * * cd /opt/rightally && scripts/ops/backup.sh >> /var/log/rightally-backup.log 2>&1
```

Backups go to `/var/backups/rightally` (14 days). **Copy them off the server:** install `rclone`, add a remote for
Cloudflare R2 (or DigitalOcean Spaces), and set `BACKUP_REMOTE=r2:rightally-backups` in `.env`. The deploy script
also takes a backup before every update.

## 5 · Watching it

- HQ › Watchtower: name who gets problem emails.
- **Better Stack** (free): monitor `https://app.rightally.app/up` and `https://app.rightally.app/up/scheduler`, and
  send its alerts to the uptime address HQ › Watchtower makes.
- Outside services › Error tracking: Sentry, if wanted.

## 6 · Updating

```bash
cd /opt/rightally && scripts/ops/deploy.sh            # latest main
scripts/ops/deploy.sh v1.0.0                          # or a tagged release
```

It backs up, builds while the running release keeps serving, runs the database updates once, restarts, and checks
`/up`. If the new release does not answer, it puts the previous one back by itself.

**Rolling back by hand** (a release answered but something is wrong): `scripts/ops/deploy.sh <previous tag>`. If
the bad release changed the database, restore the backup the deploy took just before it:
`scripts/ops/restore.sh /var/backups/rightally/db-….sql.gz /var/backups/rightally/files-….tar.gz`.

**Restarting without new code** (after changing `.env`, say): `scripts/ops/restart.sh`. It restarts the app, web,
queue and scheduler on the release that is live now. Do not run `docker compose up` by hand without `APP_RELEASE`
set: parts would start on an older build and the site shows a 502.

## 7 · How much of the server it uses

Set in `.env`; the defaults leave room for other sites on an 8 GB / 4-core VPS:

| Part                             | Memory      | CPU    |
| -------------------------------- | ----------- | ------ |
| App (PHP, `FPM_MAX_CHILDREN=16`) | 2 GB        | 1.5    |
| Queue workers (2)                | 768 MB each | 1 each |
| Scheduler                        | 512 MB      | 0.5    |
| MySQL (1 GB buffer)              | 2 GB        | 1      |
| Redis                            | 512 MB      | 0.5    |
| Web (nginx)                      | 256 MB      | 0.5    |

A busy hour can use about 6.5 GB at most; normal use is far less. On a dedicated server, raise them.

## 8 · Moving to DigitalOcean or AWS

The same image and scripts. In outline (a full guide comes with the move):

1. A server (DigitalOcean Droplet, or AWS) with Docker; managed MySQL and Redis when wanted.
2. `git clone`, copy the same `.env` (keep the same `APP_KEY`: it unlocks every encrypted value).
3. Take a last backup here (`scripts/ops/backup.sh`) with the app stopped; copy it over; `scripts/ops/restore.sh`.
4. In Cloudflare, point `@`, `*` and `www` at the new server (or its load balancer). Visitors move within minutes.
5. Then: files to Spaces or S3, the separate brokerage database, the load test.

## Brokerage public websites

Brokerage websites live at `{brokerage}.rightally.site` (or the brokerage's own domain). They cannot share
`*.rightally.app` with the back offices. To test them: add `rightally.site` to Cloudflare with `A @` and `A *` to this
server, create a second origin certificate, and repeat step 2 for `rightally.site` and `*.rightally.site` with the
same proxy file. Until then everything else works; websites can be previewed from the back office.
