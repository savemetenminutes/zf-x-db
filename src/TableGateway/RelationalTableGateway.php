<?php

namespace Smtm\Zfx\Db\TableGateway;

use ArrayObject;
use Interop\Container\ContainerInterface;
use Smtm\Zfx\Db\Sql\Ddl\CreateTable as Zfx_CreateTable;
use Smtm\Zfx\Db\Sql\Ddl\AlterTable as Zfx_AlterTable;
use Smtm\Zfx\Db\Sql\Ddl\TruncateTable as Zfx_TruncateTable;
use Smtm\Zfx\Db\Sql\Platform\Mysql\Ddl\CreateTableDecorator as Zfx_MysqlCreateTableDecorator;
use Smtm\Zfx\Db\Sql\Platform\Mysql\Ddl\AlterTableDecorator as Zfx_MysqlAlterTableDecorator;
use Smtm\Zfx\Db\TableGateway\Column\DeselectColumn;
use Smtm\Zfx\Hydrator\AliasesToClassMethodsHydrator;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Adapter\Exception\InvalidQueryException;
use Zend\Db\ResultSet\HydratingResultSet;
use Zend\Db\ResultSet\ResultSetInterface;
use Zend\Db\Sql\Ddl\CreateTable;
use Zend\Db\Sql\TableIdentifier;
use Zend\Db\TableGateway\TableGatewayInterface;
use Zend\Hydrator\ArraySerializable;
use Zend\Hydrator\ClassMethods;
use Zend\Hydrator\HydratorAwareInterface;
use Zend\Hydrator\HydratorAwareTrait;
use Zend\Hydrator\HydratorInterface;

class RelationalTableGateway implements RelationalTableGatewayInterface, HydratorAwareInterface, HydratorInterface
{
    use HydratorAwareTrait;

    protected $entityManager;
    protected $tableSuffix;
    protected $adapterDefinitions;
    protected $entityDefinitions;
    protected $entityRelations;
    protected $baseTableIdentifiers;
    protected $tableIdentifiers;
    protected $baseTableGateways;
    protected $tableGateways;

    public function __construct(
        ContainerInterface $entityManager,
        HydratorInterface $hydrator = null,
        array $options = null,
        AdapterInterface ...$adapters
    ) {
        $this->setEntityManager($entityManager);

        if ($hydrator !== null) {
            $this->setHydrator($hydrator);
        }

        if (($options !== null) && array_key_exists(self::TABLE_SUFFIX, $options)) {
            $this->tableSuffix = $options[self::TABLE_SUFFIX] ?? '';
        }

        $entityDefinitions = defined('static::ENTITY_DEFINITIONS') ? static::ENTITY_DEFINITIONS : [];
        $entityRelations   = defined('static::ENTITY_RELATIONS') ? static::ENTITY_RELATIONS : [];
        $this->setEntityDefinitions($entityDefinitions)->setEntityRelations($entityRelations)->initializeAdapterDefinitions($adapters);
    }

    protected function initializeAdapterDefinitions($adapters)
    {
        foreach ($adapters as $index => $adapter) {
            $this->adapterDefinitions[$index][self::ADAPTER] = $adapter;

            $tables                                                = array_filter($this->getEntityDefinitions(),
                function ($item) use ($index) {
                    return $item[self::ADAPTER] === $index;
                });
            $tables                                                = array_column($tables, self::TABLE);
            $this->adapterDefinitions[$index][self::TABLES]        = $tables;
            $this->adapterDefinitions[$index][self::TABLE_GATEWAY] = new TableGateway($tables,
                $this->adapterDefinitions[$index][self::ADAPTER]);
            // Because there is no cross-platform (nor a platform-specific for that matter) support for things like CREATE TABLE ... LIKE and TRUNCATE TABLE we have to do all this...
            $this->adapterDefinitions[$index][self::TABLE_GATEWAY]->getSql()->getSqlPlatform()->setTypeDecorator(Zfx_CreateTable::class,
                new Zfx_MysqlCreateTableDecorator()); // this practically does nothing... yet...
            $this->adapterDefinitions[$index][self::TABLE_GATEWAY]->getSql()->getSqlPlatform()->setTypeDecorator(CreateTable::class,
                new Zfx_MysqlCreateTableDecorator()); // the default key needs to be overwritten with the new decorator as the \Zend\Db\Sql\Platform\Platform::getDecorator() method checks whether Smtm\Zfx\Db\Sql\Platform\Mysql\Ddl\CreateTableDecorator is an instance of Zend\Db\Sql\Ddl\CreateTable (which we ultimately extend) which as a key is stored and iterated through earlier in the decorators collection
        }

        return $this;
    }

