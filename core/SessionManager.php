<?php
require_once __DIR__ . "/Database.php";

class SessionManager implements SessionHandlerInterface {
    private $db;
    private $lifetime;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->lifetime = 86400 * 30; // 30 ngày
        error_log("SessionManager initialized at " . date('Y-m-d H:i:s'));
    }

    public function open($savePath, $sessionName): bool {
        error_log("Session open called with savePath: $savePath, sessionName: $sessionName at " . date('Y-m-d H:i:s'));
        return true;
    }

    public function close(): bool {
        error_log("Session close called at " . date('Y-m-d H:i:s'));
        return true;
    }

    public function read($id): string {
        error_log("Reading session: $id at " . date('Y-m-d H:i:s'));
        try {
            $stmt = $this->db->prepare("SELECT data FROM sessions WHERE id = :id AND expires_at > :now");
            $stmt->execute([':id' => $id, ':now' => time()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                error_log("Session $id found, data length: " . strlen($row['data']));
                return $row['data'];
            } else {
                error_log("Session $id not found or expired");
                return '';
            }
        } catch (PDOException $e) {
            error_log("Error reading session $id: " . $e->getMessage());
            return '';
        }
    }

    public function write($id, $data): bool {
        error_log("Writing session: $id, Data length: " . strlen($data) . " at " . date('Y-m-d H:i:s'));
        try {
            $expires_at = time() + $this->lifetime;
            $user_id = explode('_', $id)[2] ?? 0; // Lấy user_id từ session id (session_user_22)
            $stmt = $this->db->prepare("
                INSERT INTO sessions (id, user_id, data, expires_at) 
                VALUES (:id, :user_id, :data, :expires_at) 
                ON DUPLICATE KEY UPDATE data = :data, expires_at = :expires_at
            ");
            $result = $stmt->execute([
                ':id' => $id,
                ':user_id' => $user_id,
                ':data' => $data,
                ':expires_at' => $expires_at
            ]);
            if (!$result) {
                error_log("Failed to write session $id: " . print_r($stmt->errorInfo(), true));
            } else {
                error_log("Session $id written successfully");
            }
            return $result;
        } catch (PDOException $e) {
            error_log("Error writing session $id: " . $e->getMessage());
            return false;
        }
    }

    public function destroy($id): bool {
        error_log("Destroying session: $id at " . date('Y-m-d H:i:s'));
        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) {
            error_log("Error destroying session $id: " . $e->getMessage());
            return false;
        }
    }

    public function gc($maxLifetime): int|false {
        error_log("Garbage collection called with maxLifetime: $maxLifetime at " . date('Y-m-d H:i:s'));
        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE expires_at < :now");
            $stmt->execute([':now' => time()]);
            $deletedRows = $stmt->rowCount();
            error_log("Garbage collection deleted $deletedRows sessions");
            return $deletedRows;
        } catch (PDOException $e) {
            error_log("Error in garbage collection: " . $e->getMessage());
            return false;
        }
    }
}