<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\KopexaPostalBundle\Integration\PostalIntegration;
use MauticPlugin\KopexaPostalBundle\Mailer\Transport\PostalTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $services->load('MauticPlugin\\KopexaPostalBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, ['Tests', 'DependencyInjection', 'Mailer/Transport/PostalApiTransport.php'])).'}');

    // Mailer transport postal+api:// (Postal's HTTP API instead of SMTP).
    $services->set(PostalTransportFactory::class)->tag('mailer.transport_factory');

    // Tile with the Postal logo under Settings → Plugins; its dialog shows
    // the setup steps and the live status.
    $services->get(PostalIntegration::class)
        ->tag('mautic.integration')
        ->tag('mautic.basic_integration')
        ->tag('mautic.config_integration');
    $services->alias('mautic.integration.postal', PostalIntegration::class);
};