    public function post($entity, TableGatewayInterface $tableGateway = null)
    {
        if (!is_object($entity)) {
            throw new \Exception('An object must be provided in order to persist a record. (' . gettype($entity) . ' provided)');
        }

        $tableGateway = $tableGateway ?? $this->getTableGateway($entity);
        $values       = $this->extract($entity);
        $tableGateway->insert($values);

        $lastInsertSequenceColumn = [
            $this->getInsertSequenceColumn($entity) => $tableGateway->getLastInsertValue(),
        ];

        return $this->hydrate($lastInsertSequenceColumn, $entity);
    }

    public function get(array $query = [])
    {
        if (empty($query[self::SELECT])) {
            throw new \Exception('No tables selected', 1);
        }

        $entity           = key($query[self::SELECT]);
        $fromTableGateway = $this->getTableGateway($entity);
        $select           = $fromTableGateway->getSql()->select();
        foreach ($query[self::SELECT] as $entity => $columns) {
            $selectColumns = $this->getSelectColumns($query, $entity);
            $select->columns($selectColumns);
        }
        $this->processQueryConditions($select, $query);
        return $fromTableGateway->selectWith($select);
    }

    public function decorateResultSet($resultSet, $entity): ResultSetInterface
    {
        if ($resultSet instanceof HydratingResultSet) {
            if ($entity instanceof ArrayObject) {
                return
                    $resultSet
                        ->setHydrator(new ArraySerializable())
                        ->setObjectPrototype($entity);
            }
            $entityDefinition = $this->getEntityDefinition($entity);
            if ($this->getEntityManager()->has($entityDefinition[self::ENTITY])) {
                $table                      = $entityDefinition[self::TABLE];
                $entity                     = is_object($entity) ? $entity : $this->getEntityManager()->get($entity);
                $entityColumns              = $this->extract($entity);
                $entityColumnsTablePrefixed = [];
                array_walk(
                    $entityColumns,
                    function ($element, $key) use ($table, &$entityColumnsTablePrefixed) {
                        $entityColumnsTablePrefixed[$table . '_' . $key] = $key;
                    }
                );
                $hydrator = new AliasesToClassMethodsHydrator(
                    true,
                    [],
                    $entityColumnsTablePrefixed
                );

                $resultSet->setHydrator($hydrator);
            }
            $resultSet->setObjectPrototype($entity);
        }

        return $resultSet;
    }

