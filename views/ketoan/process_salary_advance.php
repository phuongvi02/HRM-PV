<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";

// Kiểm tra phiên làm việc và quyền truy cập
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id'])) {
    echo "<script>alert('Vui lòng đăng nhập để thực hiện thao tác này!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

if (!in_array($_SESSION['role_id'], [1, 2, 4])) {
    echo "<script>alert('Bạn không có quyền thực hiện thao tác này!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

$db = Database::getInstance()->getConnection();

// Lấy tham số từ URL
$advance_id = isset($_GET['advance_id']) ? (int)$_GET['advance_id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($advance_id <= 0 || !in_array($action, ['approve', 'reject'])) {
    echo "<script>alert('Yêu cầu không hợp lệ!'); window.location.href='/HRMpv/views/ketoan/advance_requests.php';</script>";
    exit();
}

// Cập nhật trạng thái yêu cầu tạm ứng
if ($action === 'approve') {
    $status = 'Đã duyệt';
    $approved_date = date('Y-m-d H:i:s');
} else {
    $status = 'Từ chối';
    $approved_date = date('Y-m-d H:i:s');
}

$stmt = $db->prepare("
    UPDATE salary_advances 
    SET status = ?, approved_date = ? 
    WHERE id = ? AND status = 'Chờ duyệt'
");
$result = $stmt->execute([$status, $approved_date, $advance_id]);

if ($result) {
    echo "<script>alert('Yêu cầu tạm ứng #$advance_id đã được " . ($action === 'approve' ? 'phê duyệt' : 'từ chối') . " thành công!'); window.location.href='/HRMpv/views/ketoan/advance_requests.php';</script>";
} else {
    echo "<script>alert('Có lỗi xảy ra khi xử lý yêu cầu tạm ứng!'); window.location.href='/HRMpv/views/ketoan/advance_requests.php';</script>";
}
?>