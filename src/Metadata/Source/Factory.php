<?php

namespace Smtm\Zfx\Db\Metadata\Source;

use Zend\Db\Adapter\Adapter;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Exception\InvalidArgumentException;
use Zend\Db\Metadata\MetadataInterface;

/**
 * Source metadata factory.
 */
class Factory
{
    /**
     * Create source from adapter
     *
     * @param  Adapter $adapter
     * @return MetadataInterface
     * @throws InvalidArgumentException If adapter platform name not recognized.
     */
    public static function createSourceFromAdapter(AdapterInterface $adapter)
    {
        $platformName = $adapter->getPlatform()->getName();

        switch ($platformName) {
            case 'MariaDBV10_2_7':
                return new MariadbV10_2_7Metadata($adapter);
            case 'MySQL':
                return new MysqlMetadata($adapter);
            case 'SQLServer':
                return new SqlServerMetadata($adapter);
            case 'SQLite':
                return new SqliteMetadata($adapter);
            case 'PostgreSQL':
                return new PostgresqlMetadata($adapter);
            case 'Oracle':
                return new OracleMetadata($adapter);
            default:
                throw new InvalidArgumentException("Unknown adapter platform '{$platformName}'");
        }
    }
}
