<?php
namespace TccAbstractDbMapper\Mapper;

use Zend\Db\Adapter\Exception\InvalidQueryException;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Where;
use Zend\Stdlib\ArrayUtils;
use Zend\Stdlib\Hydrator\HydratorInterface;
use Zend\Hydrator\ObjectProperty;
use ZfcBase\Mapper\AbstractDbMapper as ZfcBaseAbstractDbMapper;

// TODO: Refactor this to remove the dependency on ZfcBase - use TableGateway instead?
class AbstractDbMapper extends ZfcBaseAbstractDbMapper
{
    /**
     * @var array The primary key(s) that uniquely identifies a single record in the database. ID by default.
     */
    protected $primaryKeys = [
        'id',
    ];

    /**
     * @var array The columns to be returned from SQL Select statements.
     */
    protected $columns = [
        Select::SQL_STAR,
    ];

    /**
     * @var array An array of arrays containing table, on, columns, type, specifying joins for this mapper to make.
     */
    protected $joins = [];

    /**
     * @var array An array of keys to exclude from the data extracted from the entity when saving to the db.
     */
    protected $filter;

    /**
     * @var array A key => value array of keys to map from the data extracted to a different name for saving to the db.
     */
    protected $map;
    /**
     * @var bool If true, operations will still be performed even when only a partial primary key is supplied. This
     *           variable is set back to false after one use.
     */
    protected $disablePrimaryKeyCheckOnce = false;

    /**
     * AbstractDbMapper constructor.
     *
     * @param null $tableName   The table on which this mapper will operate.
     * @param null $primaryKeys The primary keys that uniquely identify records in the table.
     */
    public function __construct($tableName=null, $primaryKeys=null)
    {
        if ($tableName) {
            $this->tableName = $tableName;
        }

        if ($primaryKeys) {
            $this->primaryKeys = $primaryKeys;
        }
    }

    /**
     * Before running a query, if no entity prototype is present then set a standard object and property hydrator
     * before calling the parent initialize method.
     *
     * @return null
     */
    protected function initialize()
    {
        // If no entity prototype is set, default to a standard object and set the hydrator to a property hydrator.
        if (!is_object($this->entityPrototype)) {
            $this->setEntityPrototype(new \stdClass());
            $this->setHydrator(new ObjectProperty());
        }

        return parent::initialize();
    }

    /**
     * Find a single entity, using the specified primary key values.
     *
     * @param array|string $pkValues A key => value array of the primary keys. If only a single primary key is
     *                               required, it can be passed as a string.
     *
     * @return object The entity matching the specified primary key(s).
     */
    public function find($pkValues, $where=null)
    {
        // If a single primary key value has been passed, assume it is the first primary key in $this->primaryKeys.
        if (!is_array($pkValues)) {
            reset($this->primaryKeys);
            $pkValues = [ current($this->primaryKeys) => $pkValues ];
        }

        // Throw an exception if the primary key is not valid or complete (if the primary key check is enabled).
        $this->assertValidPrimaryKey($pkValues);

        // Retrieve the entity identified by the specified primary key values.
        $select = $this->getSelect();
        $select->columns($this->columns);

        // Add primary keys WHERE, combining with optional additional $where if passed.
        $whereObject = new Where();
        $whereObject->addPredicates($pkValues);
        if ($where) {
            $whereObject->addPredicates($where, Where::COMBINED_BY_AND);
        }
        $select->where($whereObject);

        // TODO: There is currently no warning if multiple records were returned.
        return $this->select($select)->current();
    }

    /**
     * @param bool  $asArray    If true, an array of entities will be returned instead of a HydratingResultSet.
     * @param array $options    Options such as 'where' and 'order' to be used in the query.
     *
     * @return array|\Zend\Db\ResultSet\HydratingResultSet
     */
    public function fetchAll($asArray=false, $options = [])
    {
        return $this->executeSelectAndFormat($this->addOptionsToSelect($this->getSelect(), $options), $asArray);
    }

    /**
     * Create a database entry for the specified entity. Fail if the record already exiists.
     *
     * @param $entity       The entity from which the data to be inserted will be extracted.
     *
     * @return mixed|null   The autogenerated id.
     */
    public function insertEntity($entity)
    {
        return $this->insert($entity)->getGeneratedValue();
    }

    /**
     * Update a database entry for the specified entity. Fail if the record does not exist.
     *
     * @param $entity The entity from which the data to be updated will be extracted.
     *
     * @return null
     */
    public function updateEntity($entity)
    {
        // Extract primary key values for update.
        $pkValues = $this->getPrimaryKeyValuesFromEntity($entity);

        // Throw an exception if the primary key is not valid or complete.
        $this->assertValidPrimaryKey($pkValues);

        // TODO: There is currently no warning if there is nothing to update.
        return parent::update($entity, $pkValues);
    }

    /**
     * Create or update a database entry for the specified entity.
     *
     * @param $entity The entity from which the data to be saved will be extracted.
     *
     * @return mixed|null   The autogenerated id.
     */
    public function saveEntity($entity)
    {
        try {
            return $this->insertEntity($entity);
        } catch (InvalidQueryException $e) {
            // TODO: There is currently no warning if there is nothing to update.
            return $this->updateEntity($entity);
        }
    }

    /**
     * @param $entity The entity from which the primary keys of the record to be deleted will be extracted.
     *
     * @return mixed
     */
    public function deleteEntity($entity)
    {
        // Get the primary keys from the entity.
        $pkValues = $this->getPrimaryKeyValuesFromEntity($entity);

        // Throw an exception if the primary key is not valid or complete.
        $this->assertValidPrimaryKey($pkValues);

        // TODO: There is currently no warning if there is nothing to delete.
        return parent::delete($pkValues);
    }

