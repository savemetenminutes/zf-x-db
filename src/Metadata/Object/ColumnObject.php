<?php

namespace Smtm\Zfx\Db\Metadata\Object;

use Zend\Db\Metadata\Object\ColumnObject as Zf_ColumnObject;
use Zend\Db\Sql\Ddl\Column\ColumnInterface as Zf_ColumnInterface;

class ColumnObject extends Zf_ColumnObject
{
    /**
     *
     * @var bool
     */
    protected $autoIncrement = null;

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

    /**
     * @return bool $isNullable
     */
    public function getAutoIncrement()
    {
        return $this->autoIncrement;
    }

    /**
     * @param bool $autoIncrement to set
     * @return ColumnObject
     */
    public function setAutoIncrement($autoIncrement)
    {
        $this->autoIncrement = $autoIncrement;
        return $this;
    }

    public function toZend_Db_Sql_Ddl_Column_ColumnInterface():Zf_ColumnInterface {
        switch($this->getDataType()) {
            case 'int':
                $options = [];
                $options['length'] = $this->getNumericPrecision();
                $options['unsigned'] = $this->getNumericUnsigned();
                $options['zerofill'] = null; // TODO: get the zerofill option (MySQL specific)
                $options['autoincrement'] = $this->getAutoIncrement(); // TODO: get the column autoincrement
                $options['comment'] = null; // TODO: get the column comment
                $options['format'] = null; // TODO: get the column format
                $options['storage'] = null; // TODO: figure out what this option is for
                return new \Zend\Db\Sql\Ddl\Column\Integer($this->getName(), $this->getIsNullable(), $this->getColumnDefault(), $options);
                break;
            case 'bigint':
                $options = [];
                $options['length'] = $this->getNumericPrecision();
                $options['unsigned'] = $this->getNumericUnsigned();
                $options['zerofill'] = null; // TODO: get the zerofill option (MySQL specific)
                $options['autoincrement'] = null; // TODO: get the column autoincrement
                $options['comment'] = null; // TODO: get the column comment
                $options['format'] = null; // TODO: get the column format
                $options['storage'] = null; // TODO: figure out what this option is for
                return new \Zend\Db\Sql\Ddl\Column\BigInteger($this->getName(), $this->getIsNullable(), $this->getColumnDefault(), $options);
                break;
            case 'char':
                $options = [];
                $options['length'] = $this->getCharacterMaximumLength();
                $options['comment'] = null; // TODO: get the column comment
                $options['format'] = null; // TODO: get the column format
                $options['storage'] = null; // TODO: figure out what this option is for
                return new \Zend\Db\Sql\Ddl\Column\Char($this->getName(), $this->getCharacterMaximumLength(), $this->getIsNullable(), $this->getColumnDefault(), $options);
                break;
            case 'varchar':
                $options = [];
                $options['length'] = $this->getCharacterMaximumLength();
                $options['comment'] = null; // TODO: get the column comment
                $options['format'] = null; // TODO: get the column format
                $options['storage'] = null; // TODO: figure out what this option is for
                return new \Zend\Db\Sql\Ddl\Column\Varchar($this->getName(), $this->getCharacterMaximumLength(), $this->getIsNullable(), $this->getColumnDefault(), $options);
                break;
            case 'text':
                $options = [];
                $options['length'] = $this->getCharacterMaximumLength();
                $options['comment'] = null; // TODO: get the column comment
                $options['format'] = null; // TODO: get the column format
                $options['storage'] = null; // TODO: figure out what this option is for
                return new \Zend\Db\Sql\Ddl\Column\Text($this->getName(), $this->getCharacterMaximumLength(), $this->getIsNullable(), $this->getColumnDefault(), $options);
                break;
            case 'blob':
                $options = [];
                $options['comment'] = null; // TODO: get the column comment
                $options['format'] = null; // TODO: get the column format
                $options['storage'] = null; // TODO: figure out what this option is for
                return new \Smtm\Zfx\Db\Sql\Ddl\Column\Blob($this->getName(), $this->getCharacterMaximumLength(), $this->getIsNullable(), $this->getColumnDefault(), $options);
                break;
            case 'longblob':
                $options = [];
                $options['comment'] = null; // TODO: get the column comment
                $options['format'] = null; // TODO: get the column format
                $options['storage'] = null; // TODO: figure out what this option is for
                return new \Smtm\Zfx\Db\Sql\Ddl\Column\Longblob($this->getName(), $this->getIsNullable(), $this->getColumnDefault(), $options);
                break;
            case 'datetime':
                $options = [];
                $options['comment'] = null; // TODO: get the column comment
                $options['format'] = null; // TODO: get the column format
                $options['storage'] = null; // TODO: figure out what this option is for
                return new \Smtm\Zfx\Db\Sql\Ddl\Column\Datetime($this->getName(), $this->getIsNullable(), $this->getColumnDefault(), $options);
                break;
        }
    }
}
