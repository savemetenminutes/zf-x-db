<?php

namespace Smtm\Zfx\Db\TableGateway;

use Smtm\Zfx\Db\ResultSet\AggregateHydratingResultSet;
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
use Zend\Db\Sql\Insert;
use Zend\Db\Sql\TableIdentifier;
use Zend\Db\TableGateway\TableGateway;
use Zend\Hydrator\ClassMethods;

class RelationalTableGateway implements RelationalTableGatewayInterface
{
    protected $adapterDefinitions;
    protected $entityDefinitions;
    protected $entityRelations;

    public function __construct(AdapterInterface ...$adapters)
    {
        $entityDefinitions = defined('static::ENTITY_DEFINITIONS') ? static::ENTITY_DEFINITIONS : [];
        $entityRelations = defined('static::ENTITY_RELATIONS') ? static::ENTITY_RELATIONS : [];
        $this->setEntityDefinitions($entityDefinitions)->setEntityRelations($entityRelations)->initializeAdapterDefinitions($adapters)->initializeTableIdentifiers();
    }

    protected function initializeAdapterDefinitions($adapters)
    {
        foreach($adapters as $index => $adapter) {
            $this->adapterDefinitions[$index][self::ADAPTER] = $adapter;

            $tables = array_filter($this->getEntityDefinitions(), function($item) use($index) {
                return $item[self::ADAPTER] === $index;
            });
            $tables = array_column($tables, self::TABLE);
            $this->adapterDefinitions[$index][self::TABLES] = $tables;
            $this->adapterDefinitions[$index][self::TABLE_GATEWAY] = new TableGateway($tables, $this->adapterDefinitions[$index][self::ADAPTER]);
            // Because there is no cross-platform (nor a platform-specific for that matter) support for things like CREATE TABLE ... LIKE and TRUNCATE TABLE we have to do all this...
            $this->adapterDefinitions[$index][self::TABLE_GATEWAY]->getSql()->getSqlPlatform()->setTypeDecorator(Zfx_CreateTable::class, new Zfx_MysqlCreateTableDecorator()); // this practically does nothing... yet...
            $this->adapterDefinitions[$index][self::TABLE_GATEWAY]->getSql()->getSqlPlatform()->setTypeDecorator(CreateTable::class, new Zfx_MysqlCreateTableDecorator()); // the default key needs to be overwritten with the new decorator as the \Zend\Db\Sql\Platform\Platform::getDecorator() method checks whether Smtm\Zfx\Db\Sql\Platform\Mysql\Ddl\CreateTableDecorator is an instance of Zend\Db\Sql\Ddl\CreateTable (which we ultimately extend) which as a key is stored and iterated through earlier in the decorators collection
        }

        return $this;
    }

    protected function initializeTableIdentifiers()
    {
        foreach($this->getEntityDefinitions() as $entity => $entityDefinition) {
            $this->setTableIdentifier($entity, new TableIdentifier($entityDefinition[self::TABLE], $entityDefinition[self::SCHEMA]));
        }

        return $this;
    }

    public function post($entity)
    {
        if(!is_object($entity) && class_exists($entity)) {
            $entity = new $entity;
        }

        $entityDefinition = $this->getEntityDefinition($entity);
        if(empty($entityDefinition)) {
            throw new \Exception('Entity '.$entityDefinition[self::ENTITY].' not found in relations model.');
        }
        $adapterDefinition = $this->getAdapterDefinition($entityDefinition[self::ADAPTER]);
        $columns = $this->extractEntity($entity);
        $insert = new Insert($entityDefinition[self::TABLE_IDENTIFIER]);
        $insert->columns($columns);
    }

