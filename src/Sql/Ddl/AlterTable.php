<?php

namespace Smtm\Zfx\Db\Sql\Ddl;

use Zend\Db\Adapter\Platform\PlatformInterface;
use Zend\Db\Sql\Ddl\AlterTable as Zf_AlterTable;

class AlterTable extends Zf_AlterTable
{
    const AUTO_INCREMENT = 'autoIncrement';

    /**
     * Specifications for Sql String generation
     * @var array
     */
    protected $specifications = [
        self::TABLE => "ALTER TABLE %1\$s\n",
        self::ADD_COLUMNS  => [
            "%1\$s" => [
                [1 => "ADD COLUMN %1\$s,\n", 'combinedby' => ""]
            ]
        ],
        self::CHANGE_COLUMNS  => [
            "%1\$s" => [
                [2 => "CHANGE COLUMN %1\$s %2\$s,\n", 'combinedby' => ""],
            ]
        ],
        self::DROP_COLUMNS  => [
            "%1\$s" => [
                [1 => "DROP COLUMN %1\$s,\n", 'combinedby' => ""],
            ]
        ],
        self::ADD_CONSTRAINTS  => [
            "%1\$s" => [
                [1 => "ADD %1\$s,\n", 'combinedby' => ""],
            ]
        ],
        self::DROP_CONSTRAINTS  => [
            "%1\$s" => [
                [1 => "DROP CONSTRAINT %1\$s,\n", 'combinedby' => ""],
            ]
        ],
        self::AUTO_INCREMENT  => "AUTO_INCREMENT %1\$s\n"
    ];

    /**
     * @var bool
     */
    protected $autoIncrement = null;

    /**
     * @param string $table
     * @param $autoIncrement
     */
    public function __construct($table = '', $autoIncrement = null)
    {
        $this->setAutoIncrement($autoIncrement);
        parent::__construct($table);
    }

    /**
     * @param  bool $autoIncrement
     * @return self
     */
    public function setAutoIncrement($autoIncrement)
    {
        $this->autoIncrement = is_numeric($autoIncrement)?(int) $autoIncrement:$autoIncrement;
        return $this;
    }

    /**
     * @return bool
     */
    public function getAutoIncrement()
    {
        return $this->autoIncrement;
    }

    /**
     * @param PlatformInterface $adapterPlatform
     *
     * @return string[]
     */
    /**
     * @param  string|null $key
     * @return array
     */
    public function getRawState($key = null)
    {
        $rawState = [
            self::TABLE => $this->table,
            self::ADD_COLUMNS => $this->addColumns,
            self::DROP_COLUMNS => $this->dropColumns,
            self::CHANGE_COLUMNS => $this->changeColumns,
            self::ADD_CONSTRAINTS => $this->addConstraints,
            self::DROP_CONSTRAINTS => $this->dropConstraints,
            self::AUTO_INCREMENT => $this->autoIncrement,
        ];

        return (isset($key) && array_key_exists($key, $rawState)) ? $rawState[$key] : $rawState;
    }
}
