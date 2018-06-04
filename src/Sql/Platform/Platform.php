<?php

namespace Smtm\Zfx\Db\Sql\Platform;

use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Sql\Platform\Platform as ZendDbSqlPlatform;

class Platform extends ZendDbSqlPlatform
{
    public function __construct(AdapterInterface $adapter)
    {
        $mariadbPlatform = new MariadbV10_2_7\MariadbV10_2_7();
        $this->decorators['mariadbv10_2_7'] = $mariadbPlatform->getDecorators();
        parent::__construct($adapter);
    }

    protected function resolvePlatformName($adapterOrPlatform)
    {
        $platformName = $this->resolvePlatform($adapterOrPlatform)->getName();
        //return str_replace([' ', '_'], '', strtolower($platformName)); // Why did they do this?
        return strtolower($platformName);
    }
}
