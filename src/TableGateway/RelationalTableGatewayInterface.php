<?php

namespace Smtm\Zfx\Db\TableGateway;

interface RelationalTableGatewayInterface
{
    const ADAPTER = 'adapter';
    const SCHEMA = 'schema';
    const TABLE = 'table';
    const COLUMNS = 'columns';
    const ENTITY = 'entity';
    const RELATES = 'relates';
    const INTER_ADAPTER_RELATES = 'inter_adapter_relates';
    const ON = 'on';
    const HYDRATOR = 'hydrator';
    const NAMING_STRATEGY = 'naming_strategy';
    const SELECT = 'select';
    const WHERE = 'where';
    const EQUAL = 'equal';
    const NOT_EQUAL = 'not_equal';
    const IN = 'in';
    const NOT_IN = 'not_in';
    const GREATER_THAN = 'greater_than';
    const GREATER_OR_EQUAL_THAN = 'greater_or_equal_than';
    const LESS_THAN = 'less_than';
    const LESS_OR_EQUAL_THAN = 'less_or_equal_than';
    const IS_NULL = 'is_null';
    const IS_NOT_NULL = 'is_not_null';
    const GROUP = 'group';

    const TABLE_IDENTIFIER = 'table_identifier';
    const TABLE_GATEWAY = 'table_gateway';
    const SQL = 'sql';
    const TABLES = 'tables';

    const RESULT_SET_DEFINITIONS = 'definitions';
    const RESULT_SET_PROTOTYPE = 'result_prototype';
    const RESULT_SET_RESULT = 'result';
    const RESULT_SET_INDEX = 'result_index';
}