<?php
ob_start();
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';

$db = Database::getInstance()->getConnection();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Chỉ cho phép HR truy cập (role_id = 3)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

// Xử lý filter
$employee_id = isset($_GET['employee_id']) ? $_GET['employee_id'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Lấy danh sách nhân viên cho filter
$employees = $db->query("SELECT id, full_name FROM employees ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// Xử lý duyệt/từ chối yêu cầu nghỉ phép
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $leave_id = $_POST['leave_id'];
    $action = $_POST['action'];
    $hr_id = $_SESSION['user_id']; // Lấy ID của HR đang đăng nhập
    
    $new_status = ($action === 'approve') ? 'Đã duyệt' : 'Từ chối';
    
    $stmt = $db->prepare("
        UPDATE leave_requests 
        SET status = :status,
            updated_at = NOW(),
            approved_by = :approved_by
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $new_status,
        ':approved_by' => $hr_id,
        ':id' => $leave_id
    ]);
    
    header("Location: leave-history.php?year=$year&employee_id=$employee_id&status=$status&success=Yêu cầu đã được " . ($action === 'approve' ? 'duyệt' : 'từ chối'));
    exit();
}

// Xây dựng query với filter
$query = "
    SELECT lr.*, e.full_name, d.name as department_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE YEAR(lr.start_date) = :year
";

$params = [':year' => $year];

if ($employee_id) {
    $query .= " AND lr.employee_id = :employee_id";
    $params[':employee_id'] = $employee_id;
}

if ($status) {
    $query .= " AND lr.status = :status";
    $params[':status'] = $status;
}

