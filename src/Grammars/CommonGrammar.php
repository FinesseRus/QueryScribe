<?php

namespace Finesse\QueryScribe\Grammars;

use Finesse\QueryScribe\Exceptions\InvalidQueryException;
use Finesse\QueryScribe\GrammarInterface;
use Finesse\QueryScribe\QueryBricks\Aggregate;
use Finesse\QueryScribe\Query;
use Finesse\QueryScribe\QueryBricks\InsertFromSelect;
use Finesse\QueryScribe\QueryBricks\Join;
use Finesse\QueryScribe\Raw;
use Finesse\QueryScribe\StatementInterface;

/**
 * A grammar that covers most common DBMS syntax features.
 *
 * @author Surgie
 */
class CommonGrammar implements GrammarInterface
{
    use CommonGrammarCriteriaTrait;
    use CommonGrammarOrderTrait;

    /**
     * {@inheritDoc}
     */
    public function compile(Query $query): StatementInterface
    {
        if ($query->insert) {
            return $this->compileInsert($query)[0];
        }
        if ($query->update) {
            return $this->compileUpdate($query);
        }
        if ($query->delete) {
            return $this->compileDelete($query);
        }
        return $this->compileSelect($query);
    }

    /**
     * {@inheritDoc}
     */
    public function compileSelect(Query $query): StatementInterface
    {
        $bindings = [];
        $sql = [
            $this->compileSelectPart($query, $bindings),
            $this->compileFromPart($query, $bindings),
            $this->compileJoinPart($query, $bindings),
            $this->compileWherePart($query, $bindings),
            $this->compileOrderPart($query, $bindings),
            $this->compileOffsetAndLimitPart($query, $bindings)
        ];

        return new Raw($this->implodeSQL($sql), $bindings);
    }

    /**
     * {@inheritDoc}
     */
    public function compileInsert(Query $query): array
    {
        if ($query->table === null) {
            throw new InvalidQueryException('The INTO table is not set');
        }
        if ($query->tableAlias !== null) {
            throw new InvalidQueryException('Table alias is not allowed in insert query');
        }

        /**
         * Divide inserts into two groups: values and select queries
         * @var mixed[][]|Query[][]|StatementInterface[][] $values
         * @var InsertFromSelect[] $selects
         */
        $values = [];
        $selects = [];
        foreach ($query->insert as $index => $insert) {
            if (is_array($insert)) {
                $values[] = $insert;
            } elseif ($insert instanceof InsertFromSelect) {
                $selects[] = $insert;
            } else {
                throw new InvalidQueryException(sprintf(
                    'Unknown type of insert instruction %s: %s',
                    is_int($index) ? '#'.$index : '`'.$index.'`',
                    is_object($insert) ? get_class($insert) : gettype($insert)
                ));
            }
        }

        // Compile values queries
        $compiled = $this->compileInsertFromValues($query->table, $query->tableAlias, $values);

        // Compile insert from select queries
        foreach ($selects as $select) {
            $compiled[] = $this->compileInsertFromSelect(
                $query->table,
                $query->tableAlias,
                $select->columns,
                $select->selectQuery
            );
        }

        return $compiled;
    }

    /**
     * {@inheritDoc}
     */
    public function compileUpdate(Query $query): StatementInterface
    {
        if ($query->table === null) {
            throw new InvalidQueryException('The updated table is not set');
        }
        if (!$query->update) {
            throw new InvalidQueryException('The updated values are not set');
        }

        $bindings = [];
        $sql = [
            'UPDATE '.$this->compileIdentifierWithAlias($query->table, $query->tableAlias, $bindings),
            $this->compileJoinPart($query, $bindings),
            'SET '.$this->compileUpdateValues($query->update, $bindings),
            $this->compileWherePart($query, $bindings),
            $this->compileOrderPart($query, $bindings),
            $this->compileOffsetAndLimitPart($query, $bindings)
        ];

        return new Raw($this->implodeSQL($sql), $bindings);
    }

