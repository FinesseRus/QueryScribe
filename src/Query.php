<?php

namespace Finesse\QueryScribe;

use Finesse\QueryScribe\Exceptions\InvalidArgumentException;
use Finesse\QueryScribe\Exceptions\InvalidReturnValueException;
use Finesse\QueryScribe\QueryBricks\JoinTrait;
use Finesse\QueryScribe\QueryBricks\OrderTrait;
use Finesse\QueryScribe\QueryBricks\ResolvesClosuresTrait;
use Finesse\QueryScribe\QueryBricks\InsertTrait;
use Finesse\QueryScribe\QueryBricks\SelectTrait;
use Finesse\QueryScribe\QueryBricks\WhereTrait;

/**
 * Represents a built query. It only stores the query data and doesn't do anything smart like compiling an SQL text or
 * adding prefixes to tables. All the stored identifiers are final.
 *
 * All the Closures mentioned here as a value type are a function of the following type (if other is not specified):
 *  - Takes an empty query as the first argument;
 *  - Returns a Query object or modifies the given object by reference.
 *
 * All the exceptions are passed to the `handleException` method instead of just throwing.
 *
 * The Closure is used instead of callable to prevent ambiguities when a string column name or a value may be treated as
 * a function name.
 *
 * Future features:
 *  - todo union
 *  - todo group by and having
 *  - todo distinct
 *
 * @ignore The Closure behaviour can be changed if you pass a custom closure resolver through `setClosureResolver`.
 *
 * @author Surgie
 */
class Query
{
    use MakeRawTrait, SelectTrait, InsertTrait, JoinTrait, WhereTrait, OrderTrait, ResolvesClosuresTrait;

    /**
     * @var string|self|StatementInterface|null Query target table name
     */
    public $table = null;

    /**
     * @var string|null Target table alias
     */
    public $tableAlias = null;

    /**
     * @var mixed[]|self[]|StatementInterface[] Fields to update. The indexes are the columns names, the
     *     values are the values.
     */
    public $update = [];

    /**
     * @var bool Should rows be deleted?
     */
    public $delete = false;

    /**
     * @var int|self|StatementInterface|null Offset
     */
    public $offset = null;

    /**
     * @var int|self|StatementInterface|null Limit
     */
    public $limit = null;

    /**
     * Sets the target table.
     *
     * @param string|\Closure|self|StatementInterface $table Not prefixed table name without quotes
     * @param string|null $alias Table alias. Warning! Alias is not allowed in insert, update and delete queries in some
     *     of the DBMSs.
     * @return $this
     * @throws InvalidArgumentException
     * @throws InvalidReturnValueException
     */
    public function table($table, string $alias = null): self
    {
        $table = $this->checkStringValue('Argument $table', $table);

        $this->table = $table;
        $this->tableAlias = $alias;
        return $this;
    }

    /**
     * Returns the identifier which the target table can be appealed to.
     *
     * @return string|null Alias or table name. Null if the target table has no string name.
     */
    public function getTableIdentifier()
    {
        if ($this->tableAlias !== null) {
            return $this->tableAlias;
        }
        if (is_string($this->table)) {
            return $this->table;
        }
        return null;
    }

    /**
     * Adds values that should be updated
     *
     * @param mixed[]|\Closure[]|self[]|StatementInterface[] $values Fields to update. The indexes are the columns
     *  names, the values are the values.
     * @return $this
     * @throws InvalidArgumentException
     * @throws InvalidReturnValueException
     */
    public function addUpdate(array $values): self
    {
        foreach ($values as $column => $value) {
            if (!is_string($column)) {
                return $this->handleException(InvalidArgumentException::create(
                    'Argument $values indexes',
                    $column,
                    ['string']
                ));
            }

            $value = $this->checkScalarOrNullValue('Argument $values['.$column.']', $value);
            $this->update[$column] = $value;
        }

        return $this;
    }

    /**
     * Makes the target rows be deleted.
     *
     * @return $this
     */
    public function setDelete(): self
    {
        $this->delete = true;
        return $this;
    }

    /**
     * Sets the offset.
     *
     * Warning! SQL doesn't allow to use offset without using limit.
     *
     * @param int|\Closure|self|StatementInterface|null $offset Offset. Null removes the offset.
     * @return $this
     * @throws InvalidArgumentException
     * @throws InvalidReturnValueException
     */
    public function offset($offset): self
    {
        $this->offset = $this->checkIntOrNullValue('Argument $offset', $offset);
        return $this;
    }

    /**
     * Sets the limit.
     *
     * @param int|\Closure|self|StatementInterface|null $limit Limit. Null removes the limit.
     * @return $this
     * @throws InvalidArgumentException
     * @throws InvalidReturnValueException
     */
    public function limit($limit): self
    {
        $this->limit = $this->checkIntOrNullValue('Argument $limit', $limit);
        return $this;
    }

    /**
     * Makes an empty self copy with dependencies.
     *
     * @return static
     */
    public function makeEmptyCopy(): self
    {
        $query = $this->constructEmptyCopy();
        $query->setClosureResolver($this->closureResolver);
        return $query;
    }

