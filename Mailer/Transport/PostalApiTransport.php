<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPostalBundle\Mailer\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends through Postal's HTTP API instead of SMTP (DSN postal+api://KEY@host).
 *
 * Uses /api/v1/send/raw: the message Mautic built is sent as it is (RFC 822,
 * base64), so every header survives — List-Unsubscribe, tracking and
 * Mautic's own headers — and bounces reach Mautic through the Postal
 * webhook as before. Authentication: the server's API credential in
 * X-Server-API-Key. Postal answers HTTP 200 also on errors; the outcome is
 * "status" in the JSON.
 */
final class PostalApiTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $host,
        private readonly ?int $port,
        private readonly bool $tls,
        private readonly HttpClientInterface $client,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('postal+api://%s%s%s', $this->host, null !== $this->port ? ':'.$this->port : '', $this->tls ? '' : '?tls=false');
    }

    public function endpoint(): string
    {
        return sprintf('%s://%s%s/api/v1/send/raw', $this->tls ? 'https' : 'http', $this->host, null !== $this->port ? ':'.$this->port : '');
    }

    protected function doSend(SentMessage $message): void
    {
        $envelope = $message->getEnvelope();
        $response = $this->client->request('POST', $this->endpoint(), [
            'headers' => ['X-Server-API-Key' => $this->apiKey, 'Accept' => 'application/json'],
            'json'    => [
                'mail_from' => $envelope->getSender()->getAddress(),
                'rcpt_to'   => array_values(array_map(fn (Address $a): string => $a->getAddress(), $envelope->getRecipients())),
                'data'      => base64_encode($message->toString()),
            ],
        ]);

        try {
            $status = $response->getStatusCode();
            $body   = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach Postal at '.$this->endpoint().': '.$e->getMessage(), $response, 0, $e);
        } catch (DecodingExceptionInterface $e) {
            throw new HttpTransportException(sprintf('Postal answered HTTP %d without JSON (is %s the Postal web host?)', $status, $this->endpoint()), $response, 0, $e);
        }

        if ('success' !== ($body['status'] ?? null)) {
            $data   = is_array($body['data'] ?? null) ? $body['data'] : [];
            $reason = (string) ($data['code'] ?? $body['status'] ?? 'unknown error');
            $detail = (string) ($data['message'] ?? '');
            throw new HttpTransportException(sprintf('Postal rejected the message: %s%s (HTTP %d)', $reason, '' !== $detail ? ' – '.$detail : '', $status), $response);
        }

        $messages = is_array($body['data']['messages'] ?? null) ? $body['data']['messages'] : [];
        if ([] === $messages && [] !== $envelope->getRecipients()) {
            throw new HttpTransportException('Postal accepted the request but created no message', $response);
        }
        if (is_scalar($body['data']['message_id'] ?? null)) {
            $message->setMessageId((string) $body['data']['message_id']);
        }
        $message->appendDebug(sprintf('Postal: %s', json_encode($messages, JSON_UNESCAPED_SLASHES)));
    }
}