    /**
     * {@inheritDoc}
     */
    public function compileDelete(Query $query): StatementInterface
    {
        $bindings = [];
        $sql = [
            'DELETE'.($query->tableAlias === null ? '' : ' '.$this->quoteIdentifier($query->tableAlias)),
            $this->compileFromPart($query, $bindings),
            $this->compileJoinPart($query, $bindings),
            $this->compileWherePart($query, $bindings),
            $this->compileOrderPart($query, $bindings),
            $this->compileOffsetAndLimitPart($query, $bindings)
        ];

        return new Raw($this->implodeSQL($sql), $bindings);
    }

    /**
     * {@inheritDoc}
     */
    public function quoteCompositeIdentifier(string $name): string
    {
        $components = explode('.', $name);

        foreach ($components as $index => $component) {
            if ($component === '*') {
                continue;
            }

            $components[$index] = $this->quoteIdentifier($component);
        }

        return implode('.', $components);
    }

    /**
     * {@inheritDoc}
     */
    public function quoteIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * {@inheritDoc}
     */
    public function escapeLikeWildcards(string $string): string
    {
        return str_replace(['\\', '%',  '_'], ['\\\\', '\%', '\_'], $string);
    }

    /**
     * Compiles a SELECT part of an SQL query.
     *
     * @param Query $query Query data
     * @param array $bindings Bound values (array is filled by reference)
     * @return string SQL text
     */
    protected function compileSelectPart(Query $query, array &$bindings): string
    {
        $columns = [];

        foreach (($query->select ?: ['*']) as $alias => $column) {
            if ($column instanceof Aggregate) {
                $column = $this->compileAggregate($column, $bindings);
            } else {
                $column = $this->compileIdentifier($column, $bindings);
            }

            $columns[] = $column.(is_string($alias) ? $this->compileAlias($alias) : '');
        }

        return 'SELECT '.implode(', ', $columns);
    }

    /**
     * Compiles a FROM part of an SQL query.
     *
     * @param Query $query Query data
     * @param array $bindings Bound values (array is filled by reference)
     * @return string SQL text
     * @throws InvalidQueryException
     */
    protected function compileFromPart(Query $query, array &$bindings): string
    {
        if ($query->table === null) {
            throw new InvalidQueryException('The FROM table is not set');
        }

        return 'FROM '.$this->compileIdentifierWithAlias($query->table, $query->tableAlias, $bindings);
    }

    /**
     * Compiles a JOIN parts of an SQL query.
     *
     * @param Query $query Query data
     * @param array $bindings Bound values (array is filled by reference)
     * @return string SQL text
     * @throws InvalidQueryException
     */
    protected function compileJoinPart(Query $query, array &$bindings): string
    {
        $joinsSQL = [];

        foreach ($query->join as $join) {
            $joinsSQL[] = $this->compileOneJoin($join, $bindings);
        }

        return $this->implodeSQL($joinsSQL);
    }

    /**
     * Compiles a offset'n'limit SQL query part (if the query has it).
     *
     * @param Query $query Query data
     * @param array $bindings Bound values (array is filled by reference)
     * @return string SQL text
     * @throws InvalidQueryException
     */
    protected function compileOffsetAndLimitPart(Query $query, array &$bindings): string
    {
        $parts = [];

        if ($query->limit !== null) {
            $parts[] = 'LIMIT '.$this->compileValue($query->limit, $bindings);

            if ($query->offset !== null) {
                $parts[] = 'OFFSET '.$this->compileValue($query->offset, $bindings);
            }
        } else {
            if ($query->offset !== null) {
                throw new InvalidQueryException('Offset is not allowed without Limit');
            }
        }

        return $this->implodeSQL($parts);
    }

    /**
     * Compiles a WHERE part of an SQL query.
     *
     * @param Query $query Query data
     * @param array $bindings Bound values (array is filled by reference)
     * @return string SQL text
     * @throws InvalidQueryException
     */
    protected function compileWherePart(Query $query, array &$bindings): string
    {
        $sql = $this->compileCriteria($query->where, $bindings);
        if ($sql !== '') {
            $sql = 'WHERE '.$sql;
        }

        return $sql;
    }

