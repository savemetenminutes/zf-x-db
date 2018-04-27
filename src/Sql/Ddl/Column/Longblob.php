<?php

namespace Smtm\Zfx\Db\Sql\Ddl\Column;

use Zend\Db\Sql\Ddl\Column\AbstractLengthColumn;

class Longblob extends AbstractLengthColumn
{
    /**
     * @var string Change type to longblob
     */
    protected $type = 'LONGBLOB';

    /**
     * {@inheritDoc}
     */
    public function __construct($name, $nullable = false, $default = null, array $options = [])
    {
        parent::__construct($name, null, $nullable, $default, $options);
    }
}
