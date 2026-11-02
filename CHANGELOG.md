# Changelog

## [0.1.1](https://github.com/kopexa-grc/mautic-postal-bundle/compare/v0.1.0...v0.1.1) (2026-11-02)


### Bug fixes

* require Mautic ^7.2, the versions this plugin is tested with ([77641c2](https://github.com/kopexa-grc/mautic-postal-bundle/commit/77641c29e1e378401ec7bf7592809d665f7807b3))
* require PHP ~8.4, the versions this plugin is tested on ([f8fb2b0](https://github.com/kopexa-grc/mautic-postal-bundle/commit/f8fb2b0cedab7cdbe0782a145548161c59464f80))

## 0.1.0 – 2026-11-01

- Postal webhooks on Mautic's `/mailer/callback` route.
- Signatures are verified with RSA-SHA256, using either a fixed public key or Postal's JWKS (cached).
- Contacts are marked *Do Not Contact – bounced* on `MessageBounced` and on hard `MessageDeliveryFailed`.
- Requests without a Postal signature are left to other transports.
