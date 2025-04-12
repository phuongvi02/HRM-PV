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

if (!in_array($_SESSION['role_id'], [1, 2, 4])) {
    echo "<script>alert('Bạn không có quyền truy cập trang này (yêu cầu role_id = 1, 2, hoặc 4)!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

$db = Database::getInstance()->getConnection();

// Lấy danh sách nhân viên
$stmt = $db->prepare("SELECT id, full_name FROM employees ORDER BY full_name ASC");
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy lịch sử thưởng/phạt (khi chưa chọn nhân viên)
$history = [];
if (!isset($_POST['employee_id'])) {
    $stmt = $db->prepare("
        SELECT r.*, e.full_name 
        FROM rewards r 
        JOIN employees e ON r.employee_id = e.id 
        ORDER BY r.date DESC
    ");
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Xử lý khi chọn nhân viên
$selected_employee = null;
$attendance_data = null;
if (isset($_POST['employee_id']) && !empty($_POST['employee_id'])) {
    $employee_id = $_POST['employee_id'];
    $stmt = $db->prepare("SELECT full_name FROM employees WHERE id = ?");
    $stmt->execute([$employee_id]);
    $selected_employee = $stmt->fetch(PDO::FETCH_ASSOC);

    // Lấy dữ liệu chấm công của nhân viên
    $stmt = $db->prepare("
        SELECT check_in, check_out, date, late_minutes, early_leave_minutes 
        FROM attendance 
        WHERE employee_id = ?
        ORDER BY COALESCE(date, check_in) DESC
    ");
    $stmt->execute([$employee_id]);
    $attendance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Xử lý thêm thưởng/phạt
if (isset($_POST['submit_reward_penalty'])) {
    try {
        $employee_id = $_POST['employee_id'];
        $type = $_POST['type'] === 'reward' ? 'Thưởng' : 'Phạt'; // Chuyển đổi giá trị type
        $amount = $_POST['amount'];
        $reason = $_POST['reason'];
        $date = date('Y-m-d'); // Chỉ lấy phần DATE, bỏ thời gian

        $stmt = $db->prepare("
            INSERT INTO rewards (employee_id, type, amount, reason, date) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$employee_id, $type, $amount, $reason, $date]);

        echo "<script>alert('Đã thêm " . ($type === 'Thưởng' ? 'thưởng' : 'phạt') . " thành công!'); window.location.href='Reward.php';</script>";
        exit();
    } catch (PDOException $e) {
        echo "<script>alert('Lỗi khi thêm thưởng/phạt: " . addslashes($e->getMessage()) . "'); window.location.href='Reward.php';</script>";
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Thưởng Phạt</title>
    <link rel="stylesheet" href="/HRMpv/public/css/styles.css">
    <style>
        .container { padding: 20px; max-width: 1200px; margin: 0 auto; }
        .form-group { margin-bottom: 15px; }
        .table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .table th { background: #f5f5f5; }
        .btn-submit { background: #5cb85c; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-submit:hover { background: #4cae4c; }
        .attendance-section { margin-top: 20px; }
        .history-section { margin-top: 20px; }
        .type-reward { color: #5cb85c; }
        .type-penalty { color: #d9534f; }
        .empty-state { text-align: center; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="container">
        <h2>Quản Lý Thưởng Phạt</h2>

        <!-- Form chọn nhân viên -->
        <form method="POST" action="">
            <div class="form-group">
                <label for="employee_id">Chọn Nhân Viên:</label>
                <select name="employee_id" id="employee_id" onchange="this.form.submit()">
                    <option value="">-- Chọn nhân viên --</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= $employee['id'] ?>" <?= isset($_POST['employee_id']) && $_POST['employee_id'] == $employee['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($employee['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <!-- Hiển thị lịch sử thưởng/phạt khi chưa chọn nhân viên -->
        <?php if (!$selected_employee): ?>
            <div class="history-section">
                <h3>Lịch Sử Thưởng Phạt</h3>
                <?php if (empty($history)): ?>
                    <div class="empty-state">
                        <p>Không có lịch sử thưởng/phạt nào.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Mã Thưởng/Phạt</th>
                                <th>Tên Nhân Viên</th>
                                <th>Loại</th>
                                <th>Số Tiền</th>
                                <th>Lý Do</th>
                                <th>Ngày</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $item): ?>
                                <tr>
                                    <td>#<?= htmlspecialchars($item['id']) ?></td>
                                    <td><?= htmlspecialchars($item['full_name']) ?></td>
                                    <td><span class="type-<?= $item['type'] === 'Thưởng' ? 'reward' : 'penalty' ?>">
                                        <?= htmlspecialchars($item['type']) ?></span></td>
                                    <td><?= number_format($item['amount'], 2, ',', '.') ?> VNĐ</td>
                                    <td><?= htmlspecialchars($item['reason']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($item['date'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Hiển thị thông tin chấm công và form thưởng/phạt khi đã chọn nhân viên -->
        <?php if ($selected_employee): ?>
            <div class="attendance-section">
                <h3>Thông Tin Chấm Công - <?= htmlspecialchars($selected_employee['full_name']) ?></h3>
                <?php if (empty($attendance_data)): ?>
                    <div class="empty-state">
                        <p>Không có dữ liệu chấm công cho nhân viên này.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ngày Làm Việc</th>
                                <th>Giờ Vào</th>
                                <th>Giờ Ra</th>
                                <th>Đi Muộn (phút)</th>
                                <th>Rời Sớm (phút)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_data as $record): ?>
                                <tr>
                                    <td>
                                        <?php
                                        // Hiển thị ngày từ cột date, nếu date là NULL thì lấy từ check_in
                                        if ($record['date']) {
                                            echo date('d/m/Y', strtotime($record['date']));
                                        } elseif ($record['check_in']) {
                                            echo date('d/m/Y', strtotime($record['check_in']));
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td><?= $record['check_in'] ? date('H:i', strtotime($record['check_in'])) : 'N/A' ?></td>
                                    <td><?= $record['check_out'] ? date('H:i', strtotime($record['check_out'])) : 'N/A' ?></td>
                                    <td><?= $record['late_minutes'] ?? 0 ?></td>
                                    <td><?= $record['early_leave_minutes'] ?? 0 ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Form thêm thưởng/phạt -->
            <h3>Thêm Thưởng/Phạt</h3>
            <form method="POST" action="">
                <input type="hidden" name="employee_id" value="<?= $_POST['employee_id'] ?>">
                <div class="form-group">
                    <label for="type">Loại:</label>
                    <select name="type" id="type" required>
                        <option value="reward">Thưởng</option>
                        <option value="penalty">Phạt</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="amount">Số Tiền (VNĐ):</label>
                    <input type="number" name="amount" id="amount" min="0" step="0.01" required>
                </div>
                <div class="form-group">
                    <label for="reason">Lý Do:</label>
                    <textarea name="reason" id="reason" rows="3" required></textarea>
                </div>
                <button type="submit" name="submit_reward_penalty" class="btn-submit">Xác Nhận</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>