    public function get(array $query = [], bool $leadTransaction = true)
    {
        if(empty($query[self::SELECT])) {
            throw new \Exception('No tables selected', 1);
        }

        $entities = $this->findAllInvolvedEntities($query);

        $allChains = $this->buildRelationsChains($query, $entities);
        $chains = $allChains['entityChains'];
        $mergedChains = $allChains['mergedChains'];
        $interAdapterChainsRelations = $allChains['interAdapterChainsRelations'];
        $adapterChainsPrioritized = $allChains['adapterChainsPrioritized'];

        // Process the cross-adapter queries from the last one backwards and prepare the target adapter query where clause values
        $key = null;
        $resultSets = [];
        foreach($adapterChainsPrioritized as $key => $adapterChain) {
            $resultSets[] = [];
            $currentResultSetIndex = count($resultSets) - 1;

            // Prepare the select from... table
            $fromEntityDefinition = reset($adapterChain);
            $fromEntity = key($adapterChain);
            $fromTableIdentifier = new TableIdentifier($fromEntityDefinition[self::TABLE], $fromEntityDefinition[self::SCHEMA]);
            $fromTableGateway = new TableGateway($fromTableIdentifier, $this->adapters[$fromEntityDefinition[self::ADAPTER]]);

            $select = $fromTableGateway->getSql()->select();

            $selectColumns = $this->getSelectColumns($query, $fromEntity, $fromEntityDefinition, $resultSets[$currentResultSetIndex]);
            $this->processInterAdapterJoinLogic($fromEntity, $fromEntityDefinition, $interAdapterChainsRelations, $key, $selectColumns, $resultSets, $currentResultSetIndex, $result ?? null);
            $select->columns($selectColumns);

            // Prepare the join tables
            $previousEntityChain = [];
            $previousTableIdentifier = null;
            foreach ($adapterChain as $entity => $entityDefinition) {
                $previousChainObjects = $this->getPreviousChainObjects($chains, $entity);
                $previousEntityChain = $previousChainObjects['previousEntityChain'];
                $previousTableIdentifier = $previousChainObjects['previousTableIdentifier'];
                // We have already processed the first table as a select from... table so skip to the next one
                if ($entity === $fromEntity) {
                    continue;
                }

                $tableIdentifier = new TableIdentifier($entityDefinition[self::TABLE], $entityDefinition[self::SCHEMA]);
                $joinSelectColumns = $this->getSelectColumns($query, $entity, $entityDefinition, $resultSets[$currentResultSetIndex]);
                $select->join($tableIdentifier, $previousTableIdentifier->getTable() . '.' . key($entityDefinition[self::ON]) . '=' . $tableIdentifier->getTable() . '.' . reset($entityDefinition[self::ON]), $joinSelectColumns);
            }

            $this->processQueryConditions($select, $query, $chains, $adapterChain);

            try {
                $result = $this->executeChainQuery($select, $fromTableGateway, $leadTransaction);
            } catch(InvalidQueryException $e) {
                var_dump($select->getSqlString());die();
            }

            $resultSets[$currentResultSetIndex][self::RESULT_SET_RESULT] = &$result;
            $entitiesResultsMap = [];
            foreach($adapterChain as $entity => $entityDefinition) {
                if(isset($resultSets[$currentResultSetIndex][self::RESULT_SET_PROTOTYPE][$entity])) {
                    $currentHydrator = $resultSets[$currentResultSetIndex][self::RESULT_SET_PROTOTYPE][$entity]->getHydrator();
                    if($currentHydrator instanceof ClassMethods) {
                        $resultSets[$currentResultSetIndex][self::RESULT_SET_PROTOTYPE][$entity]->setHydrator((new AliasesToClassMethodsHydrator($currentHydrator->getUnderscoreSeparatedKeys(), $resultSets[$currentResultSetIndex][self::RESULT_SET_DEFINITIONS][$entity][self::COLUMNS]))->setNamingStrategy($currentHydrator->getNamingStrategy()));
                    }
                    $resultSets[$currentResultSetIndex][self::RESULT_SET_PROTOTYPE][$entity]->initialize($result->getDataSource());

                    $entitiesResultsMap[$entity][self::RESULT_SET_INDEX] = $key;
                }
            }
        }

        if($key === null) {
            throw new \Exception('The query does not resolve to valid results.', 0);
        }
        // Automatically set the last result index as the active result index in the AggregateHydratingResultSet
        return new AggregateHydratingResultSet($resultSets, $entitiesResultsMap, $key);
    }
    