    /**
     * Compiles a values list for the SET part of a update SQL query.
     *
     * @param Query mixed[]|Query[]|StatementInterface[] Values. The indexes are the columns names, the values are the
     *     values.
     * @param array $bindings Bound values (array is filled by reference)
     * @return string SQL text
     * @throws InvalidQueryException
     */
    protected function compileUpdateValues(array $values, array &$bindings): string
    {
        $parts = [];

        foreach ($values as $column => $value) {
            $parts[] = $this->quoteCompositeIdentifier($column).' = '.$this->compileValue($value, $bindings);
        }

        return implode(', ', $parts);
    }

    /**
     * Converts a identifier (table, column, database, etc.) to a part of an SQL query text. Screens all the stuff.
     *
     * @param string|Query|StatementInterface $identifier Identifier
     * @param array $bindings Bound values (array is filled by reference)
     * @return string SQL text
     */
    protected function compileIdentifier($identifier, array &$bindings): string
    {
        if ($identifier instanceof Query || $identifier instanceof StatementInterface) {
            return $this->compileSubQuery($identifier, $bindings);
        }

        return $this->quoteCompositeIdentifier($identifier);
    }

    /**
     * Converts a value to a part of an SQL query text. Actually it sends all the values to the bindings.
     *
     * @param mixed|Query|StatementInterface $value Value (a scalar value or a subquery)
     * @param array $bindings Bound values (array is filled by link)
     * @return string SQL text
     */
    protected function compileValue($value, array &$bindings): string
    {
        if ($value instanceof Query || $value instanceof StatementInterface) {
            return $this->compileSubQuery($value, $bindings);
        }

        $this->mergeBindings($bindings, [$value]);
        return '?';
    }

    /**
     * Converts a subquery to an SQL query text.
     *
     * @param Query|StatementInterface $subQuery Subquery
     * @param array $bindings Bound values (array is filled by link)
     * @param bool $parenthesise Wrap the subquery in parentheses?
     * @return string SQL text wrapped in parentheses
     */
    protected function compileSubQuery($subQuery, array &$bindings, bool $parenthesise = true): string
    {
        if ($subQuery instanceof Query) {
            try {
                $subQuery = $this->compile($subQuery);
            } catch (InvalidQueryException $exception) {
                throw new InvalidQueryException(
                    'Error in subquery: '.$exception->getMessage(),
                    $exception->getCode(),
                    $exception
                );
            }
        }

        $this->mergeBindings($bindings, $subQuery->getBindings());
        $sql = $subQuery->getSQL();

        return $parenthesise ? '('.$sql.')' : $sql;
    }

    /**
     * Converts an Aggregate object to an SQL query text.
     *
     * @param Aggregate $aggregate Aggregate
     * @param array $bindings Bound values (array is filled by link)
     * @return string SQL text
     */
    protected function compileAggregate(Aggregate $aggregate, array &$bindings): string
    {
        return $aggregate->function.'('.$this->compileIdentifier($aggregate->column, $bindings).')';
    }

    /**
     * Converts a Join object ot an SQL query text.
     *
     * @param Join $join Join
     * @param array $bindings Bound values (array is filled by link)
     * @return string SQL text
     */
    protected function compileOneJoin(Join $join, array &$bindings): string
    {
        $sql = $join->type.' JOIN '.$this->compileIdentifierWithAlias($join->table, $join->tableAlias, $bindings);

        if ($join->criteria) {
            $sql .= ' ON '.$this->compileCriteria($join->criteria, $bindings);
        }

        return $sql;
    }

    /**
     * Converts a identifier (table, column, database, etc.) with alias to a part of an SQL query text. Screens all the
     * stuff.
     *
     * @param string|Query|StatementInterface $identifier Identifier
     * @param string|null $alias Alias name
     * @param array $bindings Bound values (array is filled by link)
     * @return string SQL text
     */
    protected function compileIdentifierWithAlias($identifier, string $alias = null, array &$bindings): string
    {
        return $this->compileIdentifier($identifier, $bindings) . ($alias === null ? '' : $this->compileAlias($alias));
    }

