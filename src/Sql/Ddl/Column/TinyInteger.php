<?php

namespace Smtm\Zfx\Db\Sql\Ddl\Column;

use Zend\Db\Sql\Ddl\Column\Column;

class TinyInteger extends Column
{
    /**
     * @return array
     */
    public function getExpressionData()
    {
        $data    = parent::getExpressionData();
        $options = $this->getOptions();

        if (isset($options['length'])) {
            $data[0][1][1] .= '(' . $options['length'] . ')';
        }

        return $data;
    }
}