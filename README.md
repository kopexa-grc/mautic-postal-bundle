# kopexa/mautic-postal-bundle

[![Packagist](https://img.shields.io/packagist/v/kopexa/mautic-postal-bundle)](https://packagist.org/packages/kopexa/mautic-postal-bundle) [![ci](https://github.com/kopexa-grc/mautic-postal-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/kopexa-grc/mautic-postal-bundle/actions/workflows/ci.yml)

[Postal](https://docs.postalserver.io) integration for Mautic 7:

- **Sending through Postal's HTTP API** instead of SMTP: mailer DSN
  `postal+api://API_KEY@postal.example.com`.
- **Bounces:** bounces and hard delivery failures reported by Postal mark
  the contact as *Do Not Contact – bounced*, so Mautic stops mailing dead
  addresses.

Appears under *Settings → Plugins* with the Postal logo.

## Installation

Requires **Mautic 7.2+** (Composer-based installation) and **PHP 8.4+**:

```bash
composer require kopexa/mautic-postal-bundle
php bin/console mautic:plugins:reload
```

Configure the Postal key (`postal_jwks_url` or `postal_webhook_public_key`) and add a webhook to `https://<mautic>/mailer/callback` in Postal.

Already included in the hardened Mautic image
[`ghcr.io/kopexa-grc/mautic`](https://github.com/kopexa-grc/mautic-stack).


## How it works

Postal sends webhooks to Mautic's transport callback endpoint
`POST https://<mautic>/mailer/callback`. The plugin:

1. verifies `X-Postal-Signature-256` (RSA-SHA256 over the body) against the
   Postal server's public key — from its JWKS
   (`https://<postal>/.well-known/jwks.json`) or a configured PEM;
2. handles `MessageBounced` and `MessageDeliveryFailed` (status `HardFail`)
   through Mautic's `TransportCallback` API (DNC reason *bounced*);
3. ignores all other events (sent, delayed, held, opens, clicks).

Unsigned or wrongly signed requests are rejected (403). Requests without a
Postal signature header are left to other transports.

## Sending through the API

```
MAUTIC_MAILER_DSN=postal+api://API_KEY@postal.example.com
```

`API_KEY` is an API credential of the Postal server (*Server → Credentials
→ API*). The transport posts the message Mautic built to
`/api/v1/send/raw` (base64, envelope sender and recipients), so all headers
arrive unchanged. Postal's error codes become mailer errors
(`Postal rejected the message: InvalidServerAPIKey`), Postal's message ID
is set on the sent message.

| DSN | |
|---|---|
| `postal+api://KEY@postal.example.com` | HTTPS on 443 |
| `postal+api://KEY@postal.example.com:8443` | another port |
| `postal+api://KEY@postal-web:5000?tls=false` | plain HTTP (internal network) |

## Configuration

| Parameter | Env (kopexa image) | |
|---|---|---|
| `postal_jwks_url` | `MAUTIC_POSTAL_JWKS_URL` | e.g. `https://postal.example.com/.well-known/jwks.json` (cached, default 1 h) |
| `postal_webhook_public_key` | `MAUTIC_POSTAL_WEBHOOK_PUBLIC_KEY` | PEM, takes precedence over the JWKS URL |

In Postal: *Server → Webhooks → Add webhook*, URL
`https://<mautic>/mailer/callback`, events **MessageBounced** and
**MessageDeliveryFailed**.

If egress is restricted (`networkPolicy.egress`), allow Mautic to reach the
Postal host on 443 for the JWKS, or configure the PEM instead.

Full setup, endpoint behaviour and troubleshooting: [docs/postal.md](https://github.com/kopexa-grc/mautic-stack/blob/main/docs/postal.md).

## Development

- `bin/check.sh` checks the package (PHP lint, `composer validate`, version
  consistency, self-test). CI runs it on every push and pull request.
- `tests/functional/run.sh` installs the plugin into a real Mautic 7 and
  tests it over HTTP; CI runs it on PHP 8.4 and 8.5.
- End-to-end tests also run in
  [kopexa-grc/mautic-stack](https://github.com/kopexa-grc/mautic-stack): its
  smoke test installs this package into the Mautic image and exercises it
  end to end.
- **Commits** follow [Conventional Commits](https://www.conventionalcommits.org)
  (`feat:`, `fix:`, `docs:`, `chore:` …); they drive versions and the changelog.
- **Releases** are automated with
  [release-please](https://github.com/googleapis/release-please): it keeps a
  release PR open that bumps `VERSION`, the plugin version and
  `CHANGELOG.md`. Merging it tags `v<version>` and creates the GitHub
  release. Packagist and mautic-stack's Renovate pick the tag up.
- **Dependencies** are kept current by Renovate, once a month
  (security fixes immediately). Composer constraint updates
  are committed as `fix(deps):` and so lead to a patch release; CI-only
  updates (`ci:`) do not.



The Postal logo (`Assets/img/icon.png`) comes from
[postalserver/postal](https://github.com/postalserver/postal) under the MIT
licence (see `Assets/img/NOTICE`). This plugin is not affiliated with the
Postal project.