    public function get_old(array $query = [], bool $leadTransaction = true)
    {
        if (empty($query[self::SELECT])) {
            throw new \Exception('No tables selected', 1);
        }

        $entities = $this->findAllInvolvedEntities($query);

        $allChains                   = $this->buildRelationsChains($query, $entities);
        $chains                      = $allChains['entityChains'];
        $mergedChains                = $allChains['mergedChains'];
        $interAdapterChainsRelations = $allChains['interAdapterChainsRelations'];
        $adapterChainsPrioritized    = $allChains['adapterChainsPrioritized'];

        // Process the cross-adapter queries from the last one backwards and prepare the target adapter query where clause values
        $key        = null;
        $resultSets = [];
        foreach ($adapterChainsPrioritized as $key => $adapterChain) {
            $resultSets[]          = [];
            $currentResultSetIndex = count($resultSets) - 1;

            // Prepare the select from... table
            $fromEntityDefinition = reset($adapterChain);
            $fromEntity           = key($adapterChain);
            $fromTableIdentifier  = new TableIdentifier($fromEntityDefinition[self::TABLE],
                $fromEntityDefinition[self::SCHEMA]);
            $fromTableGateway     = new TableGateway($fromTableIdentifier,
                $this->adapters[$fromEntityDefinition[self::ADAPTER]]);

            $select = $fromTableGateway->getSql()->select();

            $selectColumns = $this->getSelectColumns($query, $fromEntity, $fromEntityDefinition,
                $resultSets[$currentResultSetIndex]);
            $this->processInterAdapterJoinLogic($fromEntity, $fromEntityDefinition, $interAdapterChainsRelations, $key,
                $selectColumns, $resultSets, $currentResultSetIndex, $result ?? null);
            $select->columns($selectColumns);

            // Prepare the join tables
            $previousEntityChain     = [];
            $previousTableIdentifier = null;
            foreach ($adapterChain as $entity => $entityDefinition) {
                $previousChainObjects    = $this->getPreviousChainObjects($chains, $entity);
                $previousEntityChain     = $previousChainObjects['previousEntityChain'];
                $previousTableIdentifier = $previousChainObjects['previousTableIdentifier'];
                // We have already processed the first table as a select from... table so skip to the next one
                if ($entity === $fromEntity) {
                    continue;
                }

                $tableIdentifier   = new TableIdentifier($entityDefinition[self::TABLE],
                    $entityDefinition[self::SCHEMA]);
                $joinSelectColumns = $this->getSelectColumns($query, $entity, $entityDefinition,
                    $resultSets[$currentResultSetIndex]);
                $select->join($tableIdentifier,
                    $previousTableIdentifier->getTable() . '.' . key($entityDefinition[self::ON]) . '=' . $tableIdentifier->getTable() . '.' . reset($entityDefinition[self::ON]),
                    $joinSelectColumns);
            }

            $this->processQueryConditions($select, $query, $chains, $adapterChain);

            try {
                $result = $this->executeChainQuery($select, $fromTableGateway, $leadTransaction);
            } catch (InvalidQueryException $e) {
                var_dump($select->getSqlString());
                die();
            }

            $resultSets[$currentResultSetIndex][self::RESULT_SET_RESULT] = &$result;
            $entitiesResultsMap                                          = [];
            foreach ($adapterChain as $entity => $entityDefinition) {
                if (isset($resultSets[$currentResultSetIndex][self::RESULT_SET_PROTOTYPE][$entity])) {
                    $currentHydrator = $resultSets[$currentResultSetIndex][self::RESULT_SET_PROTOTYPE][$entity]->getHydrator();
                    if ($currentHydrator instanceof ClassMethods) {
                        $resultSets[$currentResultSetIndex][self::RESULT_SET_PROTOTYPE][$entity]->setHydrator((new AliasesToClassMethodsHydrator($currentHydrator->getUnderscoreSeparatedKeys(),
                            $resultSets[$currentResultSetIndex][self::RESULT_SET_DEFINITIONS][$entity][self::COLUMNS]))->setNamingStrategy($currentHydrator->getNamingStrategy()));
                    }
                    $resultSets[$currentResultSetIndex][self::RESULT_SET_PROTOTYPE][$entity]->initialize($result->getDataSource());

                    $entitiesResultsMap[$entity][self::RESULT_SET_INDEX] = $key;
                }
            }
        }

        if ($key === null) {
            throw new \Exception('The query does not resolve to valid results.', 0);
        }
        // Automatically set the last result index as the active result index in the AggregateHydratingResultSet
        return new AggregateHydratingResultSet($resultSets, $entitiesResultsMap, $key);
    }

    public function findAllInvolvedEntities($query)
    {
        // Find all entities involved in the operation
        $entities = [];
        foreach ($query as $queryClause => $queryValues) {
            if ($queryClause == self::WHERE) {
                foreach ($queryValues as $whereType => $whereValues) {
                    foreach ($whereValues as $entity => $whereValues) {
                        $entities[$entity] = $entity;
                    }
                }
            } else {
                foreach ($queryValues as $entity => $queryValues) {
                    $entities[$entity] = $entity;
                }
            }
        }

        return $entities;
    }

