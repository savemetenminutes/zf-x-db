<?php

namespace Smtm\Zfx\Db\Sql\Ddl;

use Smtm\Zfx\Db\Metadata\Source\Factory as Zfx_MetadataSourceFactory;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Adapter\Platform\PlatformInterface;
use Zend\Db\Adapter\Adapter as Adapter;
use Zend\Db\Sql\Ddl\CreateTable as Zf_CreateTable;

class CreateTable extends Zf_CreateTable
{
    /**
     * {@inheritDoc}
     */
    protected $specifications = [
        self::TABLE => 'CREATE %1$sTABLE %2$s%3$s (',
        self::COLUMNS  => [
            "\n    %1\$s" => [
                [1 => '%1$s', 'combinedby' => ",\n    "]
            ]
        ],
        'combinedBy' => ",",
        self::CONSTRAINTS => [
            "\n    %1\$s" => [
                [1 => '%1$s', 'combinedby' => ",\n    "]
            ]
        ],
        'statementEnd' => '%1$s',
    ];

    /**
     * @var bool
     */
    protected $isIfNotExists = false;

    /**
     * @param string $table
     * @param bool   $isTemporary
     * @param bool   $isIfNotExists
     */
    public function __construct($table = '', $isTemporary = false, $isIfNotExists = false)
    {
        $this->setIfNotExists($isIfNotExists);
        parent::__construct($table, $isTemporary);
    }

    /**
     * @param  bool $isIfNotExists
     * @return self
     */
    public function setIfNotExists($isIfNotExists)
    {
        $this->isIfNotExists = (bool) $isIfNotExists;
        return $this;
    }

    /**
     * @return bool
     */
    public function isIfNotExists()
    {
        return $this->isIfNotExists;
    }

    /**
     * @param PlatformInterface $adapterPlatform
     *
     * @return string[]
     */
    protected function processTable(PlatformInterface $adapterPlatform = null)
    {
        return [
            $this->isTemporary ? 'TEMPORARY ' : '',
            $this->isIfNotExists ? 'IF NOT EXISTS ' : '',
            $adapterPlatform->quoteIdentifier($this->table),
        ];
    }

    public function copyTableStructure(String $sourceTable, AdapterInterface $adapter) {
        // Zend Framework 3 does not support an easy 'CREATE TABLE ... LIKE' cross-platform implementation.
        $metadata = Zfx_MetadataSourceFactory::createSourceFromAdapter($adapter)->getTable($sourceTable);
        foreach($metadata->getColumns() as $column) {
            $this->addColumn($column->__toSqlDdlColumn());
        }

        foreach($metadata->getConstraints() as $constraint) {
            $this->addConstraint($constraint->__toSqlDdlConstraint());
        }
    }
}
