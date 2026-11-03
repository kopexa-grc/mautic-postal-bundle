<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPostalBundle\Mailer\Transport;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * postal+api://API_KEY@postal.example.com          HTTPS on 443
 * postal+api://API_KEY@postal-web.postal:5000?tls=false   plain HTTP, e.g. inside the cluster
 *
 * API_KEY is a server API credential (Postal: Server → Credentials → API).
 */
final class PostalTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if ('postal+api' !== $dsn->getScheme()) {
            throw new UnsupportedSchemeException($dsn, 'postal', $this->getSupportedSchemes());
        }
        $host = $dsn->getHost();
        if ('' === $host || 'default' === $host) {
            throw new InvalidArgumentException('postal+api needs the Postal host, e.g. postal+api://KEY@postal.example.com');
        }
        $tls = !in_array(strtolower((string) $dsn->getOption('tls', 'true')), ['0', 'false', 'no', 'off'], true);

        return new PostalApiTransport($this->getUser($dsn), $host, $dsn->getPort(), $tls, $this->client ?? HttpClient::create(), $this->dispatcher, $this->logger);
    }

    protected function getSupportedSchemes(): array
    {
        return ['postal+api'];
    }
}