    public function findAllInvolvedEntities($query)
    {
        // Find all entities involved in the operation
        $entities = [];
        foreach($query as $queryClause => $queryValues) {
            if($queryClause == self::WHERE) {
                foreach($queryValues as $whereType => $whereValues) {
                    foreach($whereValues as $entity => $whereValues) {
                        $entities[$entity] = $entity;
                    }
                }
            } else {
                foreach($queryValues as $entity => $queryValues) {
                    $entities[$entity] = $entity;
                }
            }
        }

        return $entities;
    }

    public function buildRelationsChains($query, $entities)
    {
        // Determine the entity join chains for each entity
        $chains = [];
        $mergedChains = [];
        foreach($entities as $entity => $entityRepeated) {
            $chain = [];
            $relation = $this->findRelationChain($entity, $chain);
            if(!$relation) {
                // The entity does not figure in the relations schema
                throw new \Exception('Invalid entity: '.$entity, 2);
            }
            $chains[$entity] = array_reverse($chain);
            $mergedChains = array_merge($mergedChains, $chains[$entity]);
        }

        // Build an all entity encompassing chain
        $_mergedChains = $mergedChains;
        foreach($mergedChains as $entity => $entityDefinition) {
            if(!isset($entities[$entity])) {
                array_shift($_mergedChains);
            } else {
                break;
            }
        }
        $mergedChains = $_mergedChains;

        // Create each adapter's own chain and determine the inter-adapter table relations
        $currentAdapter = reset($mergedChains)[self::ADAPTER];
        $adapterChains[] = [];
        $adapterChainsCount = 1;
        $currentAdapterChainIndex = 0;
        $interAdapterChainsRelations[] = [];
        foreach($mergedChains as $entity => $entityDefinition) {
            if($currentAdapter == $entityDefinition[self::ADAPTER]) {
                $adapterChains[$currentAdapterChainIndex][$entity] = $entityDefinition;
            } else {
                $interAdapterChainsRelations[$currentAdapterChainIndex][key($previousEntity)] = reset($previousEntity) + [self::INTER_ADAPTER_RELATES => [$entity => $entityDefinition]];
                $interAdapterChainsRelations[] = [];
                $adapterChains[] = [$entity => $entityDefinition];
                $adapterChainsCount = count($adapterChains);
                $currentAdapterChainIndex = $adapterChainsCount - 1;
                $currentAdapter = $entityDefinition[self::ADAPTER];
            }
            $previousEntity = [$entity => $entityDefinition];
        }
        $interAdapterChainsRelations = array_reverse($interAdapterChainsRelations);
        if(count($adapterChains) <= 1) {
            // No cross-adapter joins
            $interAdapterChainsRelations = [];
        }

        // Determine the order of adapter chains resolution based on the reverse order of selected tables
        $adapterChainsPrioritized = [];
        $_adapterChains = $adapterChains;
        foreach($query[self::SELECT] as $entity => $querySelect) {
            if(empty($_adapterChains)) {
                break;
            }
            if(isset($adapterChainsPrioritized[count($adapterChainsPrioritized)-1][$entity])) {
                continue;
            }
            foreach($adapterChains as $key => $adapterChain) {
                if(isset($adapterChain[$entity])) {
                    $adapterChainsPrioritized[] = $adapterChain;
                    unset($_adapterChains[$key]);
                    break;
                }
            }
        }
        foreach($_adapterChains as $adapterChain) {
            $adapterChainsPrioritized[] = $adapterChain;
        }
        $adapterChainsPrioritized = array_reverse($adapterChainsPrioritized);

        return [
            'entityChains' => $chains,
            'mergedChains' => $mergedChains,
            'interAdapterChainsRelations' => $interAdapterChainsRelations,
            'adapterChainsPrioritized' => $adapterChainsPrioritized
        ];
    }

