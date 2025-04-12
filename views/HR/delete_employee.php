<?php
ob_start();
require_once __DIR__ . "/../../core/Database.php";

$db = Database::getInstance()->getConnection();

// Lấy ID nhân viên từ URL
$employee_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
error_log("Employee ID from URL: $employee_id");

if ($employee_id <= 0) {
    error_log("Lỗi: ID nhân viên không hợp lệ");
    header("Location: list_employee.php?error=ID nhân viên không hợp lệ!");
    exit();
}

// Kiểm tra xem nhân viên có tồn tại không
try {
    $stmt = $db->prepare("SELECT id FROM employees WHERE id = :id");
    $stmt->execute([':id' => $employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        error_log("Lỗi: Không tìm thấy nhân viên với ID: $employee_id");
        header("Location: list_employee.php?error=Không tìm thấy nhân viên!");
        exit();
    }
} catch (PDOException $e) {
    error_log("Lỗi kiểm tra nhân viên: " . $e->getMessage());
    header("Location: list_employee.php?error=Lỗi kiểm tra nhân viên: " . urlencode($e->getMessage()));
    exit();
}

// Xóa nhân viên
try {
    $stmt = $db->prepare("DELETE FROM employees WHERE id = :id");
    $stmt->execute([':id' => $employee_id]);
    $rowCount = $stmt->rowCount();
    error_log("Đã xóa nhân viên với ID: $employee_id, Số dòng ảnh hưởng: $rowCount");
    header("Location: list_employee.php?success=Xóa nhân viên thành công!");
    exit();
} catch (PDOException $e) {
    error_log("Lỗi khi xóa nhân viên: " . $e->getMessage());
    header("Location: list_employee.php?error=Lỗi khi xóa nhân viên: " . urlencode($e->getMessage()));
    exit();
}

ob_end_flush();
?>