<?php

namespace Smtm\Zfx\Db\Adapter;

use Interop\Container\ContainerInterface;
use Zend\Db\Adapter\AdapterAbstractServiceFactory as ZendDbAdapterAbstractServiceFactory;

class AdapterAbstractServiceFactory extends ZendDbAdapterAbstractServiceFactory
{
    /**
     * Create a DB adapter
     *
     * @param  ContainerInterface $container
     * @param  string $requestedName
     * @param  array $options
     * @return Adapter
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $config = $this->getConfig($container);
        return new Adapter($config[$requestedName]);
    }

    /**
     * Get db configuration, if any
     *
     * @param  ContainerInterface $container
     * @return array
     */
    protected function getConfig(ContainerInterface $container)
    {
        if ($this->config !== null) {
            return $this->config;
        }

        if (! $container->has('config')) {
            $this->config = [];
            return $this->config;
        }

        $config = $container->get('config');
        if (! isset($config['db_x'])
            || ! is_array($config['db_x'])
        ) {
            $this->config = [];
            return $this->config;
        }

        $config = $config['db_x'];
        if (! isset($config['adapters'])
            || ! is_array($config['adapters'])
        ) {
            $this->config = [];
            return $this->config;
        }

        $this->config = $config['adapters'];
        return $this->config;
    }
}
