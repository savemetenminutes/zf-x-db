<?php

namespace Smtm\Zfx\Db\Sql\Ddl\Column;

use Smtm\Zfx\Db\Sql\Ddl\Column\AbstractDatetimeColumn as Zfx_AbstractDatetimeColumn;

class Datetime extends Zfx_AbstractDatetimeColumn
{
    /**
     * @var string
     */
    protected $type = 'DATETIME';

    /**
     * @var bool
     */
    protected $isDefaultTimestamp = false;
}
