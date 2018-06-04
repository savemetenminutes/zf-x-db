<?php

namespace Smtm\Zfx\Db\Sql;

use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Sql\Platform\AbstractPlatform as ZendDbSqlAbstractPlatform;
use Zend\Db\Sql\Sql as ZendDbSql;

class Sql extends ZendDbSql
{
    public function __construct(
        AdapterInterface $adapter,
        $table = null,
        ?ZendDbSqlAbstractPlatform $sqlPlatform = null
    ) {
        parent::__construct($adapter, $table, $sqlPlatform);
        $this->sqlPlatform = $sqlPlatform ?: new Platform\Platform($adapter);
    }
}
