<?php

namespace Smtm\Zfx\Db\Metadata\Source;

use Smtm\Zfx\Db\Metadata\Object;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Metadata\Source\MysqlMetadata as Zf_MysqlMetadata;

class MysqlMetadata extends Zf_MysqlMetadata
{
    protected function loadColumnData($table, $schema)
    {
        if (isset($this->data['columns'][$schema][$table])) {
            return;
        }
        $this->prepareDataHierarchy('columns', $schema, $table);
        $p = $this->adapter->getPlatform();

        $isColumns = [
            ['CS', 'MAXLEN'],
            ['CCSA', 'CHARACTER_SET_NAME'],
            ['T', 'ENGINE'],
            ['C', 'COLLATION_NAME'],
            ['C', 'ORDINAL_POSITION'],
            ['C', 'COLUMN_DEFAULT'],
            ['C', 'IS_NULLABLE'],
            ['C', 'DATA_TYPE'],
            //['C', 'CHARACTER_MAXIMUM_LENGTH'], // https://bugs.mysql.com/bug.php?id=90685
            ['C', 'CHARACTER_OCTET_LENGTH'],
            ['C', 'NUMERIC_PRECISION'],
            ['C', 'NUMERIC_SCALE'],
            ['C', 'COLUMN_NAME'],
            ['C', 'COLUMN_TYPE'],
            ['C', 'EXTRA'],
        ];

        array_walk($isColumns, function (&$c) use ($p) { $c = $p->quoteIdentifierChain($c); });

        $sql = 'SELECT '
            // https://bugs.mysql.com/bug.php?id=90685 BEGIN
            . ' CASE '
            . ' WHEN `C`.`COLUMN_TYPE` LIKE "%text%" THEN `C`.`CHARACTER_MAXIMUM_LENGTH` DIV `CS`.`MAXLEN`'
            . ' ELSE `C`.`CHARACTER_MAXIMUM_LENGTH`'
            . ' END AS `CHARACTER_MAXIMUM_LENGTH`,'
            // https://bugs.mysql.com/bug.php?id=90685 END
            . ' ' . implode(', ', $isColumns)
            . ' FROM ' . $p->quoteIdentifierChain(['INFORMATION_SCHEMA', 'TABLES']) . 'T'
            . ' INNER JOIN ' . $p->quoteIdentifierChain(['INFORMATION_SCHEMA', 'COLUMNS']) . 'C'
            . ' ON ' . $p->quoteIdentifierChain(['T', 'TABLE_SCHEMA'])
            . '  = ' . $p->quoteIdentifierChain(['C', 'TABLE_SCHEMA'])
            . ' AND ' . $p->quoteIdentifierChain(['T', 'TABLE_NAME'])
            . '  = ' . $p->quoteIdentifierChain(['C', 'TABLE_NAME'])
            . ' LEFT JOIN ' . $p->quoteIdentifierChain(['INFORMATION_SCHEMA', 'COLLATION_CHARACTER_SET_APPLICABILITY']) . 'CCSA'
            . ' ON ' . $p->quoteIdentifierChain(['C', 'COLLATION_NAME'])
            . '  = ' . $p->quoteIdentifierChain(['CCSA', 'COLLATION_NAME'])
            . ' LEFT JOIN ' . $p->quoteIdentifierChain(['INFORMATION_SCHEMA', 'CHARACTER_SETS']) . 'CS'
            . ' ON ' . $p->quoteIdentifierChain(['CCSA', 'CHARACTER_SET_NAME'])
            . '  = ' . $p->quoteIdentifierChain(['CS', 'CHARACTER_SET_NAME'])
            . ' WHERE ' . $p->quoteIdentifierChain(['T', 'TABLE_TYPE'])
            . ' IN (\'BASE TABLE\', \'VIEW\')'
            . ' AND ' . $p->quoteIdentifierChain(['T', 'TABLE_NAME'])
            . '  = ' . $p->quoteTrustedValue($table);

        if ($schema != self::DEFAULT_SCHEMA) {
            $sql .= ' AND ' . $p->quoteIdentifierChain(['T', 'TABLE_SCHEMA'])
                . ' = ' . $p->quoteTrustedValue($schema);
        } else {
            $sql .= ' AND ' . $p->quoteIdentifierChain(['T', 'TABLE_SCHEMA'])
                . ' != \'INFORMATION_SCHEMA\'';
        }

        $sql .= ' ORDER BY '. $p->quoteIdentifierChain(['C', 'ORDINAL_POSITION']);

        $results = $this->adapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
        $columns = [];
        foreach ($results->toArray() as $row) {
            $erratas = [];
            $matches = [];
            if (preg_match('/^(?:enum|set)\((.+)\)$/i', $row['COLUMN_TYPE'], $matches)) {
                $permittedValues = $matches[1];
                if (preg_match_all("/\\s*'((?:[^']++|'')*+)'\\s*(?:,|\$)/", $permittedValues, $matches, PREG_PATTERN_ORDER)) {
                    $permittedValues = str_replace("''", "'", $matches[1]);
                } else {
                    $permittedValues = [$permittedValues];
                }
                $erratas['permitted_values'] = $permittedValues;
            }
            $columns[$row['COLUMN_NAME']] = [
                'ordinal_position'          => $row['ORDINAL_POSITION'],
                'auto_increment'            => in_array('auto_increment', explode(',', $row['EXTRA'])),
                'column_default'            => $row['COLUMN_DEFAULT'],
                'is_nullable'               => ('YES' == $row['IS_NULLABLE']),
                'data_type'                 => $row['DATA_TYPE'],
                'character_maximum_length'  => $row['CHARACTER_MAXIMUM_LENGTH'],
                'character_octet_length'    => $row['CHARACTER_OCTET_LENGTH'],
                'numeric_precision'         => $row['NUMERIC_PRECISION'],
                'numeric_scale'             => $row['NUMERIC_SCALE'],
                'numeric_unsigned'          => (false !== strpos($row['COLUMN_TYPE'], 'unsigned')),
                'erratas'                   => $erratas,
            ];
        }

        $this->data['columns'][$schema][$table] = $columns;
    }

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
            'ordinal_position', 'auto_increment', 'column_default', 'is_nullable',
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
        $column->setAutoIncrement($info['auto_increment']);
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

    public function loadCharacterSetsData()
    {
        if (isset($this->data['character_sets'])) {
            return $this->data['character_sets'];
        }
        //$this->prepareDataHierarchy('columns', $schema, $table);
        $p = $this->adapter->getPlatform();

        $isColumns = [
            ['CS', 'CHARACTER_SET_NAME'],
            ['CS', 'DEFAULT_COLLATE_NAME'],
            ['CS', 'DESCRIPTION'],
            ['CS', 'MAXLEN'],
        ];

        array_walk($isColumns, function (&$c) use ($p) { $c = $p->quoteIdentifierChain($c); });

        $sql = 'SELECT ' . implode(', ', $isColumns)
            . ' FROM ' . $p->quoteIdentifierChain(['INFORMATION_SCHEMA', 'CHARACTER_SETS']) . 'CS';

        $results = $this->adapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
        $characterSets = [];
        foreach ($results->toArray() as $row) {
            $characterSets[$row['CHARACTER_SET_NAME']] = [
                'character_set_name'        => $row['CHARACTER_SET_NAME'],
                'default_collate_name'      => $row['DEFAULT_COLLATE_NAME'],
                'description'               => $row['DESCRIPTION'],
                'maxlen'                    => $row['MAXLEN'],
            ];
        }

        return $this->data['character_sets'] = $characterSets;
    }
}