    /**
     * Makes a self copy with dependencies for passing to a subquery callback.
     *
     * @return static
     */
    public function makeCopyForSubQuery(): self
    {
        return $this->makeEmptyCopy();
    }

    /**
     * Makes a self copy with dependencies for passing to a criteria group callback.
     *
     * @return static
     */
    public function makeCopyForCriteriaGroup(): self
    {
        $query = $this->makeEmptyCopy();
        $query->table = $this->table;
        $query->tableAlias = $this->tableAlias;
        return $query;
    }

    /**
     * Modifies this query by passing it through a transform function.
     *
     * Warning! In contrast to the other methods, this method can both change this object and return a new object. The
     * returned query is the only query guaranteed to be a proper result query.
     *
     * @param callable $transform The function. It recieves this query object as the first argument. It must either
     *  change the given object or return a new query object.
     * @return static Result query object
     * @throws InvalidReturnValueException If the callback return value is wrong
     */
    public function apply(callable $transform): self
    {
        $result = $transform($this) ?? $this;

        if ($result instanceof self) {
            return $result;
        }

        return $this->handleException(InvalidReturnValueException::create(
            'The transform function return value',
            $result,
            ['null', self::class]
        ));
    }

    /**
     * Creates a new object of the current instance class. Override this method when you extend this class and the
     * extending class constructor have mandatory arguments or you need to inject some dependencies.
     *
     * @return static
     */
    protected function constructEmptyCopy(): self
    {
        return new static();
    }

    /**
     * Check that value is suitable for being a "string or subquery" property of a query. Retrieves the closure
     * subquery.
     *
     * @param string $name Value name
     * @param string|\Closure|self|StatementInterface $value
     * @return string|self|StatementInterface
     * @throws InvalidArgumentException
     * @throws InvalidReturnValueException
     */
    protected function checkStringValue(string $name, $value)
    {
        if (
            !is_string($value) &&
            !($value instanceof \Closure) &&
            !($value instanceof self) &&
            !($value instanceof StatementInterface)
        ) {
            return $this->handleException(InvalidArgumentException::create(
                $name,
                $value,
                ['string', \Closure::class, self::class, StatementInterface::class]
            ));
        }

        if ($value instanceof \Closure) {
            return $this->resolveSubQueryClosure($value);
        }

        return $value;
    }

    /**
     * Check that value is suitable for being a "int or null or subquery" property of a query. Retrieves the closure
     * subquery.
     *
     * @param string $name Value name
     * @param int|\Closure|self|StatementInterface|null $value
     * @return int|self|StatementInterface|null
     * @throws InvalidArgumentException
     * @throws InvalidReturnValueException
     */
    protected function checkIntOrNullValue(string $name, $value)
    {
        if (
            $value !== null &&
            !is_numeric($value) &&
            !($value instanceof \Closure) &&
            !($value instanceof self) &&
            !($value instanceof StatementInterface)
        ) {
            return $this->handleException(InvalidArgumentException::create(
                $name,
                $value,
                ['integer', \Closure::class, self::class, StatementInterface::class, 'null']
            ));
        }

        if (is_numeric($value)) {
            $value = (int)$value;
        } elseif ($value instanceof \Closure) {
            return $this->resolveSubQueryClosure($value);
        }

        return $value;
    }

    /**
     * Check that value is suitable for being a "scalar or null or subquery" property of a query. Retrieves the closure
     * subquery.
     *
     * @param string $name Value name
     * @param mixed|\Closure|self|StatementInterface|null $value
     * @return mixed|self|StatementInterface|null
     * @throws InvalidArgumentException
     * @throws InvalidReturnValueException
     */
    protected function checkScalarOrNullValue(string $name, $value)
    {
        if (
            $value !== null &&
            !is_scalar($value) &&
            !($value instanceof \Closure) &&
            !($value instanceof self) &&
            !($value instanceof StatementInterface)
        ) {
            return $this->handleException(InvalidArgumentException::create(
                $name,
                $value,
                ['scalar', \Closure::class, self::class, StatementInterface::class, 'null']
            ));
        }

        if ($value instanceof \Closure) {
            return $this->resolveSubQueryClosure($value);
        }

        return $value;
    }

    /**
     * Check that value is suitable for being a subquery property of a query. Retrieves the closure subquery.
     *
     * @param string $name Value name
     * @param \Closure|self|StatementInterface $value
     * @return self|StatementInterface
     * @throws InvalidArgumentException
     * @throws InvalidReturnValueException
     */
    protected function checkSubQueryValue(string $name, $value)
    {
        if (
            !($value instanceof \Closure) &&
            !($value instanceof self) &&
            !($value instanceof StatementInterface)
        ) {
            return $this->handleException(InvalidArgumentException::create(
                $name,
                $value,
                [\Closure::class, self::class, StatementInterface::class]
            ));
        }

        if ($value instanceof \Closure) {
            $value = $this->resolveSubQueryClosure($value);
        }

        return $value;
    }

    /**
     * Handles an exception thrown by this class.
     *
     * @param \Throwable $exception Thrown exception
     * @return mixed A value to return
     * @throws \Throwable
     */
    protected function handleException(\Throwable $exception)
    {
        throw $exception;
    }
}
