<?php
namespace Smtm\Zfx\Db\ResultSet;

use Countable;
use Iterator;

use Smtm\Zfx\Db\ResultSet\Exception\InvalidMethodException;
use Smtm\Zfx\Db\TableGateway\RelationalTableGatewayInterface;

use Zend\Db\ResultSet\ResultSetInterface;

class AggregateHydratingResultSet implements Countable, Iterator, ResultSetInterface, RelationalTableGatewayInterface
{
    protected $resultSets;
    protected $entitiesResultMap;
    protected $activeResultSetIndex;

    public function __construct(array &$resultSets = [], array &$entitiesResultMap = [], int $activeResultSetIndex = 0)
    {
        $this->resultSets = $resultSets;
        $this->entitiesResultMap = $entitiesResultMap;

        $this->setActiveResultSetIndex($activeResultSetIndex);
    }

    public function setActiveResultSetIndexFromEntity($entity):AggregateHydratingResultSet
    {
        $entityClass = $this->getEntityClass($entity);
        if(!isset($this->entitiesResultMap[$entityClass][self::RESULT_SET_INDEX])) {
            throw new \Exception('Cannot set active result set index. Invalid entity: '.$entityClass, 0);
        }
        $entityResultSetIndex = $this->entitiesResultMap[$entityClass][self::RESULT_SET_INDEX];

        return $this->setActiveResultSetIndex($entityResultSetIndex);
    }

    public function setActiveResultSetIndex(int $activeResultSetIndex):AggregateHydratingResultSet
    {
        if(!isset($this->resultSets[$activeResultSetIndex])) {
            throw new \Exception('Cannot set active result set index. Invalid index: '.$activeResultSetIndex, 0);
        }
        $this->activeResultSetIndex = $activeResultSetIndex;

        return $this;
    }

    protected function getActiveResultSetIndex():int
    {
        return $this->activeResultSetIndex;
    }

    public function getEntityResult($entity)
    {
        $entityClass = $this->getEntityClass($entity);
        if(!$this->resultSets[$this->entitiesResultMap[$entityClass][self::RESULT_SET_INDEX]][self::RESULT_SET_PROTOTYPE][$entityClass]->count()) {
            return new $entityClass;
        }

        return $this->resultSets[$this->entitiesResultMap[$entityClass][self::RESULT_SET_INDEX]][self::RESULT_SET_PROTOTYPE][$entityClass]->current();
    }

    public function getActiveResultSet()
    {
        return $this->resultSets[$this->getActiveResultSetIndex()][self::RESULT_SET_RESULT];
    }

    public function getActiveResultSetDataSource()
    {
        if(is_array($this->getActiveResultSet()) || !method_exists($this->getActiveResultSet(), 'getDataSource')) {
            return $this->getActiveResultSet();
        }

        return $this->getActiveResultSet()->getDataSource();
    }

    protected function getEntityClass($entity):string
    {
        $entityClass = $entity;
        if(is_object($entity)) {
            $entityClass = get_class($entity);
        }

        return $entityClass;
    }

    public function initialize($dataSource)
    {
    }

    public function count()
    {
        return $this->getActiveResultSet()->count();
    }

    public function getFieldCount()
    {
        return $this->getActiveResultSet()->getFieldCount();
    }

    public function current()
    {
        return $this;
    }

    public function key()
    {
        return $this->getActiveResultSet()->key();
    }

    public function next()
    {
        $this->getActiveResultSet()->next();
    }

    public function rewind()
    {
        $this->getActiveResultSet()->rewind();
    }

    public function valid()
    {
        return $this->getActiveResultSet()->valid();
    }

    public function toArray()
    {
        return $this->getActiveResultSet()->toArray();
    }
}