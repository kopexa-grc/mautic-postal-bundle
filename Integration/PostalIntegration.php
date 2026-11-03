<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPostalBundle\Integration;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\IntegrationsBundle\DTO\Note;
use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormNotesInterface;
use Symfony\Component\Mailer\Transport\Dsn;

/**
 * Postal under Settings → Plugins: the tile with the Postal logo opens a
 * dialog with the setup steps and the live status of this instance
 * (webhook URL, whether the webhook key is configured, which mail
 * transport is active). The bundle is configured through Mautic parameters
 * (environment / Helm values), not in this dialog.
 */
class PostalIntegration extends BasicIntegration implements BasicInterface, ConfigFormInterface, ConfigFormNotesInterface
{
    use ConfigurationTrait;
    use DefaultConfigFormTrait;

    public const NAME = 'Postal';

    public function __construct(private readonly CoreParametersHelper $parameters)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'Postal';
    }

    public function getIcon(): string
    {
        return 'plugins/KopexaPostalBundle/Assets/img/icon.png';
    }

    public function getAuthorizationNote(): ?Note
    {
        $e        = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $site     = rtrim((string) $this->parameters->get('site_url'), '/');
        $jwks     = trim((string) $this->parameters->get('postal_jwks_url'));
        $pem      = trim((string) $this->parameters->get('postal_webhook_public_key'));
        $keyOk    = '' !== $jwks || '' !== $pem;
        $key      = '' !== $pem ? 'public key (PEM) configured' : ('' !== $jwks ? 'JWKS: '.$e($jwks) : '<strong>not configured</strong> – set <code>postal_jwks_url</code> (Helm: <code>mautic.postal.jwksUrl</code>) or <code>postal_webhook_public_key</code>; until then Postal webhooks are refused (503)');

        return new Note(
            '<div><p><strong>Postal is configured through the environment (Helm values), not in this dialog.</strong> The switch below has no effect.</p>'
            .'<p><strong>1. Bounces</strong> – in Postal: <em>Server → Webhooks → Add webhook</em><br>'
            .'URL: <code>'.$e(('' !== $site ? $site : 'https://&lt;mautic&gt;').'/mailer/callback').'</code><br>'
            .'Events: <code>MessageBounced</code> and <code>MessageDeliveryFailed</code><br>'
            .'Webhook key: '.($keyOk ? '✓ '.$key : '✗ '.$key).'</p>'
            .'<p><strong>2. Sending</strong> – current transport: '.$this->transport().'<br>'
            .'Through Postal\'s HTTP API: mailer DSN <code>postal+api://API_KEY@postal.example.com</code> '
            .'(API credential from <em>Server → Credentials → API</em>; <code>?tls=false</code> for plain HTTP inside the network). '
            .'The sender address must belong to a domain verified in Postal.</p>'
            .'<p>Details: <a href="https://github.com/kopexa-grc/mautic-postal-bundle#readme" target="_blank" rel="noopener">README</a></p></div>',
            $keyOk ? Note::TYPE_INFO : Note::TYPE_WARNING
        );
    }

    public function getFeaturesNote(): ?Note
    {
        return null;
    }

    public function getFieldMappingNote(): ?Note
    {
        return null;
    }

    /** Scheme and host of the mailer DSN, never credentials. */
    private function transport(): string
    {
        $dsn = trim((string) $this->parameters->get('mailer_dsn'));
        if ('' === $dsn) {
            return 'not configured';
        }
        try {
            $parsed = Dsn::fromString($dsn);
        } catch (\Throwable) {
            return 'invalid mailer DSN';
        }
        $where = htmlspecialchars($parsed->getHost().(null !== $parsed->getPort() ? ':'.$parsed->getPort() : ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return match (true) {
            'postal+api' === $parsed->getScheme() => '✓ Postal API (<code>'.$where.'</code>)',
            str_starts_with($parsed->getScheme(), 'smtp') => 'SMTP (<code>'.$where.'</code>)',
            default => '<code>'.htmlspecialchars($parsed->getScheme(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>',
        };
    }
}
