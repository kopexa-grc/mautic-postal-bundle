<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
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
};
