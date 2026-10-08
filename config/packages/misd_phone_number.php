<?php

declare(strict_types=1);

use libphonenumber\PhoneNumberFormat;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('misd_phone_number', [
        'twig' => [
            'enabled' => true,
        ],
        'form' => [
            'enabled' => true,
        ],
        'serializer' => [
            'enabled' => true,
            'default_region' => 'US',
            'format' => PhoneNumberFormat::E164,
        ],
        'validator' => [
            'enabled' => true,
            'default_region' => 'US',
            'format' => PhoneNumberFormat::INTERNATIONAL,
        ],
    ]);
};