    public function buildRelationsChains($query, $entities)
    {
        // Determine the entity join chains for each entity
        $chains       = [];
        $mergedChains = [];
        foreach ($entities as $entity => $entityRepeated) {
            $chain    = [];
            $relation = $this->findRelationChain($entity, $chain);
            if (!$relation) {
                // The entity does not figure in the relations schema
                throw new \Exception('Invalid entity: ' . $entity, 2);
            }
            $chains[$entity] = array_reverse($chain);
            $mergedChains    = array_merge($mergedChains, $chains[$entity]);
        }

        // Build an all entity encompassing chain
        $_mergedChains = $mergedChains;
        foreach ($mergedChains as $entity => $entityDefinition) {
            if (!isset($entities[$entity])) {
                array_shift($_mergedChains);
            } else {
                break;
            }
        }
        $mergedChains = $_mergedChains;

        // Create each adapter's own chain and determine the inter-adapter table relations
        $currentAdapter                = reset($mergedChains)[self::ADAPTER];
        $adapterChains[]               = [];
        $adapterChainsCount            = 1;
        $currentAdapterChainIndex      = 0;
        $interAdapterChainsRelations[] = [];
        foreach ($mergedChains as $entity => $entityDefinition) {
            if ($currentAdapter == $entityDefinition[self::ADAPTER]) {
                $adapterChains[$currentAdapterChainIndex][$entity] = $entityDefinition;
            } else {
                $interAdapterChainsRelations[$currentAdapterChainIndex][key($previousEntity)] = reset($previousEntity) + [self::INTER_ADAPTER_RELATES => [$entity => $entityDefinition]];
                $interAdapterChainsRelations[]                                                = [];
                $adapterChains[]                                                              = [$entity => $entityDefinition];
                $adapterChainsCount                                                           = count($adapterChains);
                $currentAdapterChainIndex                                                     = $adapterChainsCount - 1;
                $currentAdapter                                                               = $entityDefinition[self::ADAPTER];
            }
            $previousEntity = [$entity => $entityDefinition];
        }
        $interAdapterChainsRelations = array_reverse($interAdapterChainsRelations);
        if (count($adapterChains) <= 1) {
            // No cross-adapter joins
            $interAdapterChainsRelations = [];
        }

        // Determine the order of adapter chains resolution based on the reverse order of selected tables
        $adapterChainsPrioritized = [];
        $_adapterChains           = $adapterChains;
        foreach ($query[self::SELECT] as $entity => $querySelect) {
            if (empty($_adapterChains)) {
                break;
            }
            if (isset($adapterChainsPrioritized[count($adapterChainsPrioritized) - 1][$entity])) {
                continue;
            }
            foreach ($adapterChains as $key => $adapterChain) {
                if (isset($adapterChain[$entity])) {
                    $adapterChainsPrioritized[] = $adapterChain;
                    unset($_adapterChains[$key]);
                    break;
                }
            }
        }
        foreach ($_adapterChains as $adapterChain) {
            $adapterChainsPrioritized[] = $adapterChain;
        }
        $adapterChainsPrioritized = array_reverse($adapterChainsPrioritized);

        return [
            'entityChains'                => $chains,
            'mergedChains'                => $mergedChains,
            'interAdapterChainsRelations' => $interAdapterChainsRelations,
            'adapterChainsPrioritized'    => $adapterChainsPrioritized
        ];
    }

    protected function determineSelectColumns($query, $entity)
    {
        $queryColumns = [];
        if (is_a($query[self::SELECT][$entity], $entity)) {
            $queryColumns = $this->extract($query[self::SELECT][$entity]);
            $queryColumns =
                array_filter(
                    $queryColumns,
                    function ($item) {
                        return !($item instanceof DeselectColumn);
                    }
                );
            $queryColumns = array_keys($queryColumns);
        } else {
            if (is_array($query[self::SELECT][$entity])) {
                $queryColumns = $query[self::SELECT][$entity];
            }
        }

        return $queryColumns;
    }

    protected function processSelectColumns($queryColumns, $entity)
    {
        $entityDefinition = $this->getEntityDefinition($entity);
        $selectColumns    = [];
        foreach ($queryColumns as $columnAlias => $column) {
            $alias = $entityDefinition[self::SCHEMA] . $entityDefinition[self::TABLE] . '_';
            if (is_numeric($columnAlias)) {
                $alias .= $column;
            } else {
                $alias .= $columnAlias;
            }
            $selectColumns[$alias] = $column;
        }

        return $selectColumns;
    }

