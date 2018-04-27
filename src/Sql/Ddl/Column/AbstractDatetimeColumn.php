<?php

namespace Smtm\Zfx\Db\Sql\Ddl\Column;

use Zend\Db\Sql\Ddl\Column\Column;

/**
 * Class AbstractDatetimeColumn
 * @package ZendExtended\Db\Sql\Ddl\Column
 */
abstract class AbstractDatetimeColumn extends Column
{
    const CURRENT_TIMESTAMP = 'CURRENT_TIMESTAMP';
    /**
     * @return array
     */
    public function getExpressionData()
    {
        $spec = $this->specification;

        $params   = [];
        $params[] = $this->name;
        $params[] = $this->type;

        $types = [self::TYPE_IDENTIFIER, self::TYPE_LITERAL];

        if (!$this->isNullable) {
            $spec .= ' NOT NULL';
        }

        if ($this->default !== null) {
            if($this->default == self::CURRENT_TIMESTAMP) {
                $spec     .= ' DEFAULT %s';
                $params[] = $this->default;
                $types[]  = self::TYPE_LITERAL;
            } else {
                $spec     .= ' DEFAULT %s';
                $params[] = $this->default;
                $types[]  = self::TYPE_VALUE;
            }
        }

        $options = $this->getOptions();

        if (isset($options['on_update'])) {
            $spec    .= ' %s';
            $params[] = 'ON UPDATE CURRENT_TIMESTAMP';
            $types[]  = self::TYPE_LITERAL;
        }

        $data = [[
            $spec,
            $params,
            $types,
        ]];

        foreach ($this->constraints as $constraint) {
            $data[] = ' ';
            $data = array_merge($data, $constraint->getExpressionData());
        }

        return $data;
    }
}
