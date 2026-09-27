<?php
/**
 * SalesDesk — PDO database singleton.
 * Only prepared statements — never interpolate user input into SQL.
 */
require_once __DIR__ . '/config.php';

class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $dsn     = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            // Pin the connection collation to the one every table uses.
            // Some hosts set a different collation_connection (init_connect /
            // server defaults) than the client charset's default. Bound
            // parameters then carry utf8mb4_general_ci while string literals
            // carry utf8mb4_unicode_ci, and any "? = 'literal'" comparison
            // dies with: 1267 Illegal mix of collations (…COERCIBLE) for '='.
            if (stripos((string) DB_CHARSET, 'utf8mb4') === 0) {
                $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            while (ob_get_level()) ob_end_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'A database error occurred. Please try again later.']);
            exit;
        }
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }
}