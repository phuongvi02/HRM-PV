<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";
require_once '../layouts/header_employee.php';
require_once '../layouts/sidebar_employee.php';
require_once '../layouts/navbar_employee.php';

// Kiểm tra phiên làm việc và quyền truy cập
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id'])) {
    echo "<script>alert('Vui lòng đăng nhập để truy cập!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

if ($_SESSION['role_id'] != 5) {
    if (in_array($_SESSION['role_id'], [1, 2, 4])) {
        echo "<script>alert('Bạn không có quyền gửi yêu cầu tạm ứng (chỉ dành cho nhân viên)!'); window.location.href='/HRMpv/views/manager/advance_requests.php';</script>";
        exit();
    } else {
        echo "<script>alert('Bạn không có quyền truy cập trang này! Role ID hiện tại: " . $_SESSION['role_id'] . " (yêu cầu role_id = 5)'); window.location.href='/HRMpv/views/auth/login.php';</script>";
        exit();
    }
}

$employee_id = $_SESSION['user_id'];
$is_employee = ($_SESSION['role_id'] == 5);

$db = Database::getInstance()->getConnection();

// Lấy thông tin nhân viên và lương cơ bản từ hợp đồng mới nhất
$stmt = $db->prepare("
    SELECT e.*, c.salary AS contract_salary
    FROM employees e
    LEFT JOIN contracts c ON e.id = c.employee_id
    WHERE e.id = :employee_id
    AND (c.end_date IS NULL OR c.end_date >= CURDATE())
    ORDER BY c.start_date DESC
    LIMIT 1
");
$stmt->execute([':employee_id' => $employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    echo "<script>alert('Không tìm thấy thông tin nhân viên hoặc hợp đồng!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

// Sử dụng lương từ hợp đồng (nếu không có thì dùng mặc định từ employees.salary)
$base_salary = $employee['contract_salary'] ?? $employee['salary'];

// Xử lý thêm yêu cầu tạm ứng
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_employee) {
    $amount = str_replace(',', '', $_POST['amount']);
    $reason = $_POST['reason'];
    $status = 'Chờ duyệt';

    try {
        $max_advance = $base_salary * 0.7;
        if ($amount > $max_advance) {
            echo "<script>alert('Số tiền tạm ứng không được vượt quá 70% lương!'); window.history.back();</script>";
            exit();
        }

        $sql = "INSERT INTO salary_advances (employee_id, amount, reason, status, request_date) 
                VALUES (:employee_id, :amount, :reason, :status, NOW())";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':amount' => $amount,
            ':reason' => $reason,
            ':status' => $status
        ]);

        echo "<script>alert('Yêu cầu tạm ứng đã được gửi!'); window.location.href='salary-advance.php';</script>";
    } catch (PDOException $e) {
        echo "<script>alert('Có lỗi xảy ra: " . htmlspecialchars($e->getMessage()) . "');</script>";
    }
}

// Lấy danh sách tạm ứng của nhân viên
$advances = $db->prepare("
    SELECT * FROM salary_advances
    WHERE employee_id = :employee_id
    ORDER BY request_date DESC
");
$advances->execute([':employee_id' => $employee_id]);
$advanceList = $advances->fetchAll(PDO::FETCH_ASSOC);

// Tính tổng số tiền đã tạm ứng trong tháng hiện tại
$currentMonth = date('m');
$currentYear = date('Y');
$stmt = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) as total_advance
    FROM salary_advances
    WHERE employee_id = :employee_id 
    AND MONTH(request_date) = :month 
    AND YEAR(request_date) = :year
    AND status = 'Đã duyệt'
");
$stmt->execute([
    ':employee_id' => $employee_id,
    ':month' => $currentMonth,
    ':year' => $currentYear
]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$totalAdvanceThisMonth = $result['total_advance'];

$maxAdvance = $base_salary * 0.7;
$remainingAdvance = $maxAdvance - $totalAdvanceThisMonth;
?>

<link rel="stylesheet" href="/HRMpv/public/css/salary.css">
<link rel="stylesheet" href="/HRMpv/public/css/index_nv.css">
<div class="advance-container">
    <h2>Yêu Cầu Tạm Ứng Lương</h2>
    
    <div class="advance-summary">
        <h4>Thông tin tạm ứng:</h4>
        <p>Lương cơ bản (theo hợp đồng): <strong><?= number_format($base_salary, 0, ',', '.') ?> VNĐ</strong></p>
        <p>Tổng đã tạm ứng tháng này: <strong><?= number_format($totalAdvanceThisMonth, 0, ',', '.') ?> VNĐ</strong></p>
        <p>Số tiền còn có thể tạm ứng: <strong><?= number_format($remainingAdvance, 0, ',', '.') ?> VNĐ</strong></p>
        <p><small>* Tối đa 70% lương cơ bản (<?= number_format($maxAdvance, 0, ',', '.') ?> VNĐ)</small></p>
    </div>

    <?php if ($is_employee): ?>
        <div class="form-section">
            <h3>Tạo Yêu Cầu Tạm Ứng</h3>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Số Tiền Tạm Ứng:</label>
                    <input type="text" name="amount" class="form-control" required
                           placeholder="Nhập số tiền"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',')">
                    <small class="text-muted">Tối đa <?= number_format($remainingAdvance, 0, ',', '.') ?> VNĐ</small>
                </div>
                <div class="form-group">
                    <label>Lý Do:</label>
                    <textarea name="reason" class="form-control" required rows="3" 
                              placeholder="Vui lòng nêu rõ lý do tạm ứng..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary" <?= $remainingAdvance <= 0 ? 'disabled' : '' ?>>
                    Gửi Yêu Cầu
                </button>
                <?php if ($remainingAdvance <= 0): ?>
                    <small class="text-danger">Bạn đã đạt giới hạn tạm ứng trong tháng này</small>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <div class="advance-list">
        <h3>Lịch Sử Tạm Ứng</h3>
        <?php if (empty($advanceList)): ?>
            <div class="empty-state">
                <p class="text-muted">Bạn chưa có yêu cầu tạm ứng nào</p>
            </div>
        <?php else: ?>
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Số Tiền</th>
                        <th>Lý Do</th>
                        <th>Trạng Thái</th>
                        <th>Ngày Yêu Cầu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($advanceList as $advance): ?>
                        <tr>
                            <td><?= number_format($advance['amount'], 0, ',', '.') ?> VNĐ</td>
                            <td><?= htmlspecialchars($advance['reason']) ?></td>
                            <td><span class="status-<?= strtolower(str_replace(' ', '-', $advance['status'])) ?>">
                                <?= htmlspecialchars($advance['status']) ?></span></td>
                            <td><?= date('d/m/Y H:i', strtotime($advance['request_date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelector('input[name="amount"]')?.addEventListener('change', function() {
    const maxAmount = <?= $remainingAdvance ?>;
    const inputValue = parseInt(this.value.replace(/,/g, ''));
    if (inputValue > maxAmount) {
        alert('Số tiền tạm ứng không được vượt quá ' + maxAmount.toLocaleString('vi-VN') + ' VNĐ');
        this.value = maxAmount.toLocaleString('vi-VN');
    }
});
</script>