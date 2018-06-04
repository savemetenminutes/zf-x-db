<?php

namespace Smtm\Zfx\Db\TableGateway;

use Smtm\Zfx\Db\Sql\Sql;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\ResultSet\ResultSetInterface;
use Zend\Db\TableGateway\TableGateway as ZendDbTableGateway;

class TableGateway extends ZendDbTableGateway
{
    public function __construct(
        $table,
        AdapterInterface $adapter,
        $features = null,
        ?ResultSetInterface $resultSetPrototype = null,
        ?Sql $sql = null
    ) {
        parent::__construct($table, $adapter, $features, $resultSetPrototype, $sql);
        $this->sql = ($sql) ?: new Sql($this->adapter, $this->table);
    }
}
