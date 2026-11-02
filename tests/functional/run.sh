#!/usr/bin/env bash
# Functional test: Postal bounce webhooks in a real Mautic 7.
#   tests/functional/run.sh    (see tests/functional/lib.sh for the environment)
# shellcheck source=tests/functional/lib.sh
source "$(dirname "$0")/lib.sh"

install_mautic
key="$WORK/postal.pem"; openssl genrsa -out "$key" 2048 2>/dev/null
set_params "$(jq -n --arg k "$(openssl rsa -in "$key" -pubout 2>/dev/null)" '{postal_webhook_public_key: $k}')"
start_server

echo "Postal bounce webhooks"
cid="$(api -X POST "$BASE/api/contacts/new" -d email=bounce@example.com | jq -r .contact.id)"
[[ "$cid" =~ ^[0-9]+$ ]] || fail "create contact"
post() { # <body> <signing key>
  local sig; sig="$(printf '%s' "$1" | openssl dgst -sha256 -sign "$2" | base64 -w0)"
  curl -s -o "$WORK/resp" -w '%{http_code}' -X POST "$BASE/mailer/callback" -H 'Content-Type: application/json' \
    -H "X-Postal-Signature-256: $sig" --data-binary "$1"
}
bounce='{"event":"MessageBounced","payload":{"original_message":{"direction":"outgoing","to":"bounce@example.com"}}}'
other="$WORK/other.pem"; openssl genrsa -out "$other" 2048 2>/dev/null
[[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/mailer/callback" -d '{}')" == 404 ]] && pass "unsigned request left to other transports" || fail "unsigned request"
[[ "$(post "$bounce" "$other")" == 403 ]] && pass "wrong signature rejected" || fail "wrong signature accepted"
[[ "$(post "$bounce" "$key")" == 200 ]] && jq -e '.handled == true' "$WORK/resp" >/dev/null && pass "signed bounce accepted" || { cat "$WORK/resp"; fail "signed bounce"; }
[[ "$(api "$BASE/api/contacts/$cid" | jq -r '.contact.doNotContact[0].reason')" == 2 ]] && pass "contact marked Do Not Contact: bounced" || fail "contact not marked"
[[ "$(post '{"event":"MessageDelayed","payload":{"message":{"direction":"outgoing","to":"bounce@example.com"},"status":"SoftFail"}}' "$key")" == 200 ]] \
  && jq -e '.handled == false' "$WORK/resp" >/dev/null && pass "soft failures ignored" || fail "soft failure"
echo "all Postal checks passed"
