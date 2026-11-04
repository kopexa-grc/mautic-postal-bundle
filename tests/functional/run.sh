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

echo "Sending through Postal's HTTP API (postal+api://)"
# A stand-in for Postal: records requests, answers like Postal's /api/v1/send/raw.
MOCK_PORT=$((PORT + 18))
cat >"$WORK/postal-mock.php" <<'PHP'
<?php
$log = getenv('POSTAL_MOCK_LOG');
$body = file_get_contents('php://input');
file_put_contents($log, json_encode(['path' => $_SERVER['REQUEST_URI'], 'key' => $_SERVER['HTTP_X_SERVER_API_KEY'] ?? null, 'body' => json_decode($body, true)])."\n", FILE_APPEND);
header('Content-Type: application/json');
if ('good-key' !== ($_SERVER['HTTP_X_SERVER_API_KEY'] ?? '')) {
    echo json_encode(['status' => 'error', 'time' => 0.01, 'flags' => [], 'data' => ['code' => 'InvalidServerAPIKey', 'message' => 'The API token provided in X-Server-API-Key was not valid.']]);
    return;
}
$rcpts = json_decode($body, true)['rcpt_to'] ?? [];
echo json_encode(['status' => 'success', 'time' => 0.01, 'flags' => [], 'data' => ['message_id' => 'mock-123@postal.test', 'messages' => array_fill_keys($rcpts, ['id' => 1, 'token' => 'abc'])]]);
PHP
(POSTAL_MOCK_LOG="$WORK/postal-requests.jsonl" php -S "127.0.0.1:$MOCK_PORT" "$WORK/postal-mock.php" >"$WORK/mock.log" 2>&1 &)
for _ in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:$MOCK_PORT/" && break; sleep 0.5; done
: >"$WORK/postal-requests.jsonl"
set_params "{\"mailer_dsn\": \"postal+api://good-key@127.0.0.1:$MOCK_PORT?tls=false\", \"messenger_dsn_email\": \"sync://\", \"mailer_from_email\": \"news@example.com\", \"mailer_from_name\": \"Kopexa\"}"
to="$(api -X POST "$BASE/api/contacts/new" -d email=api@example.com -d firstname=Api | jq -r .contact.id)"
eid="$(api -X POST "$BASE/api/emails/new" -d name='Postal API test' -d subject='Postal API test' -d emailType=template -d isPublished=1 \
  --data-urlencode 'customHtml=<html><body><p>Hallo {contactfield=firstname}</p></body></html>' | jq -r .email.id)"
sent="$(api -X POST "$BASE/api/emails/$eid/contact/$to/send")"
req="$(tail -1 "$WORK/postal-requests.jsonl")"
raw="$(jq -r '.body.data' <<<"$req" | base64 -d)"
jq -e '.success == 1 or .success == true' <<<"$sent" >/dev/null \
  && jq -e '.path == "/api/v1/send/raw" and .key == "good-key" and .body.rcpt_to == ["api@example.com"] and .body.mail_from == "news@example.com"' <<<"$req" >/dev/null \
  && grep -q '^Subject: Postal API test' <<<"$raw" && grep -q 'Hallo Api' <<<"$raw" \
  && pass "Mautic sends through /api/v1/send/raw: API key header, envelope, the full message with rendered tokens" \
  || { echo "$sent"; echo "$req" | head -c 600; fail "postal+api send"; }
php -r '
  require $argv[1]."/vendor/autoload.php";
  $t = (new MauticPlugin\KopexaPostalBundle\Mailer\Transport\PostalTransportFactory(null, Symfony\Component\HttpClient\HttpClient::create()))
       ->create(Symfony\Component\Mailer\Transport\Dsn::fromString("postal+api://bad-key@127.0.0.1:".$argv[2]."?tls=false"));
  try { $t->send((new Symfony\Component\Mime\Email())->from("news@example.com")->to("x@example.com")->subject("x")->text("x")); echo "sent"; }
  catch (Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) { echo $e->getMessage(); }
' "$MAUTIC_DIR" "$MOCK_PORT" >"$WORK/bad.txt" 2>&1 || true
grep -q 'Postal rejected the message: InvalidServerAPIKey' "$WORK/bad.txt" && pass "Postal errors become transport errors (InvalidServerAPIKey)" || { cat "$WORK/bad.txt"; fail "error handling"; }

