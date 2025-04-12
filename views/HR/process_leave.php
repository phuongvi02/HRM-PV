<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';
// Kiểm tra quyền truy cập
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 2) {
    echo json_encode(['success' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này']);
    exit();
}

$db = Database::getInstance()->getConnection();

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = $_POST['request_id'] ?? null;
    $request_type = $_POST['request_type'] ?? null;
    $action = $_POST['action'] ?? null;

    if (!$request_id || !in_array($request_type, ['leave', 'overtime']) || !in_array($action, ['approve', 'reject'])) {
        $response['message'] = 'Dữ liệu không hợp lệ';
        echo json_encode($response);
        exit();
    }

    try {
        $db->beginTransaction();

        if ($request_type === 'leave') {
            $table = 'leave_requests';
            $status = ($action === 'approve') ? 'Được duyệt' : 'Từ chối';
            $update_query = "UPDATE $table SET status = :status, updated_at = NOW() WHERE id = :id AND status = 'Chờ duyệt'";
            $stmt = $db->prepare($update_query);
            $stmt->execute([':status' => $status, ':id' => $request_id]);

            if ($stmt->rowCount() > 0 && $action === 'approve') {
                $request_query = "SELECT employee_id, start_date, end_date FROM $table WHERE id = :id";
                $stmt = $db->prepare($request_query);
                $stmt->execute([':id' => $request_id]);
                $request = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($request) {
                    $start = new DateTime($request['start_date']);
                    $end = new DateTime($request['end_date']);
                    $days = $start->diff($end)->days + 1;

                    $update_employee_query = "UPDATE employees SET used_leave_days = used_leave_days + :days WHERE id = :employee_id";
                    $stmt = $db->prepare($update_employee_query);
                    $stmt->execute([':days' => $days, ':employee_id' => $request['employee_id']]);
                }
            }
        } elseif ($request_type === 'overtime') {
            $table = 'overtime_requests';
            $status = ($action === 'approve') ? 'Được duyệt' : 'Từ chối';
            $update_query = "UPDATE $table SET status = :status, updated_at = NOW() WHERE id = :id AND status = 'Chờ duyệt'";
            $stmt = $db->prepare($update_query);
            $stmt->execute([':status' => $status, ':id' => $request_id]);

            if ($stmt->rowCount() > 0 && $action === 'approve') {
                $request_query = "SELECT employee_id, hours FROM $table WHERE id = :id";
                $stmt = $db->prepare($request_query);
                $stmt->execute([':id' => $request_id]);
                $request = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($request) {
                    $hours = $request['hours'];
                    $update_employee_query = "UPDATE employees SET overtime_hours = COALESCE(overtime_hours, 0) + :hours WHERE id = :employee_id";
                    $stmt = $db->prepare($update_employee_query);
                    $stmt->execute([':hours' => $hours, ':employee_id' => $request['employee_id']]);
                }
            }
        }

        if ($stmt->rowCount() > 0) {
            $db->commit();
            $response['success'] = true;
            $response['message'] = ($action === 'approve') ? 'Yêu cầu đã được duyệt!' : 'Yêu cầu đã bị từ chối!';
        } else {
            $db->rollBack();
            $response['message'] = 'Yêu cầu không tồn tại hoặc đã được xử lý';
        }
    } catch (PDOException $e) {
        $db->rollBack();
        $response['message'] = 'Có lỗi xảy ra: ' . $e->getMessage();
    }
}

header('Content-Type: application/json');
echo json_encode($response);
exit();