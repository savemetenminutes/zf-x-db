<?php

namespace Smtm\Zfx\Db\Adapter\Platform;

use Zend\Db\Adapter\Platform\Mysql;

class MariadbV10_2_7 extends Mysql
{
    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'MariaDBV10_2_7';
    }

    public function setDriver($driver)
    {
        $this->resource = $driver;
        return $this;
    }
}