$query .= " ORDER BY lr.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$leaveHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tính số ngày nghỉ còn lại trong năm cho mỗi nhân viên
function getRemainingLeaveDays($db, $employee_id, $year) {
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(
                DATEDIFF(end_date, start_date) + 1
            ), 0) as used_days
        FROM leave_requests
        WHERE employee_id = :employee_id
        AND YEAR(start_date) = :year
        AND status = 'Đã duyệt'
    ");
    
    $stmt->execute([
        ':employee_id' => $employee_id,
        ':year' => $year
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return 12 - (int)$result['used_days']; // Giả sử mỗi nhân viên có 12 ngày nghỉ mỗi năm
}

// Hàm helper để lấy class cho status
function getStatusClass($status) {
    switch ($status) {
        case 'Chờ duyệt':
            return 'status-pending';
        case 'Đã duyệt':
            return 'status-approved';
        case 'Từ chối':
            return 'status-rejected';
        default:
            return '';
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lịch Sử Nghỉ Phép</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Reset và cài đặt chung */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            color: #333;
            font-size: 14px;
            line-height: 1.6;
            background-color: transparent !important;
        }

        .history-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        h2 {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-bottom: 20px;
        }

        .filter-section {
            margin-bottom: 20px;
        }

        .form-inline {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 14px;
            font-weight: 500;
            color: #5a5c69;
            margin-bottom: 5px;
        }

        .form-control {
            width: 200px;
            padding: 8px 12px;
            font-size: 14px;
            border: 1px solid #d1d3e2;
            border-radius: 4px;
            color: #333;
        }

        .form-control:focus {
            border-color: #4e73df;
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
            outline: none;
        }

        .btn {
            padding: 8px 15px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .btn-primary {
            background-color: #4e73df;
            color: #fff;
        }

        .btn-primary:hover {
            background-color: #2e59d9;
        }

        .btn-secondary {
            background-color: #858796;
            color: #fff;
        }

        .btn-secondary:hover {
            background-color: #717384;
        }

        .btn-success {
            background-color: #1cc88a;
            color: #fff;
        }

        .btn-success:hover {
            background-color: #17a673;
        }

        .btn-danger {
            background-color: #e74a3b;
            color: #fff;
        }

        .btn-danger:hover {
            background-color: #c53727;
        }

        .remaining-days {
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
            background-color: #e9ecef;
        }

        .remaining-days.warning {
            background-color: #fff3cd;
            color: #856404;
        }

        .remaining-days.danger {
            background-color: #f8d7da;
            color: #721c24;
        }

        .history-table {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .table thead {
            background-color: #e0e7ff;
        }

        .table th,
        .table td {
            padding: 10px;
            text-align: left;
            vertical-align: middle;
            border-bottom: 1px solid #e3e6f0;
        }

        .table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            color: #333;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-pending {
            background-color: #f6c23e;
            color: #333;
        }

        .status-approved {
            background-color: #1cc88a;
            color: #fff;
        }

        .status-rejected {
            background-color: #e74a3b;
            color: #fff;
        }

        .no-results {
            text-align: center;
            padding: 20px;
            color: #5a5c69;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .form-inline {
                flex-direction: column;
                align-items: stretch;
            }

            .form-control {
                width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .table th,
            .table td {
                padding: 8px;
                font-size: 12px;
            }
        }

        .content {
            flex: initial !important;
            padding: 0 !important;
            margin-top: 0 !important;
            transition: none !important;
        }
        /* override.css */
body {
    min-height: auto !important; /* Ghi đè min-height */
    display: block !important;   /* Ghi đè display: flex */
    flex-direction: initial !important; /* Ghi đè flex-direction */
    padding-top: 0 !important;   /* Ghi đè padding-top */
}
    </style>
</head>
<body>
    <div class="history-container">
        <h2 class="mb-4">Lịch Sử Nghỉ Phép</h2>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_GET['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="filter-section">
            <form method="GET" class="form-inline">
                <div class="form-group">
                    <label>Nhân Viên:</label>
                    <select name="employee_id" class="form-control">
                        <option value="">Tất cả nhân viên</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $employee_id == $emp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Trạng Thái:</label>
                    <select name="status" class="form-control">
                        <option value="">Tất cả trạng thái</option>
                        <option value="Chờ duyệt" <?= $status === 'Chờ duyệt' ? 'selected' : '' ?>>Chờ duyệt</option>
                        <option value="Đã duyệt" <?= $status === 'Đã duyệt' ? 'selected' : '' ?>>Đã duyệt</option>
                        <option value="Từ chối" <?= $status === 'Từ chối' ? 'selected' : '' ?>>Từ chối</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Năm:</label>
                    <select name="year" class="form-control">
                        <?php for ($i = date('Y'); $i >= date('Y') - 5; $i--): ?>
                            <option value="<?= $i ?>" <?= $year == $i ? 'selected' : '' ?>>
                                <?= $i ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Lọc</button>
                <?php if (!empty($_GET)): ?>
                    <a href="leave-history.php" class="btn btn-secondary">Đặt lại</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($employee_id): 
            $remainingDays = getRemainingLeaveDays($db, $employee_id, $year);
            $remainingClass = '';
            if ($remainingDays <= 3) {
                $remainingClass = 'danger';
            } elseif ($remainingDays <= 5) {
                $remainingClass = 'warning';
            }
        ?>
            <div class="remaining-days <?= $remainingClass ?>">
                <strong>Số ngày nghỉ còn lại trong năm <?= $year ?>:</strong>
                <?= $remainingDays ?> ngày
            </div>
        <?php endif; ?>

        <div class="history-table">
            <?php if (empty($leaveHistory)): ?>
                <div class="no-results">
                    <p>Không tìm thấy dữ liệu phù hợp với điều kiện tìm kiếm.</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nhân viên</th>
                            <th>Phòng ban</th>
                            <th>Thời gian nghỉ</th>
                            <th>Số ngày</th>
                            <th>Lý do</th>
                            <th>Trạng thái</th>
                            <th>Ngày yêu cầu</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaveHistory as $leave): 
                            $start = new DateTime($leave['start_date']);
                            $end = new DateTime($leave['end_date']);
                            $days = $start->diff($end)->days + 1;
                            $statusClass = getStatusClass($leave['status']);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($leave['full_name']) ?></td>
                            <td><?= htmlspecialchars($leave['department_name']) ?></td>
                            <td>
                                <?= date('d/m/Y', strtotime($leave['start_date'])) ?> - 
                                <?= date('d/m/Y', strtotime($leave['end_date'])) ?>
                            </td>
                            <td><?= $days ?> ngày</td>
                            <td><?= htmlspecialchars($leave['reason']) ?></td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>">
                                    <?= htmlspecialchars($leave['status']) ?>
                                </span>
                            </td>
                            <td><?= date('d/m/Y H:i', strtotime($leave['created_at'])) ?></td>
                            <td>
                                <?php if ($leave['status'] === 'Chờ duyệt'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="leave_id" value="<?= $leave['id'] ?>">
                                        <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Duyệt</button>
                                        <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm">Từ chối</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">Đã xử lý</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Tự động ẩn thông báo sau 3 giây
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    bootstrap.Alert.getInstance(alert)?.close();
                });
            }, 3000);
        });
    </script>
</body>
</html>

<?php include __DIR__ . '/../layouts/footer.php'; ?>