    /**
     * Return the select object, with the appropriate joins applied, if specified.
     *
     * @param string $table Override the table specified against the mapper, if desired.
     *
     * @return \Zend\Db\Sql\Select The select object, configured with table, joins and columns.
     */
    protected function getSelect($table = null)
    {
        // Use ZfcBase method to get the select object.
        $select = parent::getSelect($table);

        // Add any specified joins.
        foreach ($this->joins as $join) {
            $select->join(
                $join['table'],
                $join['on'],
                (isset($join['columns']) && $join['columns'] ? $join['columns'] : $select::SQL_STAR),
                (isset($join['type']) && $join['type'] ? $join['type'] : $select::JOIN_LEFT)
            );
        }

        return $select;
    }

    /**
     * Add options such as WHERE and ORDER to the specified Select object.
     *
     * @param \Zend\Db\Sql\Select $select   The select object to which the options will be added.
     * @param array               $options  The options to add, in a key => value array.
     *
     * @return \Zend\Db\Sql\Select The select object, configured with where and order clauses.
     */
    protected function addOptionsToSelect($select, $options)
    {
        $select->columns($this->columns);

        if (isset($options['where'])) {
            $select->where($options['where']);
        }
        if (isset($options['order'])) {
            $select->order($options['order']);
        }

        return $select;
    }

    /**
     * Execute the specified Select and format as either a plain array or a HydratingResultSet.
     *
     * @param \Zend\Db\Sql\Select $select   The select object to execute.
     * @param bool                $asArray  True if a plain array is desired, false for a HydratingResultSet.
     *
     * @return array|\Zend\Db\ResultSet\HydratingResultSet
     */
    protected function executeSelectAndFormat($select, $asArray=false)
    {
        // Execute the Select.
        $results = $this->select($select);

        // Return results as a hydrating result set by default, or a plain array of entities if requested.
        return $asArray ? ArrayUtils::iteratorToArray($results, false) : $results;
    }

    /**
     * Extract primary keys from an entity, using the primary keys specified against this mapper.
     *
     * @param $entity The entity from which the primary keys should be extracted.
     *
     * @return array
     */
    public function getPrimaryKeyValuesFromEntity($entity)
    {
        // Prepare the primary key array for the intersect operations that follow.
        $primaryKeys = array_flip($this->primaryKeys);

        // Retrieve the primary key values from the entity.
        $primaryKeyValues = array_intersect_key($this->entityToArray($entity), $primaryKeys);

        // Remove empty primary keys.
        $primaryKeyValues = array_filter($primaryKeyValues, function ($value) { return $value !== ''; });

        return $primaryKeyValues;
    }

    /**
     * Given an array of primary key values, throw an exception if the key is incomplete or includes values for fields
     * which are not part of the primary key. NOTE that if disablePrimaryKeyCheckOnce has been called, this assertion
     * will return true regardless of the primary key values supplied.
     *
     * @param array $pkValues A key => value array of primary keys to values for checking.
     */
    protected function assertValidPrimaryKey($pkValues)
    {
        // Disable the assertion if requested, and reset the flag to ensure that the assertion is applied next time.
        if ($this->disablePrimaryKeyCheckOnce) {
            $this->disablePrimaryKeyCheckOnce(false);
            return;
        }

        // Check for missing primary key values and extra values that are not part of the primary key.
        $primaryKeys = array_flip($this->primaryKeys);
        $missingKeys = array_keys(array_diff_key($primaryKeys, $pkValues));
        $extraKeys = array_keys(array_diff_key($pkValues, $primaryKeys));
        if ($missingKeys || $extraKeys) {
            $messages = [];
            if ($missingKeys) {
                $messages[] = 'Missing primary key part: ' . implode(', ', $missingKeys) . '.';
            }
            if ($extraKeys) {
                $messages[] = 'Invalid primary key part: ' . implode(',', $extraKeys) . '.';
            }
            throw new InvalidPrimaryKeyException(implode(' ', $messages));
        }
    }

    /**
     * This function should be called immediately before calling an operation such as find. The flag will be reset as
     * soon as it has been used. Be careful, this can be dangerous, especially on UPDATE or DELETE operations.
     *
     * @param bool $disable Can be optionally set to false to re-enable the primary key check immediately.
     *
     * @return AbstractDbMapper $this This object is returned to provide a chainable interface.
     */
    public function disablePrimaryKeyCheckOnce($disable=true)
    {
        $this->disablePrimaryKeyCheckOnce = ($disable == true);
        return $this;
    }

    // TODO: Use filter and mapper objects?
    /**
     * Convert an entity to an array ready for use in insert and update statements. Use the hydrator to extract the
     * data and then filter and map this ready for propagation to the database.
     *
     * @param object                                $entity   The entity to convert.
     * @param \Zend\Hydrator\HydratorInterface|null $hydrator Override the hydrator to use for initial conversion.
     *
     * @return array
     */
    protected function entityToArray($entity, HydratorInterface $hydrator=null)
    {
        // Use ZfcBase to perform the initial conversion.
        $data = parent::entityToArray($entity, $hydrator);

        // Filter any keys extracted by the hydrator that should not be propagated to the database.
        if ($this->filter) {
            $data = array_intersect_key($data, array_flip($this->filter));
        }

        // Map any keys extracted by the hydrator that should be propagated to the database with a different name.
        if ($this->map) {
            $mappedData = [];
            foreach ($data as $key => $value) {
                $mappedData[isset($this->map[$key]) ? $this->map[$key] : $key] = $value;
            }
            $data = $mappedData;
        }

        return $data;
    }
}
