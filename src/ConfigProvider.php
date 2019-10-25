<?php

namespace Smtm\Zfx\Db;

class ConfigProvider
{
    /**
     * Retrieve zend-db default configuration.
     *
     * @return array
     */
    public function __invoke()
    {
        return [
            'dependencies' => $this->getDependencyConfig(),
        ];
    }

    /**
     * Retrieve zend-db default dependency configuration.
     *
     * @return array
     */
    public function getDependencyConfig()
    {
        return [
            'abstract_factories' => [
                Adapter\AdapterAbstractServiceFactory::class,
            ],
            'factories' => [
                Adapter\AdapterInterface::class => Adapter\AdapterServiceFactory::class,
            ],
            'aliases' => [
                Adapter\Adapter::class => Adapter\AdapterInterface::class,
            ],
        ];
    }
}
