<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../views/layouts/sidebar_kt.php';
require_once __DIR__ . '/../../views/layouts/header_kt.php';

// Kiểm tra phiên làm việc và quyền truy cập
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id'])) {
    echo "<script>alert('Vui lòng đăng nhập để truy cập!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

if ($_SESSION['role_id'] == 5) {
    // Nếu là nhân viên, chuyển hướng về trang salary-advance.php
    echo "<script>alert('Bạn không có quyền truy cập trang này (chỉ dành cho quản lý/HR/Payroll)!'); window.location.href='/HRMpv/views/employee/salary-advance.php';</script>";
    exit();
}

if (!in_array($_SESSION['role_id'], [1, 2, 4])) {
    echo "<script>alert('Bạn không có quyền truy cập trang này (yêu cầu role_id = 1, 2, hoặc 4)!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

$db = Database::getInstance()->getConnection();

// Lấy danh sách yêu cầu tạm ứng chưa xử lý
$stmt = $db->prepare("
    SELECT sa.*, e.full_name, e.salary 
    FROM salary_advances sa 
    JOIN employees e ON sa.employee_id = e.id 
    WHERE sa.status = 'Chờ duyệt'
    ORDER BY sa.request_date DESC
");
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy lịch sử tạm ứng (bao gồm Đã duyệt và Từ chối)
$stmt_history = $db->prepare("
    SELECT sa.*, e.full_name 
    FROM salary_advances sa 
    JOIN employees e ON sa.employee_id = e.id 
    WHERE sa.status IN ('Đã duyệt', 'Từ chối')
    ORDER BY sa.request_date DESC
");
$stmt_history->execute();
$history = $stmt_history->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Danh Sách Yêu Cầu Tạm Ứng Lương</title>
    <link rel="stylesheet" href="/HRMpv/public/css/styles.css">
    <style>
        .requests-container { padding: 20px; max-width: 1200px; margin: 0 auto; }
        .table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .table th { background: #f5f5f5; }
        .btn-approve { background: #5cb85c; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-reject { background: #d9534f; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-approve:hover { background: #4cae4c; }
        .btn-reject:hover { background: #c9302c; }
        .empty-state { text-align: center; padding: 30px; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .history-section { margin-top: 20px; }
        .status-pending { color: #f0ad4e; }
        .status-approved { color: #5cb85c; }
        .status-rejected { color: #d9534f; }
    </style>
</head>
<body>
    <div class="requests-container">
        <h2>Danh Sách Yêu Cầu Tạm Ứng Lương</h2>

        <?php if (empty($requests)): ?>
            <div class="empty-state">
                <p class="text-muted">Hiện tại không có yêu cầu tạm ứng nào đang chờ duyệt.</p>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Mã Yêu Cầu</th>
                        <th>Tên Nhân Viên</th>
                        <th>Số Tiền</th>
                        <th>Lý Do</th>
                        <th>Ngày Yêu Cầu</th>
                        <th>Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <tr>
                            <td>#<?= htmlspecialchars($request['id']) ?></td>
                            <td><?= htmlspecialchars($request['full_name']) ?></td>
                            <td><?= number_format($request['amount'], 0, ',', '.') ?> VNĐ</td>
                            <td><?= htmlspecialchars($request['reason']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($request['request_date'])) ?></td>
                            <td>
                                <a href="process_salary_advance.php?advance_id=<?= $request['id'] ?>&action=approve" 
                                   class="btn-approve" 
                                   onclick="return confirm('Bạn có chắc chắn muốn phê duyệt yêu cầu này?');">Phê duyệt</a>
                                <a href="process_salary_advance.php?advance_id=<?= $request['id'] ?>&action=reject" 
                                   class="btn-reject" 
                                   onclick="return confirm('Bạn có chắc chắn muốn từ chối yêu cầu này?');">Từ chối</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="history-section">
            <h3>Lịch Sử Tạm Ứng</h3>
            <?php if (empty($history)): ?>
                <div class="empty-state">
                    <p class="text-muted">Hiện tại không có lịch sử tạm ứng nào.</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Mã Yêu Cầu</th>
                            <th>Tên Nhân Viên</th>
                            <th>Số Tiền</th>
                            <th>Lý Do</th>
                            <th>Trạng Thái</th>
                            <th>Ngày Yêu Cầu</th>
                            <th>Ngày Phê Duyệt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $item): ?>
                            <tr>
                                <td>#<?= htmlspecialchars($item['id']) ?></td>
                                <td><?= htmlspecialchars($item['full_name']) ?></td>
                                <td><?= number_format($item['amount'], 0, ',', '.') ?> VNĐ</td>
                                <td><?= htmlspecialchars($item['reason']) ?></td>
                                <td><span class="status-<?= strtolower(str_replace(' ', '-', $item['status'])) ?>">
                                    <?= htmlspecialchars($item['status']) ?></span></td>
                                <td><?= date('d/m/Y H:i', strtotime($item['request_date'])) ?></td>
                                <td><?= $item['approved_date'] ? date('d/m/Y H:i', strtotime($item['approved_date'])) : 'Chưa có' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>