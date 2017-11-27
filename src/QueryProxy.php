<?php

namespace Finesse\QueryScribe;

use Finesse\QueryScribe\Exceptions\InvalidReturnValueException;

/**
 * Helps to make a Query object extensions which don't fully inherit the Query interface. It keeps an original Query
 * object and passes all the methods calls to it. It helps to substitute the value passed to closures.
 *
 * @mixin Query
 *
 * @author Surgie
 */
class QueryProxy implements ClosureResolverInterface
{
    /**
     * @var Query A real underlying query object
     */
    protected $baseQuery;

    /**
     * @var string[] Which methods should not be proxied to the underlying query object
     */
    protected $doNotProxy = [
        'setClosureResolver',
        'makeEmptyCopy',
        'makeCopyForSubQuery',
        'makeCopyForCriteriaGroup'
    ];

    /**
     * @param Query $baseQuery Underlying query object
     */
    public function __construct(Query $baseQuery)
    {
        try {
            $this->baseQuery = $baseQuery->setClosureResolver($this);
        } catch (\Throwable $exception) {
            $this->handleException($exception);
        }
    }

    /**
     * {@inheritDoc}
     * All the exception from the underlying query are sent to the `handleException` method.
     *
     * @throws \Error If the given method is not defined in a base query or forbidden
     */
    public function __call($name, $arguments)
    {
        if (in_array($name, $this->doNotProxy)) {
            return $this->handleException(
                new \Error(sprintf('Call to undefined method %s::%s()', static::class, $name))
            );
        }

        try {
            $result = $this->baseQuery->$name(...$arguments);
        } catch (\Throwable $exception) {
            return $this->handleException($exception);
        }

        // If the base query returns itself, this object should also return itself
        if ($result === $this->baseQuery) {
            return $this;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function __clone()
    {
        try {
            $this->baseQuery = clone $this->baseQuery;
        } catch (\Throwable $exception) {
            $this->handleException($exception);
        }
    }

    /**
     * {@inheritDoc}
     * @return self
     * @see Query::applyCallback
     */
    public function applyCallback($callback): self
    {
        $result = $callback($this) ?? $this;

        if ($result instanceof self) {
            return $result;
        }
        if ($result instanceof Query) {
            return new static($result);
        }

        return $this->handleException(InvalidReturnValueException::create(
            'The callback return value',
            $result,
            ['null', self::class, Query::class]
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function resolveSubQueryClosure(\Closure $callback): Query
    {
        try {
            $baseQuery = $this->baseQuery->makeCopyForSubQuery();
        } catch (\Throwable $exception) {
            return $this->handleException($exception);
        }

        return (new static($baseQuery))->applyCallback($callback)->baseQuery;
    }

    /**
     * {@inheritDoc}
     */
    public function resolveCriteriaGroupClosure(\Closure $callback): Query
    {
        try {
            $baseQuery = $this->baseQuery->makeCopyForCriteriaGroup();
        } catch (\Throwable $exception) {
            return $this->handleException($exception);
        }

        return (new static($baseQuery))->applyCallback($callback)->baseQuery;
    }

    /**
     * Returns the underlying real query object.
     *
     * @return Query
     */
    protected function getBaseQuery(): Query
    {
        return $this->baseQuery;
    }

    /**
     * Handles exceptions thrown by this object or the underlying query.
     *
     * @param \Throwable $exception Thrown exception
     * @return mixed A value to return in case of error
     * @throws \Throwable It may rethrow it
     */
    protected function handleException(\Throwable $exception)
    {
        throw $exception;
    }
}
