<?php
/**
 * VolunteerOps - Database Connection
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5, // Prevent intermittent 60s hangs on Hostinger connection pools
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]);
        } catch (PDOException $e) {
            if (DEBUG_MODE) {
                die("Σφάλμα σύνδεσης: " . $e->getMessage());
            }
            die("Σφάλμα σύνδεσης βάσης δεδομένων.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}

// Helper functions
function db() {
    return Database::getInstance()->getConnection();
}

function dbFetchAll($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function dbFetchOne($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    return $result === false ? null : $result;
}

function dbFetchValue($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function dbExecute($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function dbInsert($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return db()->lastInsertId();
}

function dbEscape($string) {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $string);
}
