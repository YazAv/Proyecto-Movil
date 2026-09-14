<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class Database {
    private $host = "127.0.0.1"; 
    private $port = "5432"; 
    private $db_name = "sazontrack_db";
    private $username = "postgres";
    private $password = "123456"; 
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $dsn = "pgsql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name;
            $this->conn = new PDO(
                $dsn,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "Error de conexión PostgreSQL: " . $e->getMessage()
            ]);
            exit();
        }
        return $this->conn;
    }
}