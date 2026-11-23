# Production deploy

The app runs at <https://pixelforge.blunier.me> on a DigitalOcean droplet shared with another site. It is the Docker Compose stack in [`deploy/compose.yaml`](../deploy/compose.yaml), in `/opt/stacks/pixelforge`:

| Service     | What it does                                                                                     |
| ----------- | ------------------------------------------------------------------------------------------------ |
| `app`       | The Laravel app on FrankenPHP, built from the [`Dockerfile`](../Dockerfile). Internal port 8080. |
| `queue`     | `php artisan queue:work`, same image.                                                            |
| `scheduler` | `php artisan schedule:work`, same image.                                                         |
| `pgsql`     | Postgres 18, private to this stack.                                                              |

TLS and the public ports belong to the droplet's shared Caddy in `/opt/stacks/caddy`, which also serves the other site. `app` joins Caddy's external `proxy` network under the alias `pixelforge-app`. The other services are only on the stack's private `internal` network.

Persistent named volumes survive redeploys: `pgsql-data` (database) and `app-storage` (`storage/`, including generated images). Only `docker compose down -v` deletes them. Certificates live in the shared Caddy's volumes.

Every push to `main` runs [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml):

1. It runs the test workflow.
2. If that passes, it builds the image and pushes it to GHCR as `ghcr.io/ablunier/pixelforge-assessment:<commit-sha>` and `:latest`.
3. It copies the [`deploy/`](../deploy) folder (`compose.yaml` and `deploy.sh`) to `/opt/stacks/pixelforge` over SSH and runs `./deploy.sh <commit-sha>`. The script records the tag as `IMAGE_TAG` in `.env`, pulls the image, runs `php artisan migrate --force` and restarts the stack.
4. It checks that `https://pixelforge.blunier.me/up` responds.

The droplet never builds anything and has no checkout of the repo. Production secrets live only in `/opt/stacks/pixelforge/.env` on the droplet and in GitHub Actions secrets. They are never committed.

## One-time setup (owner)

These steps assume the droplet already runs Docker, the shared Caddy in `/opt/stacks/caddy`, and its external `proxy` network.

### 1. DNS

Add an `A` record (and `AAAA` if the droplet has IPv6) for `pixelforge.blunier.me` pointing at the droplet's public IP. Caddy can only get a certificate once this resolves.

### 2. Caddy site

Add this block to `/opt/stacks/caddy/Caddyfile`:

```caddyfile
pixelforge.blunier.me {
	encode zstd gzip
	reverse_proxy pixelforge-app:8080
}
```

Reload Caddy after the first deploy (step 6); before that, the site returns 502:

```sh
cd /opt/stacks/caddy && docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile
```

### 3. Deploy user and stack directory

GitHub Actions connects as a user that can run Docker. Reuse the one that deploys the other site, or create one, as root:

```sh
adduser --disabled-password --gecos '' deploy
usermod -aG docker deploy
mkdir -p /opt/stacks/pixelforge && chown deploy:deploy /opt/stacks/pixelforge
```

Create a key pair for GitHub Actions and authorise it for that user:

```sh
ssh-keygen -t ed25519 -N '' -C github-actions-pixelforge -f /tmp/deploy_key
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
cat /tmp/deploy_key.pub >> /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys && chmod 600 /home/deploy/.ssh/authorized_keys
cat /tmp/deploy_key   # → DEPLOY_SSH_KEY secret, then: rm /tmp/deploy_key*
```

### 4. Production `.env`

Create `/opt/stacks/pixelforge/.env` as the deploy user (`chmod 600`). Generate `APP_KEY` with `echo "base64:$(openssl rand -base64 32)"` and use a long random `DB_PASSWORD`. `deploy.sh` adds and updates `IMAGE_TAG` itself.

```dotenv
APP_NAME=PixelForge
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://pixelforge.blunier.me

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

### 5. GitHub Actions secrets

On the repository's `production` environment (**Settings → Environments → production**), which the deploy job uses:

| Secret               | Value                                                                          |
| -------------------- | ------------------------------------------------------------------------------ |
| `DEPLOY_HOST`        | The droplet's IP or hostname.                                                  |
| `DEPLOY_USER`        | The deploy user, e.g. `deploy`.                                                |
| `DEPLOY_SSH_KEY`     | The private key generated above.                                               |
| `DEPLOY_KNOWN_HOSTS` | Output of `ssh-keyscan -t ed25519 <droplet-ip>`, run from a machine you trust. |

Optional variable `DEPLOY_PATH` overrides the stack directory (default `/opt/stacks/pixelforge`).

### 6. First deploy and public image

Run the deploy workflow: `gh workflow run deploy.yml --ref main`. The first push creates the `pixelforge-assessment` package on GHCR as private, so the droplet can't pull it and the deploy job fails. Make the package public (your GitHub profile → **Packages → pixelforge-assessment → Package settings → Change visibility → Public**), then re-run the failed job. Later deploys need nothing more.

Then reload Caddy (step 2) and check `https://pixelforge.blunier.me/up`.

### 7. Pixelforge

Set the production key's webhook URL to `https://pixelforge.blunier.me/webhooks/pixelforge`.

### 8. Reviewer account and sandbox checks

Create the reviewer account and submit one real Generation per sandbox keyword, then work through [sandbox-verification.md](sandbox-verification.md):

```sh
docker compose exec -e REVIEWER_EMAIL=<reviewer-email> app php artisan reviewer:create --examples
```

It prints a generated password once; share it privately with the submission. To choose the password instead, add `REVIEWER_EMAIL` and `REVIEWER_PASSWORD` to `/opt/stacks/pixelforge/.env` and recreate the containers. Don't run `db:seed` for this: in production it creates the account but no example Generations, since made-up ones wouldn't match Pixelforge's records.

## Operating

From `/opt/stacks/pixelforge` on the droplet:

```sh
docker compose ps
docker compose logs -f app queue
docker compose exec app php artisan tinker
```

To roll back, deploy an earlier commit's image with `./deploy.sh <older-commit-sha>`. Migrations are not rolled back, and the next push to `main` deploys forward again. Each deploy keeps only the current app image on the droplet.

Notes:

- The app trusts `X-Forwarded-*` headers from any proxy. That's safe because `app` publishes no ports, so only containers on the `proxy` network (Caddy and the other site) can reach it. Don't add a `ports:` mapping to it, and don't attach untrusted containers to `proxy`.
- Email verification is off and there is no mail delivery (`MAIL_MAILER=log`); password-reset emails only go to the log.
