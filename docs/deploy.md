# Production deploy

The app runs at <https://pixelforge.blunier.me> on a DigitalOcean droplet, as the Docker Compose stack in [`compose.production.yaml`](../compose.production.yaml):

| Service     | What it does                                                                                     |
| ----------- | ------------------------------------------------------------------------------------------------ |
| `caddy`     | Only public service (ports 80/443). Gets the TLS certificate automatically and proxies to `app`. |
| `app`       | The Laravel app on FrankenPHP, built from the [`Dockerfile`](../Dockerfile). Internal port 8080. |
| `queue`     | `php artisan queue:work`, same image.                                                            |
| `scheduler` | `php artisan schedule:work`, same image.                                                         |
| `pgsql`     | Postgres 18.                                                                                     |

Persistent named volumes survive redeploys: `pgsql-data` (database), `app-storage` (`storage/`, including generated images) and `caddy-data`/`caddy-config` (certificates). Only `docker compose down -v` deletes them.

Every push to `main` runs [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml): it runs the test workflow, and only if that passes it connects to the droplet over SSH and runs [`scripts/deploy.sh`](../scripts/deploy.sh), which checks out the pushed commit, rebuilds the image, runs `php artisan migrate --force` and restarts the stack. It then checks that `https://pixelforge.blunier.me/up` responds.

Production secrets live only in `/opt/pixelforge/.env` on the droplet and in GitHub Actions secrets. They are never committed.

## One-time setup (owner)

### 1. DNS

Add an `A` record (and `AAAA` if the droplet has IPv6) for `pixelforge.blunier.me` pointing at the droplet's public IP. Caddy can only get a certificate once this resolves, and ports 80 and 443 must be reachable.

### 2. Droplet bootstrap

On a fresh Ubuntu droplet, as root:

```sh
# Docker Engine + Compose plugin
curl -fsSL https://get.docker.com | sh

# Deploy user that can run Docker
adduser --disabled-password --gecos '' deploy
usermod -aG docker deploy

# Firewall: SSH and web only
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw allow 443/udp && ufw --force enable

# App checkout
mkdir -p /opt/pixelforge && chown deploy:deploy /opt/pixelforge
sudo -u deploy git clone https://github.com/ablunier/pixelforge-assessment.git /opt/pixelforge
```

(If the repository is private, add a read-only deploy key on GitHub for the `deploy` user and clone over SSH instead.)

Create a key pair for GitHub Actions and authorise it for the `deploy` user:

```sh
ssh-keygen -t ed25519 -N '' -C github-actions-deploy -f /tmp/deploy_key
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
cat /tmp/deploy_key.pub >> /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys && chmod 600 /home/deploy/.ssh/authorized_keys
cat /tmp/deploy_key   # → DEPLOY_SSH_KEY secret, then: rm /tmp/deploy_key*
```

Create `/opt/pixelforge/.env` as the `deploy` user (`chmod 600`). Generate `APP_KEY` with `echo "base64:$(openssl rand -base64 32)"` and use a long random `DB_PASSWORD`:

```dotenv
APP_NAME=PixelForge
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://pixelforge.blunier.me
APP_DOMAIN=pixelforge.blunier.me

LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=pixelforge
DB_USERNAME=pixelforge
DB_PASSWORD=...

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local
MAIL_MAILER=log

PIXELFORGE_BASE_URL=...
PIXELFORGE_API_KEY=...
PIXELFORGE_WEBHOOK_SECRET=...
```

Then do the first deploy by hand:

```sh
sudo -iu deploy
cd /opt/pixelforge && scripts/deploy.sh
```

### 3. GitHub Actions secrets

In the repository's **Settings → Secrets and variables → Actions** (or on a `production` environment, which the deploy job uses):

| Secret               | Value                                                                          |
| -------------------- | ------------------------------------------------------------------------------ |
| `DEPLOY_HOST`        | The droplet's IP or hostname.                                                  |
| `DEPLOY_USER`        | `deploy`                                                                       |
| `DEPLOY_SSH_KEY`     | The private key generated above.                                               |
| `DEPLOY_KNOWN_HOSTS` | Output of `ssh-keyscan -t ed25519 <droplet-ip>`, run from a machine you trust. |

Optional variable `DEPLOY_PATH` overrides the checkout path (default `/opt/pixelforge`).

### 4. Pixelforge

Set the production key's webhook URL to `https://pixelforge.blunier.me/webhooks/pixelforge`.

## Operating

From `/opt/pixelforge` on the droplet:

```sh
docker compose -f compose.production.yaml ps
docker compose -f compose.production.yaml logs -f app queue
docker compose -f compose.production.yaml exec app php artisan tinker
```

Notes:

- The app trusts `X-Forwarded-*` headers from any proxy, which is safe only because `app` publishes no ports and is reachable only through Caddy. Don't add a `ports:` mapping to it.
- Email verification is off and there is no mail delivery (`MAIL_MAILER=log`); password-reset emails only go to the log.
