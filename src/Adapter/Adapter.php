<?php

namespace Smtm\Zfx\Db\Adapter;

use Zend\Db\Adapter\Adapter as ZendDbAdapter;
use Zend\Db\Adapter\Driver\DriverInterface;

class Adapter extends ZendDbAdapter
{
    protected function createDriver($parameters)
    {
        if (
            is_string($parameters['driver'])
            && class_exists($parameters['driver'])
            && in_array(DriverInterface::class, class_implements($parameters['driver']))
        ) {
            $parameters['driver'] = new $parameters['driver']($parameters);
        }

        return parent::createDriver($parameters);
    }

    protected function createPlatform(array $parameters)
    {
        return new Platform\MariadbV10_2_7($this->driver);
    }
}
