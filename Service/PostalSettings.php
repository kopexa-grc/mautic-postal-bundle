<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPostalBundle\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\KopexaPostalBundle\Integration\PostalIntegration;

/**
 * Where the key for Postal's webhook signatures comes from:
 *
 * 1. Mautic's configuration (parameters postal_webhook_public_key /
 *    postal_jwks_url, e.g. in config/local.php or the environment) — for
 *    installations configured as code; it takes precedence and applies
 *    whether the plugin is switched on or not;
 * 2. otherwise the fields of the plugin dialog (Settings → Plugins →
 *    Postal), stored encrypted by Mautic, used only while the plugin is
 *    switched on.
 */
class PostalSettings
{
    public const SOURCE_CONFIGURATION = 'configuration';
    public const SOURCE_PLUGIN        = 'plugin';

    /** @var array{source: ?string, jwks: string, pem: string, enabled: bool, stored: bool}|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly CoreParametersHelper $parameters,
        private readonly IntegrationsHelper $integrations,
    ) {
    }

    public function jwksUrl(): string
    {
        return $this->resolve()['jwks'];
    }

    public function publicKey(): string
    {
        return $this->resolve()['pem'];
    }

    /** self::SOURCE_CONFIGURATION, self::SOURCE_PLUGIN or null (no key). */
    public function source(): ?string
    {
        return $this->resolve()['source'];
    }

    /** Whether the plugin is switched on in its dialog. */
    public function isEnabled(): bool
    {
        return $this->resolve()['enabled'];
    }

    public function isConfigured(): bool
    {
        return null !== $this->source();
    }

    /** @return array{source: ?string, jwks: string, pem: string, enabled: bool, stored: bool} */
    private function resolve(): array
    {
        if (null !== $this->resolved) {
            return $this->resolved;
        }

        $enabled = false;
        $fields  = [];
        try {
            $config  = $this->integrations->getIntegration(PostalIntegration::NAME)->getIntegrationConfiguration();
            $enabled = (bool) $config->getIsPublished();
            $fields  = (array) ($config->getApiKeys() ?? []);
        } catch (\Throwable) {
            // Not registered yet (before mautic:plugins:reload): only the configuration counts.
        }

        return $this->resolved = self::resolveFrom($this->parameters, $enabled, $fields);
    }

    /**
     * The precedence rule on its own, shared with the plugin dialog (which
     * must not depend on this service: Mautic builds integrations inside
     * IntegrationsHelper).
     *
     * @param array<string, mixed> $fields the dialog's fields (decrypted)
     *
     * @return array{source: ?string, jwks: string, pem: string, enabled: bool, stored: bool}
     */
    public static function resolveFrom(CoreParametersHelper $parameters, bool $enabled, array $fields): array
    {
        $pem  = trim((string) $parameters->get('postal_webhook_public_key'));
        $jwks = trim((string) $parameters->get('postal_jwks_url'));
        if ('' !== $pem || '' !== $jwks) {
            return ['source' => self::SOURCE_CONFIGURATION, 'jwks' => $jwks, 'pem' => $pem, 'enabled' => $enabled, 'stored' => true];
        }

        $pem  = trim((string) ($fields['public_key'] ?? ''));
        $jwks = trim((string) ($fields['jwks_url'] ?? ''));
        if ($enabled && ('' !== $pem || '' !== $jwks)) {
            return ['source' => self::SOURCE_PLUGIN, 'jwks' => $jwks, 'pem' => $pem, 'enabled' => true, 'stored' => true];
        }

        return ['source' => null, 'jwks' => '', 'pem' => '', 'enabled' => $enabled, 'stored' => '' !== $pem || '' !== $jwks];
    }
}
