<?php

namespace Smtm\Zfx\Db\Sql\Ddl;

use Zend\Db\Adapter\Platform\PlatformInterface;
use Zend\Db\Sql\Ddl\SqlInterface;
use Zend\Db\Sql\AbstractSql;

class TruncateTable extends AbstractSql implements SqlInterface
{
    const TABLE = 'table';

    /**
     * @var array
     */
    protected $specifications = [
        self::TABLE => 'TRUNCATE TABLE %1$s'
    ];

    /**
     * @var string
     */
    protected $table = '';

    /**
     * @param string $table
     */
    public function __construct($table = '')
    {
        $this->setTable($table);
    }

    /**
     * @param  string $name
     * @return self
     */
    public function setTable($name)
    {
        $this->table = $name;
        return $this;
    }

    protected function processTable(PlatformInterface $adapterPlatform = null)
    {
        return [$adapterPlatform->quoteIdentifier($this->table)];
    }
}
