<?php
namespace Smtm\Zfx\Db\ResultSet\Exception;

class InvalidMethodException extends \BadMethodCallException
{
    const CODE_INVALID_METHOD = 1;
    const MESSAGE_INVALID_METHOD = 'smtm_zfx_db_resultset_exception_invalid_method';
}
