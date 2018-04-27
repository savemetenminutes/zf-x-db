<?php

namespace Smtm\Zfx\Db\Sql\Ddl\Column;

use Zend\Db\Sql\Ddl\Column\AbstractLengthColumn;

class Blob extends AbstractLengthColumn
{
    const MAX_CHARACTER_MAXIMUM_LENGTH = 65535;

    /**
     * @var string Change type to blob
     */
    protected $type = 'BLOB';

    /**
     * @param  int $length
     *
     * @return self
     */
    public function setLength($length)
    {
        $length = (int) $length;
        $this->length = (($length == self::MAX_CHARACTER_MAXIMUM_LENGTH)?null:$length);

        return $this;
    }
}