echo "Setup in the plugin dialog (no key in Mautic's configuration)"
set_params '{"postal_webhook_public_key": "", "postal_jwks_url": ""}'
login >/dev/null
ajax_csrf="$(curl -s -b "$JAR" -c "$JAR" "$BASE/s/plugins" | sed -nE "s/.*mauticAjaxCsrf *= *'([^']+)'.*/\1/p" | head -1)"
dialog() { # prints the dialog HTML; the form token to $WORK/dialog-token
  curl -s -b "$JAR" -c "$JAR" -H 'X-Requested-With: XMLHttpRequest' "$BASE/s/integration/Postal/config" \
    | php -r '$d = json_decode(stream_get_contents(STDIN), true); $h = (string) ($d["newContent"] ?? "");
        preg_match("/<input[^>]*name=\"integration_config\[_token\]\"[^>]*>/", $h, $m); preg_match("/value=\"([^\"]*)\"/", $m[0] ?? "", $v);
        file_put_contents($argv[1], $v[1] ?? ""); echo $h;' "$WORK/dialog-token"
}
save() { # <published 1|0> <jwks url> <pem file>: prints the response JSON
  dialog >/dev/null
  curl -s -b "$JAR" -c "$JAR" -H 'X-Requested-With: XMLHttpRequest' -H "X-CSRF-Token: $ajax_csrf" -X POST "$BASE/s/integration/Postal/config" \
    --data-urlencode "integration_config[_token]=$(cat "$WORK/dialog-token")" --data-urlencode "integration_config[isPublished]=$1" \
    --data-urlencode "integration_config[apiKeys][jwks_url]=$2" --data-urlencode "integration_config[apiKeys][public_key]@$3" \
    --data-urlencode "integration_config[buttons][save]="
}
pub="$WORK/postal.pub"; openssl rsa -in "$key" -pubout -out "$pub" 2>/dev/null
[[ "$(post "$bounce" "$key")" == 503 ]] && grep -q 'Not set up yet' <<<"$(dialog)" && ! grep -qi 'helm' <<<"$(dialog)" \
  && pass "without a key: webhooks refused (503), the dialog says what to do (no deployment-specific wording)" || { cat "$WORK/resp"; fail "unconfigured state"; }
[[ -n "$ajax_csrf" ]] || fail "no mauticAjaxCsrf on the plugins page"
resp="$(save 1 'http://postal.example.com/.well-known/jwks.json' /dev/null)"
grep -q 'Use an https:' <<<"$resp" \
  && pass "dialog: the key URL must be https" || { echo "$resp" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? substr(strip_tags(json_encode(array_map(fn ($v) => is_string($v) ? substr($v, 0, 300) : $v, $d))), 0, 1500) : "not JSON";'; fail "http key URL accepted"; }
api -X POST "$BASE/api/contacts/$cid/dnc/email/remove" >/dev/null
save 1 '' "$pub" | grep -q closeModal || fail "saving the dialog"
[[ "$(post "$bounce" "$key")" == 200 ]] && jq -e '.handled == true' "$WORK/resp" >/dev/null \
  && [[ "$(api "$BASE/api/contacts/$cid" | jq -r '.contact.doNotContact[0].reason')" == 2 ]] && grep -q 'Bounces are processed with the key below' <<<"$(dialog)" \
  && pass "key entered and switched on in the dialog: bounces processed" || { cat "$WORK/resp"; fail "dialog setup"; }
save 0 '' "$pub" | grep -q closeModal || fail "switching off"
[[ "$(post "$bounce" "$key")" == 503 ]] && grep -q 'Switched off' <<<"$(dialog)" \
  && pass "switched off: webhooks refused, the dialog says so" || { cat "$WORK/resp"; fail "switch off"; }
set_params "$(jq -n --arg k "$(cat "$pub")" '{postal_webhook_public_key: $k}')"
[[ "$(post "$bounce" "$key")" == 200 ]] && grep -q "configuration (parameters" <<<"$(dialog)" \
  && pass "a key in Mautic's configuration takes precedence (switch not needed)" || { cat "$WORK/resp"; fail "configuration precedence"; }
echo "all Postal checks passed"
