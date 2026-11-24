# Production deploy

The app runs at <https://pixelforge.blunier.me> on a Linux host with Docker. It is the Docker Compose stack in [`deploy/compose.yaml`](../deploy/compose.yaml), in the host's stack directory (`DEPLOY_PATH`, `/opt/stacks/pixelforge` by default):

| Service     | What it does                                                                                     |
| ----------- | ------------------------------------------------------------------------------------------------ |
| `app`       | The Laravel app on FrankenPHP, built from the [`Dockerfile`](../Dockerfile). Internal port 8080. |
| `queue`     | `php artisan queue:work`, same image.                                                            |
| `scheduler` | `php artisan schedule:work`, same image.                                                         |
| `pgsql`     | Postgres 18, private to this stack.                                                              |

The stack publishes no ports and doesn't terminate TLS. A reverse proxy on the host does both, and reaches `app` as `pixelforge-app:8080` over an external Docker network named `proxy`. The other services are only on the stack's private `internal` network.

Persistent named volumes survive redeploys: `pgsql-data` (database) and `app-storage` (`storage/`, including generated images). Only `docker compose down -v` deletes them.

Every push to `main` runs [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml):

1. It runs the test workflow.
2. If that passes, it builds the image and pushes it to GHCR as `ghcr.io/ablunier/pixelforge-assessment:<commit-sha>` and `:latest`.
3. It copies the [`deploy/`](../deploy) folder (`compose.yaml` and `deploy.sh`) to the stack directory over SSH and runs `./deploy.sh <commit-sha>`. The script records the tag as `IMAGE_TAG` in `.env`, pulls the image, runs `php artisan migrate --force` and restarts the stack.
4. It checks that `https://pixelforge.blunier.me/up` responds.

The host never builds anything and has no checkout of the repo. Production secrets live only in the stack directory's `.env` on the host and in GitHub Actions secrets. They are never committed.

## One-time setup (owner)

These steps assume the host already runs Docker and a reverse proxy that terminates TLS and is attached to an external Docker network named `proxy` (`docker network create proxy`).

### 1. DNS and reverse proxy

Add an `A` record (and `AAAA` if the host has IPv6) for `pixelforge.blunier.me` pointing at the host's public IP. The proxy can only get a certificate once this resolves.

Route the domain to `pixelforge-app:8080`. With Caddy, for example:

```caddyfile
pixelforge.blunier.me {
	encode zstd gzip
	reverse_proxy pixelforge-app:8080
}
```

Until the first deploy (step 6) the site returns 502. Reload the proxy after it if it doesn't pick the upstream up by itself.

### 2. Deploy user and stack directory

GitHub Actions connects as a user that can run Docker. Create one as root (or reuse an existing one):

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

### 3. Production `.env`

Create `.env` in the stack directory as the deploy user (`chmod 600`). Generate `APP_KEY` with `echo "base64:$(openssl rand -base64 32)"` and use a long random `DB_PASSWORD`. `deploy.sh` adds and updates `IMAGE_TAG` itself.

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

PIXELFORGE_API_KEY=...
PIXELFORGE_WEBHOOK_SECRET=...
```

`PIXELFORGE_BASE_URL` defaults to `https://pixelforge.promptedit.dev/v1`; set it only to point elsewhere.

### 4. GitHub Actions secrets

On the repository's `production` environment (**Settings → Environments → production**), which the deploy job uses:

| Secret               | Value                                                                       |
| -------------------- | --------------------------------------------------------------------------- |
| `DEPLOY_HOST`        | The host's IP or hostname.                                                  |
| `DEPLOY_USER`        | The deploy user, e.g. `deploy`.                                             |
| `DEPLOY_SSH_KEY`     | The private key generated above.                                            |
| `DEPLOY_KNOWN_HOSTS` | Output of `ssh-keyscan -t ed25519 <host-ip>`, run from a machine you trust. |

To use a different stack directory, set the `DEPLOY_PATH` **variable** (not a secret) on the same environment. It defaults to `/opt/stacks/pixelforge`.

### 5. First deploy and public image

Run the deploy workflow: `gh workflow run deploy.yml --ref main`. The first push creates the `pixelforge-assessment` package on GHCR as private, so the host can't pull it and the deploy job fails. Make the package public (your GitHub profile → **Packages → pixelforge-assessment → Package settings → Change visibility → Public**), then re-run the failed job. Later deploys need nothing more.

Then check `https://pixelforge.blunier.me/up`.

### 6. Pixelforge

Set the production key's webhook URL to `https://pixelforge.blunier.me/webhooks/pixelforge`.

### 7. Reviewer account and sandbox checks

Create the reviewer account and submit one real Generation per sandbox keyword ([ADR-0008](adr/0008-production-reviewer-examples.md)), then work through [sandbox-verification.md](sandbox-verification.md):

```sh
docker compose exec -e REVIEWER_EMAIL=<reviewer-email> app php artisan reviewer:create --examples
```

It makes the reviewer an admin (so they see the admin report) and tops their Balance up to $10.00 before submitting, which covers the examples' Holds and leaves enough to try every quality and size. Running it again submits only the missing examples and adds Credits only if the Balance has dropped below $10.00. It prints a generated password once; share it privately with the submission. To choose the password instead, add `REVIEWER_EMAIL` and `REVIEWER_PASSWORD` to the stack's `.env` and recreate the containers. Don't run `db:seed` for this: in production it creates the account but no example Generations, since made-up ones wouldn't match Pixelforge's records.

## Operating

From the stack directory on the host:

```sh
docker compose ps
docker compose logs -f app queue
docker compose exec app php artisan tinker
docker compose exec app php artisan user:make-admin <email>
```

To roll back, deploy an earlier commit's image with `./deploy.sh <older-commit-sha>`. Migrations are not rolled back, and the next push to `main` deploys forward again. Each deploy keeps only the current app image on the host.

Notes:

- The app trusts `X-Forwarded-*` headers from any proxy. That's safe because `app` publishes no ports, so only containers on the `proxy` network can reach it. Don't add a `ports:` mapping to it, and don't attach untrusted containers to `proxy`.
- Email verification is off and there is no mail delivery (`MAIL_MAILER=log`); password-reset emails only go to the log.
