<?php

namespace Smtm\Zfx\Db\Metadata\Source;

use Smtm\Zfx\Db\Metadata\Object;
use Zend\Db\Metadata\Source\SqlServerMetadata as Zf_SqlServerMetadata;

class SqlServerMetadata extends Zf_SqlServerMetadata
{
    /**
     * {@inheritdoc}
     */
    public function getColumns($table, $schema = null)
    {
        if ($schema === null) {
            $schema = $this->defaultSchema;
        }

        $this->loadColumnData($table, $schema);

        $columns = [];
        foreach ($this->getColumnNames($table, $schema) as $columnName) {
            $columns[] = $this->getColumn($columnName, $table, $schema);
        }
        return $columns;
    }

    /**
     * {@inheritdoc}
     */
    public function getColumn($columnName, $table, $schema = null)
    {
        if ($schema === null) {
            $schema = $this->defaultSchema;
        }

        $this->loadColumnData($table, $schema);

        if (!isset($this->data['columns'][$schema][$table][$columnName])) {
            throw new \Exception('A column by that name was not found.');
        }

        $info = $this->data['columns'][$schema][$table][$columnName];

        $column = new Object\ColumnObject($columnName, $table, $schema);
        $props = [
            'ordinal_position', 'column_default', 'is_nullable',
            'data_type', 'character_maximum_length', 'character_octet_length',
            'numeric_precision', 'numeric_scale', 'numeric_unsigned',
            'erratas'
        ];
        foreach ($props as $prop) {
            if (isset($info[$prop])) {
                $column->{'set' . str_replace('_', '', $prop)}($info[$prop]);
            }
        }

        $column->setOrdinalPosition($info['ordinal_position']);
        $column->setColumnDefault($info['column_default']);
        $column->setIsNullable($info['is_nullable']);
        $column->setDataType($info['data_type']);
        $column->setCharacterMaximumLength($info['character_maximum_length']);
        $column->setCharacterOctetLength($info['character_octet_length']);
        $column->setNumericPrecision($info['numeric_precision']);
        $column->setNumericScale($info['numeric_scale']);
        $column->setNumericUnsigned($info['numeric_unsigned']);
        $column->setErratas($info['erratas']);

        return $column;
    }

    /**
     * {@inheritdoc}
     */
    public function getConstraints($table, $schema = null)
    {
        if ($schema === null) {
            $schema = $this->defaultSchema;
        }

        $this->loadConstraintData($table, $schema);

        $constraints = [];
        foreach (array_keys($this->data['constraints'][$schema][$table]) as $constraintName) {
            $constraints[] = $this->getConstraint($constraintName, $table, $schema);
        }

        return $constraints;
    }

    /**
     * {@inheritdoc}
     */
    public function getConstraint($constraintName, $table, $schema = null)
    {
        if ($schema === null) {
            $schema = $this->defaultSchema;
        }

        $this->loadConstraintData($table, $schema);

        if (!isset($this->data['constraints'][$schema][$table][$constraintName])) {
            throw new \Exception('Cannot find a constraint by that name in this table');
        }

        $info = $this->data['constraints'][$schema][$table][$constraintName];
        $constraint = new Object\ConstraintObject($constraintName, $table, $schema);

        foreach ([
                     'constraint_type'         => 'setType',
                     'match_option'            => 'setMatchOption',
                     'update_rule'             => 'setUpdateRule',
                     'delete_rule'             => 'setDeleteRule',
                     'columns'                 => 'setColumns',
                     'referenced_table_schema' => 'setReferencedTableSchema',
                     'referenced_table_name'   => 'setReferencedTableName',
                     'referenced_columns'      => 'setReferencedColumns',
                     'check_clause'            => 'setCheckClause',
                 ] as $key => $setMethod) {
            if (isset($info[$key])) {
                $constraint->{$setMethod}($info[$key]);
            }
        }

        return $constraint;
    }
}