    protected function determineSelectColumns($query, $entity, array &$currentResultSet = [])
    {
        $hydrator = $this->createHydrator($entity);
        $queryColumns = [];
        if(is_a($query[self::SELECT][$entity], $entity)) {
            $queryColumns = $hydrator->extract($query[self::SELECT][$entity]);
            $queryColumns =
                array_filter(
                    $queryColumns,
                    function($item) {
                        return !($item instanceof DeselectColumn);
                    }
                );
            $queryColumns = array_keys($queryColumns);
            $currentResultSet[self::RESULT_SET_PROTOTYPE][$entity] = new HydratingResultSet($hydrator, $query[self::SELECT][$entity]);
        } else if(is_array($query[self::SELECT][$entity])) {
            $queryColumns = $query[self::SELECT][$entity];
            $currentResultSet[self::RESULT_SET_PROTOTYPE][$entity] = new HydratingResultSet($hydrator, new $entity);
        }

        return $queryColumns;
    }

    protected function processSelectColumns($entity, $entityDefinition, $queryColumns, array &$currentResultSet = [])
    {
        $selectColumns = [];
        foreach ($queryColumns as $columnAlias => $column) {
            $alias = $entityDefinition[self::SCHEMA] . $entityDefinition[self::TABLE] . '_';
            if(is_numeric($columnAlias)) {
                $alias .= $column;
            } else {
                $alias .= $columnAlias;
            }
            if(is_string($column)) {
                $currentResultSet[self::RESULT_SET_DEFINITIONS][$entity][self::COLUMNS][$alias] = $column;
            } else {
                $currentResultSet[self::RESULT_SET_DEFINITIONS][$entity][self::COLUMNS][$alias] = $columnAlias;
            }
            $selectColumns[$alias] = $column;
        }

        return $selectColumns;
    }

    protected function getSelectColumns($query, $entity, $entityDefinition, array &$currentResultSet = [])
    {
        $selectColumns = [];
        if (isset($query[self::SELECT][$entity])) {
            $currentResultSet[self::RESULT_SET_DEFINITIONS][$entity] = $entityDefinition;
            if(!isset($currentResultSet[self::RESULT_SET_DEFINITIONS][$entity][self::COLUMNS])) {
                $currentResultSet[self::RESULT_SET_DEFINITIONS][$entity][self::COLUMNS] = [];
            }
            $queryColumns = $this->determineSelectColumns($query, $entity, $currentResultSet);
            $selectColumns = $this->processSelectColumns($entity, $entityDefinition, $queryColumns, $currentResultSet);
        }

        return $selectColumns;
    }

    public function getPreviousChainObjects($chains, $entity)
    {
        $previousEntityChain = [];
        $previousTableIdentifier = null;

        // Find the previous entity's chain
        foreach($chains as $chain) {
            if(isset($chain[$entity])) {
                $previousEntityChain = $chain;
                break;
            }
        }
        if(count($previousEntityChain) > 1) {
            reset($previousEntityChain);
            while (((key($previousEntityChain) !== null) && (key($previousEntityChain) !== false)) && (key($previousEntityChain) !== $entity)) {
                next($previousEntityChain);
            }
            prev($previousEntityChain);
            // ...or use array_reverse
            //$previousEntityChain = array_reverse($previousEntityChain);
            //reset($previousEntityChain);
            //next($previousEntityChain);
            $previousTableName = current($previousEntityChain)[self::TABLE];
            $previousTableSchema = current($previousEntityChain)[self::SCHEMA];
            $previousTableIdentifier = new TableIdentifier($previousTableName, $previousTableSchema);
            reset($previousEntityChain);
        }

        return [
            'previousEntityChain' => $previousEntityChain,
            'previousTableIdentifier' => $previousTableIdentifier,
        ];
    }