    /**
     * Compiles the alias name to SQL with appending keyword. This method is used this way:
     * `$sql = 'column_name'.$this->compileAlias('aliasName');`
     *
     * @param string $alias
     * @return string
     */
    protected function compileAlias(string $alias): string
    {
        return ' AS '.$this->quoteIdentifier($alias);
    }

    /**
     * Compiles set of full INSERT queries from an array of values.
     *
     * @param string|Query|StatementInterface $table Target table name
     * @param string|null $tableAlias Table alias
     * @param mixed[][]|Query[][]|StatementInterface[][] $values Array of rows of values
     * @return StatementInterface[]
     * @throws InvalidQueryException
     */
    protected function compileInsertFromValues($table, string $tableAlias = null, array $values): array
    {
        if (empty($values)) {
            return [];
        }

        $bindings = [];

        // Step 1. Fetch unique columns list.
        $columns = [];
        foreach ($values as $row) {
            foreach ($row as $column => $value) {
                if (!isset($columns[$column])) {
                    $columns[$column] = count($columns);
                }
            }
        }

        // Step 2. Build the values matrix.
        $compiledRows = [];
        foreach ($values as $row) {
            $compiledRow = [];
            foreach ($columns as $column => $columnIndex) {
                if (array_key_exists($column, $row)) {
                    $compiledRow[$columnIndex] = $this->compileValue($row[$column], $bindings);
                } else {
                    $compiledRow[$columnIndex] = 'DEFAULT';
                }
            }
            $compiledRows[] = '('.implode(', ', $compiledRow).')';
        }

        // Step 3. Build the SQL
        $sqlLine1 = 'INSERT INTO '.$this->compileIdentifierWithAlias($table, $tableAlias, $bindings)
            . ' ('.implode(', ', array_map([$this, 'quoteCompositeIdentifier'], array_keys($columns))).')';
        $sqlLine2 = 'VALUES '.implode(', ', $compiledRows);

        return [new Raw($this->implodeSQL([$sqlLine1, $sqlLine2]), $bindings)];
    }

    /**
     * Compiles a full INSERT query from a select query.
     *
     * @param string|Query|StatementInterface $table Target table name
     * @param string|null $tableAlias Table alias
     * @param string[]|null $columns Columns of a target table. If null, the list of columns is omitted.
     * @param Query|StatementInterface $selectQuery The select query
     * @return StatementInterface
     * @throws InvalidQueryException
     */
    protected function compileInsertFromSelect(
        $table,
        string $tableAlias = null,
        array $columns = null,
        $selectQuery
    ): StatementInterface {
        $bindings = [];

        $sqlLine1 = 'INSERT INTO '.$this->compileIdentifierWithAlias($table, $tableAlias, $bindings);
        if ($columns !== null) {
            $sqlLine1 .= ' ('.implode(', ', array_map([$this, 'quoteCompositeIdentifier'], $columns)).')';
        }

        $sqlLine2 = $this->compileSubQuery($selectQuery, $bindings, false);

        return new Raw($this->implodeSQL([$sqlLine1, $sqlLine2]), $bindings);
    }

    /**
     * Merges two arrays of bound values.
     *
     * @param array $target Where to add values. The values are added by link.
     * @param array $source Values to add
     */
    protected function mergeBindings(array &$target, array $source)
    {
        $targetBindingsAmount = count($target);
        $sourceBindingsIndex = 0;

        foreach ($source as $name => $value) {
            $key = is_int($name) ? $targetBindingsAmount + $sourceBindingsIndex : $name;
            $target[$key] = $value;
            $sourceBindingsIndex += 1;
        }
    }

    /**
     * Implodes parts of SQL query to a single string.
     *
     * @param string[] $parts
     * @return string
     */
    protected function implodeSQL(array $parts): string
    {
        $result = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $result .= ($result === '' ? '' : "\n").$part;
        }

        return $result;
    }
}
