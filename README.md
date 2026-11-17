# PixelForge

Image-generation app that resells Pixelforge's provider at cost plus markup. See [GLOSSARY.md](GLOSSARY.md) and [docs/adr](docs/adr).

## Run locally

```sh
docker compose up --build
```

Starts the app (http://localhost:8000), a queue worker, the scheduler and Postgres. The first start creates `.env` from `.env.example`; fill in `PIXELFORGE_BASE_URL`, `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` to talk to Pixelforge.

## Tests

```sh
composer ci:check
```