    protected function processInterAdapterJoinLogic($entity, array $entityDefinition, array $interAdapterChainsRelations, int $currentChainKey, array &$selectColumns = [], array &$resultSets = [], int $currentResultSetIndex = 0, ResultSetInterface $previousResult = null)
    {
        if(!empty($interAdapterChainsRelations)) {
            if (empty($interAdapterChainsRelations[$currentChainKey])) {
                // This is the column whose values will be used in the where in... clause of the subsequent adapter chain query
                $interAdapterJoinColumn = reset($entityDefinition[self::ON]);
                $alias = $entityDefinition[self::SCHEMA] . $entityDefinition[self::TABLE] . '_' . $interAdapterJoinColumn;
                $selectColumns[$alias] = $interAdapterJoinColumn;
                if (!isset($resultSets[$currentResultSetIndex][self::RESULT_SET_DEFINITIONS][$entity])) {
                    $resultSets[$currentResultSetIndex][self::RESULT_SET_DEFINITIONS][$entity] = $entityDefinition;
                }
                $resultSets[$currentResultSetIndex][self::RESULT_SET_DEFINITIONS][$entity][self::COLUMNS][$alias] = $interAdapterJoinColumn;
            } else {
                if(!empty($previousResult->count())) {
                    $currentChainInterAdapterJoinColumn =
                        reset($interAdapterChainsRelations[$currentChainKey])[self::TABLE] .
                        '.' .
                        key(reset(reset($interAdapterChainsRelations[$currentChainKey])[self::INTER_ADAPTER_RELATES])[self::ON])
                    ;
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
    protected function processQueryConditions(&$select, $query, $chains, $adapterChain)
    {
        foreach ($query as $clauseType => $clauseValues) {
            if($clauseType == self::WHERE) {
                foreach($clauseValues as $operandType => $filterValues) {
                    foreach ($filterValues as $entity => $filter) {
                        if(!isset($adapterChain[$entity])) {
                            continue;
                        }

                        $filterArray = $filter;
                        if (is_object($filter)) {
                            if (isset($chains[$entity][$entity][self::HYDRATOR])) {
                                $hydrator = $this->createHydrator($chains[$entity][$entity]);
                                $filterArray = $hydrator->extract($filter);
                            } else {
                                if (method_exists($filter, 'getArrayCopy')) {
                                    $filterArray = $filter->getArrayCopy();
                                } else if (method_exists($filter, 'toArray')) {
                                    $filterArray = $filter->toArray();
                                }
                            }
                        }

                        switch ($operandType) {
                            case self::IN:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->in($chains[$entity][$entity][self::TABLE] . '.' . $filterColumn, $filterValue);
                                    }
                                }
                                break;
                            case self::NOT_IN:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->notIn($chains[$entity][$entity][self::TABLE] . '.' . $filterColumn, $filterValue);
                                    }
                                }
                                break;
                            case self::NOT_EQUAL:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->notEqualTo($chains[$entity][$entity][self::TABLE] . '.' . $filterColumn, $filterValue);
                                    }
                                }
                                break;
                            case self::EQUAL:
                                foreach ($filterArray as $filterColumn => $filterValue) {
                                    if ($filterValue !== null) {
                                        $select->where->equalTo($chains[$entity][$entity][self::TABLE] . '.' . $filterColumn, $filterValue);
                                    }
                                }
                                break;
                            default:
                                break;
                        }
                    }
                }
            } else if($clauseType == self::GROUP) {
                foreach ($clauseValues as $entity => $columns) {
                    if(!isset($adapterChain[$entity])) {
                        continue;
                    }

                    $groupColumns = $columns;
                    if(!is_array($groupColumns)) {
                        $groupColumns = [$groupColumns];
                    }
                    foreach($groupColumns as $groupColumn) {
                        $select->group($chains[$entity][$entity][self::TABLE] . '.' . $groupColumn);
                    }
                }
            }
        }
    }

    protected function executeChainQuery($select, $fromTableGateway, $leadTransaction)
    {
        // Execute the current adapter chain query
        if($leadTransaction && !($transactionStarted = $this->beginTransaction($fromTableGateway->getAdapter()))) {
            $this->rollbackTransaction($fromTableGateway->getAdapter());
            $transactionStarted = $this->beginTransaction($fromTableGateway->getAdapter());
        }
        $result = $fromTableGateway->selectWith($select);
        if ($transactionStarted) {
            $this->commitTransaction($fromTableGateway->getAdapter());
        }

        return $result;
    }

    public function createHydrator($entity)
    {
        $entityDefinition = $this->getEntityDefinition($entity);
        $hydratorClass = $entityDefinition[self::HYDRATOR] ?? ClassMethods::class;
        $hydrator = new $hydratorClass;
        if (isset($entityDefinition[self::NAMING_STRATEGY])) {
            $hydrator->setNamingStrategy(new $entityDefinition[self::NAMING_STRATEGY]);
        }

        return $hydrator;
    }

    /*
     * Recursively build the path to the target entity into an array
     */
    public function findRelationChain($findEntity, &$chain = [], $relations = null)
    {
        if(empty($this->relations)) {
            return false;
        }

        if(($relations === null) || ($relations === [])) {
            $relations = $this->relations;
        }

        foreach($relations as $entity => $entityDefinition) {
            if($findEntity === $entity) {
                $chain[$entity] = $entityDefinition;
                return true;
            }
            if(isset($entityDefinition[self::RELATES])) {
                if($this->findRelationChain($findEntity, $chain, $entityDefinition[self::RELATES])) {
                    $chain[$entity] = $entityDefinition;
                    return true;
                }
            }
        }

        return false;
    }

    public function getEntityRelation($findEntity, $relations = null)
    {
        if(is_object($findEntity)) {
            $findEntity = get_class($findEntity);
        }

        if(empty($this->relations)) {
            return false;
        }

        if(($relations === null) || ($relations === [])) {
            $relations = $this->relations;
        }

        foreach($relations as $entity => $entityDefinition) {
            if($findEntity === $entity) {
                return $entityDefinition;
            }
            if(isset($entityDefinition[self::RELATES])) {
                $entityDefinition = $this->getEntityDefinition($findEntity, $entityDefinition[self::RELATES]);
                if(!empty($entityDefinition)) {
                    return $entityDefinition;
                }
            }
        }

        return false;
    }

    public function hydrateEntity($entity, $data)
    {
        $entityDefinition = $this->getEntityDefinition($entity);
        if(empty($entityDefinition)) {
            throw new \Exception('Entity '.get_class($entity).' not found in relations model.');
        }

        if(empty($data)) {
            if(is_object($entity)) {
                return $entity;
            } else {
                return new $entity;
            }
        }

        $hydrator = $this->createHydrator($entityDefinition);

        if(!is_object($entity)) {
            $entity = new $entity;
        }
        return $hydrator->hydrate($data, $entity);
    }

    public function extractEntity($entity)
    {
        if(!is_object($entity)) {
            throw new \Exception('Entity: '.var_export($entity, true).' must be a valid relation model object in order to be extracted from.');
        }

        $entityDefinition = $this->getEntityDefinition($entity);
        if(empty($entityDefinition)) {
            throw new \Exception('Entity '.get_class($entity).' not found in relations model.');
        }

        $hydrator = $this->createHydrator($entityDefinition);

        return $hydrator->extract($entity);
    }

    public function validateQueryValues($entity, array $nullable = [])
    {
        $values = $this->model->extractEntity($entity);
        $values = array_filter(
            $values,
            function($value, $key) use($nullable) {
                return
                    (
                        (($value === null) && in_array($key, $nullable)) || is_scalar($item)
                    )
                    ;
            },
            ARRAY_FILTER_USE_BOTH
        );
        $values = array_walk(
            $values,
            function(&$value, $key) {
                if(is_bool($value)) {
                    $value = $value ? 't' : 'f';
                }
            }
        );

        return $values;
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
     * @param string $entity
     * @return array
     */
    public function getEntityDefinition(string $entity): array
    {
        return $this->entityDefinitions[$entity] ?? null;
    }

    /**
     * @param string $entity
     * @param array $entityDefinition
     * @return RelationalTableGateway
     */
    public function setEntityDefinition($entity, array $entityDefinition): RelationalTableGateway
    {
        $this->entityDefinitions[$entity] = $entityDefinition;
        return $this;
    }

    /**
     * @param string $entity
     * @param TableIdentifier $tableIdentifier
     * @return RelationalTableGateway
     */
    public function setTableIdentifier($entity, TableIdentifier $tableIdentifier): RelationalTableGateway
    {
        $this->entityDefinitions[$entity][self::TABLE_IDENTIFIER] = $tableIdentifier;
        return $this;
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
}
