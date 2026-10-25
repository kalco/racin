<?php

declare(strict_types=1);

namespace Racin\Database;

use PDOException;

/**
 * A failed SQL statement. The message contains the SQL but never the bound values.
 */
class QueryException extends DatabaseException
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        PDOException $previous,
    ) {
        parent::__construct($previous->getMessage() . ' (SQL: ' . $sql . ')', 0, $previous);
    }

    /**
     * SQLSTATE error code, e.g. "23000" for integrity constraint violations.
     */
    public function sqlState(): string
    {
        $previous = $this->getPrevious();

        return $previous instanceof PDOException ? (string) $previous->getCode() : '';
    }

    public function isUniqueViolation(): bool
    {
        $previous = $this->getPrevious();
        $driverCode = $previous instanceof PDOException ? ($previous->errorInfo[1] ?? null) : null;

        // MySQL 1062 duplicate entry, SQLite 19/2067 constraint unique.
        return $this->sqlState() === '23000' && in_array($driverCode, [1062, 19, 2067], true)
            || $this->sqlState() === '23505';
    }
}
