<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPostalBundle\Integration;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\IntegrationsBundle\DTO\Note;
use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormAuthInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormNotesInterface;
use MauticPlugin\KopexaPostalBundle\Form\Type\PostalKeysType;
use MauticPlugin\KopexaPostalBundle\Service\PostalSettings;
use Symfony\Component\Mailer\Transport\Dsn;

/**
 * Postal under Settings → Plugins: the tile with the Postal logo opens a
 * dialog with the setup steps, the live status of this instance and the
 * field for Postal's key. Installations configured as code can set the
 * Mautic parameters instead (see PostalSettings).
 */
class PostalIntegration extends BasicIntegration implements BasicInterface, ConfigFormInterface, ConfigFormAuthInterface, ConfigFormNotesInterface
{
    use ConfigurationTrait;
    use DefaultConfigFormTrait;

    public const NAME = 'Postal';

    public function __construct(private readonly CoreParametersHelper $parameters)
    {
    }

    public function getAuthConfigFormName(): string
    {
        return PostalKeysType::class;
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
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $site = rtrim((string) $this->parameters->get('site_url'), '/');
        $url  = ('' !== $site ? $e($site) : 'https://&lt;your-mautic&gt;').'/mailer/callback';
        $config = $this->hasIntegrationConfiguration() ? $this->getIntegrationConfiguration() : null;
        $key    = PostalSettings::resolveFrom($this->parameters, (bool) $config?->getIsPublished(), (array) ($config?->getApiKeys() ?? []));

        $status = match ($key['source']) {
            PostalSettings::SOURCE_CONFIGURATION => '✓ Bounces are processed. The key is set in Mautic\'s configuration file (<code>postal_jwks_url</code> / <code>postal_webhook_public_key</code>); it takes precedence over the fields below.',
            PostalSettings::SOURCE_PLUGIN        => '✓ Bounces are processed with the key below ('.('' !== $key['pem'] ? 'public key' : $e($key['jwks'])).').',
            default                              => match (true) {
                $key['enabled']  => '✗ No key yet: enter the Postal key URL below and save. Until then Mautic refuses Postal\'s webhooks.',
                $key['stored']   => '✗ Switched off: Mautic refuses Postal\'s webhooks. Switch the plugin on and save.',
                default          => '✗ Not set up yet: enter the Postal key URL below, switch the plugin on and save.',
            },
        };

        return new Note(
            '<div>'
            .'<p><strong>1. Bounces</strong> – Postal reports bounces to Mautic through a webhook; Mautic then stops mailing that address.</p>'
            .'<p>In Postal: open your mail server → <em>Webhooks</em> → <em>Add webhook</em><br>'
            .'URL: <code>'.$url.'</code><br>'
            .'Events: <code>MessageBounced</code> and <code>MessageDeliveryFailed</code></p>'
            .'<p>Below: the key URL of your Postal server, usually <code>https://&lt;your-postal&gt;/.well-known/jwks.json</code>. Switch the plugin on and save.<br>'
            .'Status: '.$status.'</p>'
            .'<p><strong>2. Sending through Postal (optional)</strong> – current mail transport: '.$this->transport().'<br>'
            .'In Mautic: <em>Configuration → Email Settings</em>, mail transport (DSN): scheme <code>postal+api</code>, host your Postal server, user an API key '
            .'(Postal: mail server → <em>Credentials</em> → <em>Add credential</em>, type <em>API</em>). '
            .'The sender address must belong to a domain verified in Postal.</p>'
            .'<p>More: <a href="https://github.com/kopexa-grc/mautic-postal-bundle#readme" target="_blank" rel="noopener">documentation</a></p>'
            .'</div>',
            null !== $key['source'] ? Note::TYPE_INFO : Note::TYPE_WARNING
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
