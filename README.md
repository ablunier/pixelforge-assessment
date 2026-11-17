# PixelForge

Image-generation app that resells Pixelforge's provider at cost plus markup. See [GLOSSARY.md](GLOSSARY.md) and [docs/adr](docs/adr).

## Run locally

```sh
docker compose up --build
```

Starts the app (http://localhost:8000), a queue worker, the scheduler and Postgres. Copy `.env.example` to `.env` and fill in `PIXELFORGE_BASE_URL`, `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` to talk to Pixelforge.

## Tests

```sh
composer ci:check
```
