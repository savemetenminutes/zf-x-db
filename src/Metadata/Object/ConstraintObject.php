<?php

namespace Smtm\Zfx\Db\Metadata\Object;

use Zend\Db\Metadata\Object\ConstraintObject as Zfx_ConstraintObject;
use Zend\Db\Sql\Ddl\Constraint\ConstraintInterface as Zfx_ConstraintInterface;

class ConstraintObject extends Zfx_ConstraintObject
{
    /**
     * Constructor
     *
     * @param string $name
     * @param string $tableName
     * @param string $schemaName
     */
    public function __construct($name, $tableName, $schemaName = null)
    {
        parent::__construct($name, $tableName, $schemaName);
    }

    public function toZend_Db_Sql_Ddl_Constraint_ConstraintInterface():Zfx_ConstraintInterface {
        switch($this->getType()) {
            case 'PRIMARY KEY':
                $options = [];
                $options['comment'] = null; // TODO: get the column comment
                $options['format'] = null; // TODO: get the column format
                $options['storage'] = null; // TODO: figure out what this option is for
                return new \Zend\Db\Sql\Ddl\Constraint\PrimaryKey($this->getColumns(), $this->getName());
                break;
        }
    }
}