    protected function getSelectColumns($query, $entity)
    {
        $selectColumns = [];
        if (isset($query[self::SELECT][$entity])) {
            $queryColumns  = $this->determineSelectColumns($query, $entity);
            $selectColumns = $this->processSelectColumns($queryColumns, $entity);
        }

        return $selectColumns;
    }

    public function getPreviousChainObjects($chains, $entity)
    {
        $previousEntityChain     = [];
        $previousTableIdentifier = null;

        // Find the previous entity's chain
        foreach ($chains as $chain) {
            if (isset($chain[$entity])) {
                $previousEntityChain = $chain;
                break;
            }
        }
        if (count($previousEntityChain) > 1) {
            reset($previousEntityChain);
            while (((key($previousEntityChain) !== null) && (key($previousEntityChain) !== false)) && (key($previousEntityChain) !== $entity)) {
                next($previousEntityChain);
            }
            prev($previousEntityChain);
            // ...or use array_reverse
            //$previousEntityChain = array_reverse($previousEntityChain);
            //reset($previousEntityChain);
            //next($previousEntityChain);
            $previousTableName       = current($previousEntityChain)[self::TABLE];
            $previousTableSchema     = current($previousEntityChain)[self::SCHEMA];
            $previousTableIdentifier = new TableIdentifier($previousTableName, $previousTableSchema);
            reset($previousEntityChain);
        }

        return [
            'previousEntityChain'     => $previousEntityChain,
            'previousTableIdentifier' => $previousTableIdentifier,
        ];
    }

    protected function processInterAdapterJoinLogic(
        $entity,
        array $entityDefinition,
        array $interAdapterChainsRelations,
        int $currentChainKey,
        array &$selectColumns = [],
        array &$resultSets = [],
        int $currentResultSetIndex = 0,
        ResultSetInterface $previousResult = null
    ) {
        if (!empty($interAdapterChainsRelations)) {
            if (empty($interAdapterChainsRelations[$currentChainKey])) {
                // This is the column whose values will be used in the where in... clause of the subsequent adapter chain query
                $interAdapterJoinColumn = reset($entityDefinition[self::ON]);
                $alias                  = $entityDefinition[self::SCHEMA] . $entityDefinition[self::TABLE] . '_' . $interAdapterJoinColumn;
                $selectColumns[$alias]  = $interAdapterJoinColumn;
                if (!isset($resultSets[$currentResultSetIndex][self::RESULT_SET_DEFINITIONS][$entity])) {
                    $resultSets[$currentResultSetIndex][self::RESULT_SET_DEFINITIONS][$entity] = $entityDefinition;
                }
                $resultSets[$currentResultSetIndex][self::RESULT_SET_DEFINITIONS][$entity][self::COLUMNS][$alias] = $interAdapterJoinColumn;
            } else {
                if (!empty($previousResult->count())) {
                    $currentChainInterAdapterJoinColumn  =
                        reset($interAdapterChainsRelations[$currentChainKey])[self::TABLE] .
                        '.' .
                        key(reset(reset($interAdapterChainsRelations[$currentChainKey])[self::INTER_ADAPTER_RELATES])[self::ON]);
                    $previousChainInterAdapterJoinColumn = key(reset($resultSets[count($resultSets) - 2][self::RESULT_SET_DEFINITIONS])[self::COLUMNS]);
                    $select->where->in(
                        $currentChainInterAdapterJoinColumn,
                        array_column(
                            $previousResult->toArray(),
                            $previousChainInterAdapterJoinColumn
                        )
                    );
                } else {
                    $select->where->literal('0 = 1');
                }
            }
        }
    }

