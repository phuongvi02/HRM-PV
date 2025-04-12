<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";

// Kiểm tra phiên làm việc và vai trò (giả sử role_id = 1 là Quản trị)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

$db = Database::getInstance()->getConnection();

// Xử lý phê duyệt/từ chối yêu cầu
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = $_POST['request_id'] ?? 0;
    $action = $_POST['action'] ?? '';

    // Lấy thông tin yêu cầu
    $request_stmt = $db->prepare("
        SELECT pr.*, e.position_id, e.department_id, e.basic_salary, e.full_name 
        FROM promotion_requests pr
        JOIN employees e ON pr.employee_id = e.id
        WHERE pr.id = :request_id
    ");
    $request_stmt->execute([':request_id' => $request_id]);
    $request = $request_stmt->fetch(PDO::FETCH_ASSOC);

    if ($request) {
        if ($action === 'approve') {
            // Cập nhật thông tin nhân viên
            $update_data = [];
            $params = [':employee_id' => $request['employee_id']];
        
            if ($request['request_type'] === 'promotion' || $request['request_type'] === 'position_change') {
                $update_data[] = "position_id = :position_id";
                $params[':position_id'] = $request['desired_position_id'];
            }
            if ($request['request_type'] === 'department_change') {
                $update_data[] = "department_id = :department_id";
                $params[':department_id'] = $request['desired_department_id'];
            }
            if ($request['request_type'] === 'salary_increase' && $request['desired_salary']) {
                $update_data[] = "basic_salary = :basic_salary";
                $params[':basic_salary'] = $request['desired_salary'];
            }
        
            if (!empty($update_data)) {
                $update_stmt = $db->prepare("
                    UPDATE employees 
                    SET " . implode(', ', $update_data) . " 
                    WHERE id = :employee_id
                ");
                $update_stmt->execute($params);
            }
        
            // Cập nhật trạng thái yêu cầu
            $status_stmt = $db->prepare("UPDATE promotion_requests SET status = 'approved' WHERE id = :request_id");
            $status_stmt->execute([':request_id' => $request_id]);
        
            // Tạo bài test nếu là thăng chức hoặc tăng lương
            if ($request['request_type'] === 'promotion' || $request['request_type'] === 'salary_increase') {
                $test_check_stmt = $db->prepare("SELECT id FROM tests WHERE request_id = :request_id AND employee_id = :employee_id");
                $test_check_stmt->execute([':request_id' => $request_id, ':employee_id' => $request['employee_id']]);
                if (!$test_check_stmt->fetch()) {
                    $test_stmt = $db->prepare("
                        INSERT INTO tests (request_id, employee_id, test_type, question, options, correct_answer, status)
                        VALUES (:request_id, :employee_id, 'multiple_choice', :question, :options, :correct_answer, 'pending')
                    ");
                    $test_stmt->execute([
                        ':request_id' => $request_id,
                        ':employee_id' => $request['employee_id'],
                        ':question' => 'Kế toán trưởng cần làm gì để quản lý tài chính hiệu quả?',
                        ':options' => json_encode(['A. Lập báo cáo tài chính', 'B. Kiểm tra hóa đơn', 'C. Cả hai', 'D. Không làm gì']),
                        ':correct_answer' => 'C'
                    ]);
                    $success = "Yêu cầu của " . htmlspecialchars($request['full_name']) . " đã được phê duyệt! Bài test đã được tạo.";
                } else {
                    $success = "Yêu cầu của " . htmlspecialchars($request['full_name']) . " đã được phê duyệt thành công!";
                }
            } else {
                $success = "Yêu cầu của " . htmlspecialchars($request['full_name']) . " đã được phê duyệt thành công!";
            }
        } elseif ($action === 'reject') {
            // Cập nhật trạng thái yêu cầu
            $status_stmt = $db->prepare("UPDATE promotion_requests SET status = 'rejected' WHERE id = :request_id");
            $status_stmt->execute([':request_id' => $request_id]);
            $success = "Yêu cầu của " . htmlspecialchars($request['full_name']) . " đã bị từ chối!";
        }
    }
}

// Lấy danh sách tất cả yêu cầu và điểm từ bảng tests (lấy bài test mới nhất)
$requests_stmt = $db->prepare("
    SELECT pr.*, e.full_name, p.name AS current_position, d.name AS current_department,
           p2.name AS desired_position, d2.name AS desired_department, t.score, t.status AS test_status
    FROM promotion_requests pr
    JOIN employees e ON pr.employee_id = e.id
    LEFT JOIN positions p ON e.position_id = p.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN positions p2 ON pr.desired_position_id = p2.id
    LEFT JOIN departments d2 ON pr.desired_department_id = d2.id
    LEFT JOIN (
        SELECT t1.*
        FROM tests t1
        WHERE t1.id = (
            SELECT t2.id
            FROM tests t2
            WHERE t2.employee_id = t1.employee_id
            ORDER BY t2.created_at DESC
            LIMIT 1
        )
    ) t ON pr.employee_id = t.employee_id
    ORDER BY pr.created_at DESC
");
$requests_stmt->execute();
$requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
    <button onclick="history.back()" class="btn btn-secondary mb-3">Quay lại</button>
<link rel="stylesheet" href="/HRMpv/public/css/styles.css">

<style>
.admin-requests-container {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
    min-height: calc(100vh - 200px);
    margin-left: 250px;
    transition: margin-left 0.3s ease;
}

.admin-requests-container h2 {
    margin-bottom: 30px;
    text-align: center;
    color: #333;
    font-size: 2rem;
}

.requests-table {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.requests-table table {
    width: 100%;
    border-collapse: collapse;
}

.requests-table th,
.requests-table td {
    padding: 12px;
    border: 1px solid #e5e7eb;
    text-align: left;
}

.requests-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #374151;
}

.requests-table tbody tr:hover {
    background: #f9fafb;
}

.status-pending {
    color: #f39c12;
    font-weight: 500;
}

.status-approved {
    color: #28a745;
    font-weight: 500;
}

.status-rejected {
    color: #e74c3c;
    font-weight: 500;
}

.btn-approve,
.btn-reject {
    padding: 5px 10px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9rem;
    transition: background 0.3s ease;
}

.btn-approve {
    background: #28a745;
    color: white;
}

.btn-approve:hover {
    background: #218838;
}

.btn-reject {
    background: #e74c3c;
    color: white;
}

.btn-reject:hover {
    background: #c0392b;
}

.alert {
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid transparent;
    border-radius: 4px;
    text-align: center;
}

.alert-success {
    color: #155724;
    background-color: #d4edda;
    border-color: #c3e6cb;
}

.score-column {
    width: 80px;
    text-align: center;
}

@media (max-width: 768px) {
    .admin-requests-container {
        margin-left: 0;
        padding: 10px;
    }

    .admin-requests-container h2 {
        font-size: 1.5rem;
    }

    .requests-table {
        padding: 15px;
    }

    .requests-table th,
    .requests-table td {
        padding: 10px;
        font-size: 0.9rem;
    }

    .btn-approve,
    .btn-reject {
        padding: 4px 8px;
        font-size: 0.85rem;
    }
}

@media (max-width: 576px) {
    .admin-requests-container {
        padding: 5px;
    }

    .admin-requests-container h2 {
        font-size: 1.25rem;
    }

    .requests-table th,
    .requests-table td {
        padding: 8px;
        font-size: 0.85rem;
    }

    .btn-approve,
    .btn-reject {
        padding: 3px 6px;
        font-size: 0.8rem;
    }
}
</style>

<div class="admin-requests-container">
    <h2>Quản Lý Yêu Cầu Thăng Chức/Tăng Lương/Đổi Chức Vụ/Chuyển Phòng Ban</h2>

    <!-- Hiển thị thông báo -->
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Danh sách yêu cầu -->
    <div class="requests-table">
        <table>
            <thead>
                <tr>
                    <th>Nhân viên</th>
                    <th>Loại yêu cầu</th>
                    <th>Lý do</th>
                    <th>Mức lương mong muốn</th>
                    <th>Chức vụ hiện tại</th>
                    <th>Chức vụ mong muốn</th>
                    <th>Phòng ban hiện tại</th>
                    <th>Phòng ban mong muốn</th>
                    <th>Trạng thái</th>
                    <th>Ngày gửi</th>
                    <th>Điểm</th>
                    <th>Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><?= htmlspecialchars($request['full_name']) ?></td>
                        <td>
                            <?php
                            switch ($request['request_type']) {
                                case 'promotion':
                                    echo 'Thăng chức';
                                    break;
                                case 'salary_increase':
                                    echo 'Tăng lương';
                                    break;
                                case 'position_change':
                                    echo 'Đổi chức vụ';
                                    break;
                                case 'department_change':
                                    echo 'Chuyển phòng ban';
                                    break;
                            }
                            ?>
                        </td>
                        <td><?= htmlspecialchars($request['reason']) ?></td>
                        <td><?= $request['desired_salary'] ? number_format($request['desired_salary'], 0, ',', '.') . ' VNĐ' : 'N/A' ?></td>
                        <td><?= htmlspecialchars($request['current_position'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($request['desired_position'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($request['current_department'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($request['desired_department'] ?? 'N/A') ?></td>
                        <td class="status-<?= $request['status'] ?>">
                            <?php
                            switch ($request['status']) {
                                case 'pending':
                                    echo 'Đang chờ duyệt';
                                    break;
                                case 'approved':
                                    echo 'Đã phê duyệt';
                                    break;
                                case 'rejected':
                                    echo 'Đã từ chối';
                                    break;
                            }
                            ?>
                        </td>
                        <td><?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></td>
                        <td class="score-column">
                            <?php 
                            if ($request['test_status'] === 'graded' && $request['score'] !== null) {
                                echo $request['score'] . '/10';
                            } elseif ($request['test_status'] === 'pending') {
                                echo 'Chưa làm';
                            } elseif ($request['test_status'] === 'submitted') {
                                echo 'Đã nộp, chưa chấm';
                            } elseif ($request['test_status'] === null) {
                                echo 'Chưa có bài test';
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ($request['status'] === 'pending'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn-approve">Phê duyệt</button>
                                </form>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="btn-reject">Từ chối</button>
                                </form>
                            <?php else: ?>
                                <span>Đã xử lý</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once '../layouts/footer.php';
?>