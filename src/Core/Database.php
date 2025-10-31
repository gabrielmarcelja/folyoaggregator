<?php
namespace FolyoAggregator\Core;

use PDO;
use PDOException;
use Exception;

/**
 * Database connection manager
 * Singleton pattern to ensure single database connection
 */
class Database {
    private static ?Database $instance = null;
    private ?PDO $connection = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->connect();
    }

    /**
     * Get database instance (Singleton)
     *
     * @return Database
     */
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Connect to database
     *
     * @throws Exception
     */
    private function connect(): void {
        try {
            $host = config('database.host');
            $port = config('database.port');
            $name = config('database.name');
            $user = config('database.user');
            $pass = config('database.pass');
            $charset = config('database.charset');

            $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=$charset";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $charset"
            ];

            $this->connection = new PDO($dsn, $user, $pass, $options);

        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Get PDO connection
     *
     * @return PDO
     * @throws Exception
     */
    public function getConnection(): PDO {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    /**
     * Execute a query with parameters
     *
     * @param string $sql
     * @param array $params
     * @return \PDOStatement
     * @throws Exception
     */
    public function query(string $sql, array $params = []): \PDOStatement {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception("Query failed: " . $e->getMessage());
        }
    }

    /**
     * Fetch single row
     *
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    public function fetchOne(string $sql, array $params = []): ?array {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all rows
     *
     * @param string $sql
     * @param array $params
     * @return array
     */
    public function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Insert data
     *
     * @param string $table
     * @param array $data
     * @return int Last insert ID
     */
    public function insert(string $table, array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $this->query($sql, $data);

        return (int)$this->connection->lastInsertId();
    }

    /**
     * Update data
     *
     * @param string $table
     * @param array $data
     * @param array $where
     * @return int Affected rows
     */
    public function update(string $table, array $data, array $where): int {
        $setParts = [];
        foreach ($data as $column => $value) {
            $setParts[] = "$column = :$column";
        }
        $setClause = implode(', ', $setParts);

        $whereParts = [];
        foreach ($where as $column => $value) {
            $whereParts[] = "$column = :where_$column";
            $data["where_$column"] = $value;
        }
        $whereClause = implode(' AND ', $whereParts);

        $sql = "UPDATE $table SET $setClause WHERE $whereClause";
        $stmt = $this->query($sql, $data);

        return $stmt->rowCount();
    }

    /**
     * Delete data
     *
     * @param string $table
     * @param array $where
     * @return int Affected rows
     */
    public function delete(string $table, array $where): int {
        $whereParts = [];
        foreach ($where as $column => $value) {
            $whereParts[] = "$column = :$column";
        }
        $whereClause = implode(' AND ', $whereParts);

        $sql = "DELETE FROM $table WHERE $whereClause";
        $stmt = $this->query($sql, $where);

        return $stmt->rowCount();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): void {
        $this->getConnection()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): void {
        $this->getConnection()->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback(): void {
        $this->getConnection()->rollBack();
    }

    /**
     * Close connection
     */
    public function close(): void {
        $this->connection = null;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}