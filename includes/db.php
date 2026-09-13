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
            
            // Sync MySQL session timezone with PHP timezone
            $phpTzOffset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5, // Prevent intermittent 60s hangs on Hostinger connection pools
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '" . $phpTzOffset . "'"
            ]);
        } catch (PDOException $e) {
            self::failClosed($e);
        }
    }

    /**
     * A refused connection is not a crash, it is the server being at capacity —
     * on shared hosting that is `SQLSTATE[HY000] [1226] max_user_connections`,
     * which the Action Room has hit for real. It is transient, and the right
     * advice is to wait, not to act.
     *
     * This used to `die()` with a bare sentence and PHP's default 200 status.
     * Two things made that actively harmful. The connection is opened in
     * bootstrap.php BEFORE `initSession()` and before any endpoint has sent
     * `Content-Type: application/json`, so the reply left as text/html; and
     * war-room.php's checkSessionAlive() reads "200, not JSON" as "your session
     * expired", offering a Reload button. So every user was told the wrong
     * thing, and invited to press a button whose only effect is one more
     * connection attempt against a database that has none left — a reload storm
     * at the exact moment the server can least afford it.
     *
     * 503 + Retry-After states what is actually true and, being a status code,
     * can be told apart from an auth redirect without parsing a body.
     */
    private static function failClosed(PDOException $e): void {
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 15');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Content-Type: text/html; charset=UTF-8');
        }

        // No t() and no getSetting() here: both need the database that just
        // refused us, and the session has not started either, so the viewer's
        // language is unknowable. Both languages, hardcoded, no dependencies.
        $detail = DEBUG_MODE ? '<pre style="white-space:pre-wrap;text-align:left;font-size:.8rem;color:#991b1b;background:#fef2f2;padding:12px;border-radius:6px;overflow:auto">'
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>' : '';

        echo '<!doctype html><html lang="el"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Ο διακομιστής είναι υπερφορτωμένος</title>'
            // Reloads itself rather than offering a button: an automatic retry
            // after a delay spreads the load out, a button invites everyone to
            // press it at once.
            . '<meta http-equiv="refresh" content="15">'
            . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#0f172a;padding:24px}'
            . '.box{max-width:30rem;text-align:center}h1{font-size:1.25rem;margin:0 0 .75rem}'
            . 'p{margin:0 0 .5rem;line-height:1.6;color:#334155}.en{color:#64748b;font-size:.92rem}'
            . '.wait{margin-top:1.25rem;font-size:.85rem;color:#64748b}</style></head><body><div class="box">'
            . '<h1>Ο διακομιστής είναι προσωρινά υπερφορτωμένος</h1>'
            . '<p>Η σύνδεσή σας <strong>δεν</strong> έχει λήξει και δεν χρειάζεται να συνδεθείτε ξανά. '
            . 'Η σελίδα θα ξαναπροσπαθήσει μόνη της σε λίγα δευτερόλεπτα.</p>'
            . '<p class="en">The server is temporarily overloaded. You are still signed in — this page retries by itself.</p>'
            . $detail
            . '<p class="wait">Αυτόματη επανάληψη σε 15 δευτερόλεπτα…</p>'
            . '</div></body></html>';
        exit;
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
