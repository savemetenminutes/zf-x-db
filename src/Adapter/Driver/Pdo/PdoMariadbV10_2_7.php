<?php

namespace Smtm\Zfx\Db\Adapter\Driver\Pdo;

use Zend\Db\Adapter\Driver\Pdo\Pdo;

class PdoMariadbV10_2_7 extends Pdo
{
    public function getDatabasePlatformName($nameFormat = self::NAME_FORMAT_CAMELCASE)
    {
        if ($nameFormat == self::NAME_FORMAT_CAMELCASE) {
            return 'mariadbv10_2_7';
        }

        return 'MariaDBV10_2_7';
    }
}