    /*
     * Process the where..., group by..., having..., order by..., limit... clauses
     */
    protected function processQueryConditions(&$select, $query)
    {
        foreach ($query as $clauseType => $clauseValues) {
            if ($clauseType == self::WHERE) {
                foreach ($clauseValues as $operandType => $filterValues) {
                    foreach ($filterValues as $entity => $filter) {
                        $entityDefinition = $this->getEntityDefinition($entity);
                        $tableIdentifier  = $this->getTableIdentifier($entityDefinition[self::ENTITY]);
                        $filterArray      = $filter;
                        if (is_object($filter)) {
                            if ($hydrator = $this->getHydrator()) {
                                $filterArray = $hydrator->extract($filter);
                            } else {
                                if (method_exists($filter, 'getArrayCopy')) {
                                    $filterArray = $filter->getArrayCopy();
                                } else {
                                    if (method_exists($filter, 'toArray')) {
                                        $filterArray = $filter->toArray();
                                    }
                                }
                            }
                        }

                        switch ($operandType) {
                            case self::IN:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->in($tableIdentifier->getTable() . '.' . $filterColumn,
                                            $filterValue);
                                    }
                                }
                                break;
                            case self::NOT_IN:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->notIn($tableIdentifier->getTable() . '.' . $filterColumn,
                                            $filterValue);
                                    }
                                }
                                break;
                            case self::NOT_EQUAL:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->notEqualTo($tableIdentifier->getTable() . '.' . $filterColumn,
                                            $filterValue);
                                    }
                                }
                                break;
                            case self::EQUAL:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->equalTo($tableIdentifier->getTable() . '.' . $filterColumn,
                                            $filterValue);
                                    }
                                }
                                break;
                            case self::LESS_THAN:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->lessThan($tableIdentifier->getTable() . '.' . $filterColumn,
                                            $filterValue);
                                    }
                                }
                                break;
                            case self::LESS_THAN_OR_EQUAL_TO:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->lessThanOrEqualTo($tableIdentifier->getTable() . '.' . $filterColumn,
                                            $filterValue);
                                    }
                                }
                                break;
                            case self::GREATER_THAN:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->greaterThan($tableIdentifier->getTable() . '.' . $filterColumn,
                                            $filterValue);
                                    }
                                }
                                break;
                            case self::GREATER_THAN_OR_EQUAL_TO:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->greaterThanOrEqualTo($tableIdentifier->getTable() . '.' . $filterColumn,
                                            $filterValue);
                                    }
                                }
                                break;
                            default:
                                break;
                        }
                    }
                }
            } else {
                if ($clauseType == self::GROUP) {
                    foreach ($clauseValues as $entity => $columns) {
                        $entityDefinition = $this->getEntityDefinition($entity);
                        $tableIdentifier  = $this->getTableIdentifier($entityDefinition[self::ENTITY]);
                        $groupColumns     = $columns;
                        if (!is_array($groupColumns)) {
                            $groupColumns = [$groupColumns];
                        }
                        foreach ($groupColumns as $groupColumn) {
                            $select->group($tableIdentifier->getTable() . '.' . $groupColumn);
                        }
                    }
                } else {
                    if ($clauseType == self::LIMIT) {
                        $select->limit($clauseValues);
                    } else {
                        if ($clauseType == self::OFFSET) {
                            $select->offset($clauseValues);
                        }
                    }
                }
            }
        }
    }

    protected function executeChainQuery($select, $fromTableGateway, $leadTransaction)
    {
        // Execute the current adapter chain query
        if ($leadTransaction && !($transactionStarted = $this->beginTransaction($fromTableGateway->getAdapter()))) {
            $this->rollbackTransaction($fromTableGateway->getAdapter());
            $transactionStarted = $this->beginTransaction($fromTableGateway->getAdapter());
        }
        $result = $fromTableGateway->selectWith($select);
        if ($transactionStarted) {
            $this->commitTransaction($fromTableGateway->getAdapter());
        }

        return $result;
    }

    /*
     * Recursively build the path to the target entity into an array
     */
    public function findRelationChain($findEntity, &$chain = [], $relations = null)
    {
        if (empty($this->relations)) {
            return false;
        }

        if (($relations === null) || ($relations === [])) {
            $relations = $this->relations;
        }

        foreach ($relations as $entity => $entityDefinition) {
            if ($findEntity === $entity) {
                $chain[$entity] = $entityDefinition;
                return true;
            }
            if (isset($entityDefinition[self::RELATES])) {
                if ($this->findRelationChain($findEntity, $chain, $entityDefinition[self::RELATES])) {
                    $chain[$entity] = $entityDefinition;
                    return true;
                }
            }
        }

        return false;
    }

    public function getEntityRelation($findEntity, $relations = null)
    {
        if (is_object($findEntity)) {
            $findEntity = get_class($findEntity);
        }

        if (empty($this->relations)) {
            return false;
        }

        if (($relations === null) || ($relations === [])) {
            $relations = $this->relations;
        }

        foreach ($relations as $entity => $entityDefinition) {
            if ($findEntity === $entity) {
                return $entityDefinition;
            }
            if (isset($entityDefinition[self::RELATES])) {
                $entityDefinition = $this->getEntityDefinition($findEntity, $entityDefinition[self::RELATES]);
                if (!empty($entityDefinition)) {
                    return $entityDefinition;
                }
            }
        }

        return false;
    }

    public function validateQueryValues($entity, array $nullable = [])
    {
        $values = $this->extract($entity);
        $values = array_filter(
            $values,
            function ($value, $key) use ($nullable) {
                return
                    (
                        (($value === null) && in_array($key, $nullable)) || is_scalar($item)
                    );
            },
            ARRAY_FILTER_USE_BOTH
        );
        $values = array_walk(
            $values,
            function (&$value, $key) {
                if (is_bool($value)) {
                    $value = $value ? 't' : 'f';
                }
            }
        );

        return $values;
    }

    public function hydrate(array $data, $object)
    {
        return $this->hydrator->hydrate($data, $object);
    }

    public function extract(object $object) : array
    {
        return $this->hydrator->extract($object);
    }

    public function getEntityClass($entity)
    {
        return is_object($entity) ? get_class($entity) : $entity;
    }

    /**
     * @return string
     */
    public function getTableSuffix(): string
    {
        return $this->tableSuffix ?? '';
    }

    /**
     * @param string $tableSuffix
     * @return RelationalTableGateway
     */
    public function setTableSuffix(string $tableSuffix): RelationalTableGateway
    {
        $this->tableSuffix = $tableSuffix;
        return $this;
    }

    /**
     * @param mixed $adapterIndex
     * @return AdapterInterface
     */
    public function getAdapter($adapterIndex): AdapterInterface
    {
        return $this->getAdapterDefinitions()[$adapterIndex][self::ADAPTER] ?? null;
    }

    /**
     * @param mixed $entity
     * @return AdapterInterface
     * @throws \Exception
     */
    public function getAdapterForEntity($entity): AdapterInterface
    {
        $adapterIndex = $this->getEntityDefinition($entity)[self::ADAPTER];
        return $this->getAdapter($adapterIndex) ?? null;
    }

    /**
     * @param mixed $entity
     * @return array
     * @throws \Exception
     */
    public function getAdapterDefinitionForEntity($entity): array
    {
        $adapterIndex = $this->getEntityDefinition($entity)[self::ADAPTER];
        return $this->getAdapterDefinition($adapterIndex) ?? null;
    }

    /**
     * @param mixed $adapterIndex
     * @return array
     */
    public function getAdapterDefinition($adapterIndex): array
    {
        return $this->getAdapterDefinitions()[$adapterIndex] ?? null;
    }

    /**
     * @return AdapterInterface[]
     */
    public function getAdapterDefinitions(): array
    {
        return $this->adapterDefinitions;
    }

    /**
     * @param AdapterInterface[] $adapterDefinitions
     * @return RelationalTableGateway
     */
    public function setAdapters(array $adapterDefinitions): RelationalTableGateway
    {
        $this->adapterDefinitions = $adapterDefinitions;
        return $this;
    }

    /**
     * @param mixed $entity
     * @return array
     * @throws \Exception
     */
    public function getEntityDefinition($entity): array
    {
        $entityDefinition = $this->entityDefinitions[$this->getEntityClass($entity)] ?? null;
        if ($entityDefinition === null) {
            throw new \Exception('Entity ' . $this->getEntityClass($entity) . ' not found in model.');
        }

        return $entityDefinition;
    }

    /**
     * @param mixed $entity
     * @param array $entityDefinition
     * @return RelationalTableGateway
     */
    public function setEntityDefinition($entity, array $entityDefinition): RelationalTableGateway
    {
        $this->entityDefinitions[$this->getEntityClass($entity)] = $entityDefinition;
        return $this;
    }

    /**
     * @param string $entity
     * @return TableIdentifier
     * @throws \Exception
     */
    public function getBaseTableIdentifier($entity): TableIdentifier
    {
        $entityDefinition = $this->getEntityDefinition($this->getEntityClass($entity));
        $entityTable      = $entityDefinition[self::TABLE];
        $entitySchema     = $entityDefinition[self::SCHEMA];
        if (isset($this->baseTableIdentifiers[$entitySchema ?? ''][$entityTable])) {
            return $this->baseTableIdentifiers[$entitySchema ?? ''][$entityTable];
        }

        return $this->baseTableIdentifiers[$entitySchema ?? ''][$entityTable] = new TableIdentifier($entityTable,
            $entitySchema);
    }

    /**
     * @param string $entity
     * @return TableIdentifier
     * @throws \Exception
     */
    public function getTableIdentifier($entity): TableIdentifier
    {
        $entityDefinition = $this->getEntityDefinition($this->getEntityClass($entity));
        $entityTable      = $entityDefinition[self::TABLE] . $this->getTableSuffix();
        $entitySchema     = $entityDefinition[self::SCHEMA];
        if (isset($this->tableIdentifiers[$entitySchema ?? ''][$entityTable])) {
            return $this->tableIdentifiers[$entitySchema ?? ''][$entityTable];
        }

        return $this->tableIdentifiers[$entitySchema ?? ''][$entityTable] = new TableIdentifier($entityTable,
            $entitySchema);
    }

    /**
     * @param mixed $entity
     * @return TableGatewayInterface
     * @throws \Exception
     */
    public function getBaseTableGateway($entity): TableGatewayInterface
    {
        $baseTableIdentifier = $this->getBaseTableIdentifier($entity);
        if (isset($this->baseTableGateways[$baseTableIdentifier->getSchema() ?? ''][$baseTableIdentifier->getTable()])) {
            return $this->baseTableGateways[$baseTableIdentifier->getSchema() ?? ''][$baseTableIdentifier->getTable()];
        }

        $resultSetPrototype = new HydratingResultSet($this->getHydrator(), new $entity());
        return $this->baseTableGateways[$baseTableIdentifier->getSchema() ?? ''][$baseTableIdentifier->getTable()] = new TableGateway($baseTableIdentifier,
            $this->getAdapterForEntity($entity), null, $resultSetPrototype);
    }

    /**
     * @param mixed $entity
     * @return TableGatewayInterface
     * @throws \Exception
     */
    public function getTableGateway($entity): TableGatewayInterface
    {
        $tableIdentifier = $this->getTableIdentifier($entity);
        if (isset($this->tableGateways[$tableIdentifier->getSchema() ?? ''][$tableIdentifier->getTable()])) {
            return $this->tableGateways[$tableIdentifier->getSchema() ?? ''][$tableIdentifier->getTable()];
        }

        $resultSetPrototype = new HydratingResultSet($this->getHydrator(), new $entity());
        return
            $this->tableGateways[$tableIdentifier->getSchema() ?? ''][$tableIdentifier->getTable()] =
                new TableGateway(
                    $tableIdentifier,
                    $this->getAdapterForEntity($entity),
                    null,
                    $resultSetPrototype
                );
    }

    public function getInsertSequenceColumn($entity): string
    {
        return $this->getEntityDefinition($entity)[self::INSERT_SEQUENCE_COLUMN] ?? null;
    }

    /**
     * @return array
     */
    public function getEntityDefinitions(): array
    {
        return $this->entityDefinitions;
    }

    /**
     * @param array $entityDefinitions
     * @return RelationalTableGateway
     */
    public function setEntityDefinitions(array $entityDefinitions): RelationalTableGateway
    {
        $this->entityDefinitions = $entityDefinitions;
        return $this;
    }

    /**
     * @return array
     */
    public function getEntityRelations(): array
    {
        return $this->entityRelations;
    }

    /**
     * @param array $entityRelations
     * @return RelationalTableGateway
     */
    public function setEntityRelations(array $entityRelations): RelationalTableGateway
    {
        $this->entityRelations = $entityRelations;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getEntityManager()
    {
        return $this->entityManager;
    }

    /**
     * @param mixed $entityManager
     * @return RelationalTableGateway
     */
    public function setEntityManager($entityManager)
    {
        $this->entityManager = $entityManager;
        return $this;
    }
}
