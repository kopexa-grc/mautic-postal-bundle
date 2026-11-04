# kopexa/mautic-postal-bundle

[![Packagist](https://img.shields.io/packagist/v/kopexa/mautic-postal-bundle)](https://packagist.org/packages/kopexa/mautic-postal-bundle) [![ci](https://github.com/kopexa-grc/mautic-postal-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/kopexa-grc/mautic-postal-bundle/actions/workflows/ci.yml)

[Postal](https://docs.postalserver.io) integration for Mautic 7:

- **Bounces:** bounces and hard delivery failures reported by Postal mark
  the contact as *Do Not Contact – bounced*, so Mautic stops mailing dead
  addresses.
- **Sending through Postal's HTTP API** instead of SMTP (optional).

## Installation

Requires **Mautic 7.2+** (Composer-based installation) and **PHP 8.4+**:

```bash
composer require kopexa/mautic-postal-bundle
php bin/console mautic:plugins:reload
```

## Setup

Everything happens in Mautic and Postal; the dialog under
*Settings → Plugins → Postal* shows each step with the values of your
instance and whether it is working.

### 1. Bounces

1. In Postal, open your mail server → *Webhooks* → *Add webhook*:
   - URL: `https://<your-mautic>/mailer/callback`
   - Events: **MessageBounced** and **MessageDeliveryFailed**
2. In Mautic, *Settings → Plugins → Postal*: enter the key URL of your
   Postal server, `https://<your-postal>/.well-known/jwks.json`, switch the
   plugin on and save.

Postal signs every webhook; Mautic checks the signature with that key and
refuses webhooks while the plugin is switched off or has no key. If Mautic
cannot reach your Postal server, paste its public key (PEM) instead of the
URL.

### 2. Sending through Postal (optional)

In Postal, create an API key: mail server → *Credentials* → *Add
credential*, type *API*. In Mautic, *Configuration → Email Settings*, set
the mail transport (DSN):

| Field | Value |
|---|---|
| Scheme | `postal+api` |
| Host | your Postal server, e.g. `postal.example.com` |
| User | the API key |
| Port | only if not 443 |
| Options | `tls=false` only for plain HTTP inside your own network |

As one string: `postal+api://API_KEY@postal.example.com`. The sender
address must belong to a domain verified in Postal. Mautic's message is
posted unchanged to Postal's `/api/v1/send/raw`; Postal's errors show up as
mail errors (e.g. `Postal rejected the message: InvalidServerAPIKey`).

## How it works

Postal sends webhooks to Mautic's transport callback endpoint
`POST /mailer/callback`. The plugin

1. verifies `X-Postal-Signature-256` (RSA-SHA256 over the body) with the
   Postal server's key (JWKS, cached for an hour, or a PEM);
2. handles `MessageBounced` and `MessageDeliveryFailed` (status `HardFail`)
   through Mautic's transport callback (DNC reason *bounced*);
3. ignores all other events (sent, delayed, held, opens, clicks).

Wrongly signed requests are rejected (403); requests without a Postal
signature are left to other mail transports.

## Configuration as code

For installations that keep their settings in files, the key can also be
set as Mautic parameters, e.g. in `config/local.php`:

| Parameter | |
|---|---|
| `postal_jwks_url` | key URL of the Postal server |
| `postal_webhook_public_key` | public key (PEM), takes precedence over the URL |
| `postal_jwks_cache_ttl` | seconds to cache the JWKS (default 3600) |

A key set this way takes precedence over the dialog and applies whether
the plugin is switched on or not; the dialog says so.

## Development

- `bin/check.sh` checks the package (PHP lint, `composer validate`, version
  consistency, self-test). CI runs it on every push and pull request.
- `tests/functional/run.sh` installs the plugin into a real Mautic 7 and
  tests it over HTTP; CI runs it on PHP 8.4 and 8.5.
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
