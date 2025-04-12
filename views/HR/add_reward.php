<?php
session_start();
// Giả định role_id = 3 là HR
if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [3])) {
    header("Location: /HRMpv/views/auth/login.php");
    exit();
}

require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';
$db = Database::getInstance()->getConnection();

// Lấy danh sách nhân viên để chọn (bao gồm id và name)
$employeesStmt = $db->query("SELECT id, full_name FROM employees");

$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

// Xử lý thêm phần thưởng/phạt
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $type = trim($_POST['type'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $date = $_POST['date'] ?? '';

    // Kiểm tra tất cả các trường bắt buộc
    if ($employee_id > 0 && in_array($type, ['Thưởng', 'Phạt']) && $amount > 0 && !empty($reason) && !empty($date)) {
        $stmt = $db->prepare("INSERT INTO rewards (employee_id, type, amount, reason, date) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$employee_id, $type, $amount, $reason, $date]);
        header("Location: add_reward.php?success=1");
        exit();
    } else {
        header("Location: add_reward.php?error=invalid_input");
        exit();
    }
}

include '../layouts/header.php';
?>

<div class="container mt-5">
    <h1>Thêm phần thưởng/phạt cho nhân viên</h1>

    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div class="alert alert-success" id="successMessage">Thêm phần thưởng/phạt thành công!</div>

    <?php elseif (isset($_GET['error']) && $_GET['error'] == 'invalid_input'): ?>
        <div class="alert alert-danger">Dữ liệu không hợp lệ! Vui lòng kiểm tra lại tất cả các trường.</div>
    <?php endif; ?>

    <!-- Hiển thị thông báo nếu không có nhân viên -->
    <?php if (empty($employees)): ?>
        <div class="alert alert-warning">Không có nhân viên nào trong hệ thống. Vui lòng thêm nhân viên trước!</div>
    <?php endif; ?>

    <!-- Form thêm phần thưởng/phạt -->
    <form method="POST">
        <div class="mb-3">
            <label>Nhân viên:</label>
            <select name="employee_id" class="form-control" required>
                <option value="">-- Chọn nhân viên --</option>
                <?php foreach ($employees as $employee): ?>
                    <option value="<?php echo $employee['id']; ?>">
    <?php echo htmlspecialchars($employee['full_name']); ?>
</option>

                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label>Loại:</label>
            <select name="type" class="form-control" required>
                <option value="">-- Chọn loại --</option>
                <option value="Thưởng">Thưởng</option>
                <option value="Phạt">Phạt</option>
            </select>
        </div>
        <div class="mb-3">
            <label>Số tiền (VND):</label>
            <input type="number" name="amount" step="1000" min="1" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Lý do:</label>
            <textarea name="reason" class="form-control" required></textarea>
        </div>
        <div class="mb-3">
            <label>Ngày áp dụng:</label>
            <input type="date" name="date" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Thêm</button>
    </form>
</div>
<h2 class="mt-5">Lịch sử thưởng/phạt</h2>

<?php
$historyStmt = $db->query("SELECT r.id, e.full_name, r.type, r.amount, r.reason, r.date 
                           FROM rewards r 
                           JOIN employees e ON r.employee_id = e.id 
                           ORDER BY r.date DESC");

$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<form method="POST" id="rewardForm">

<?php if (!empty($history)): ?>
    <table class="table table-bordered mt-3">
        <thead>
            <tr>
                <th>Nhân viên</th>
                <th>Loại</th>
                <th>Số tiền (VND)</th>
                <th>Lý do</th>
                <th>Ngày</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $record): ?>
                <tr>
                    <td><?php echo htmlspecialchars($record['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($record['type']); ?></td>
                    <td><?php echo number_format($record['amount'], 0, ',', '.'); ?></td>
                    <td><?php echo htmlspecialchars($record['reason']); ?></td>
                    <td><?php echo htmlspecialchars($record['date']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="alert alert-info">Chưa có lịch sử thưởng/phạt nào.</div>
<?php endif; ?>
<script>
document.addEventListener("DOMContentLoaded", function () {
    // Tự động ẩn thông báo sau 3 giây
    setTimeout(function () {
        let successMessage = document.getElementById("successMessage");
        if (successMessage) {
            successMessage.style.display = "none";
        }
    }, 3000);

    // Reset form khi nhấn nút submit
    document.getElementById("rewardForm").addEventListener("submit", function () {
        setTimeout(() => {
            this.reset(); // Xóa dữ liệu nhập trong form
        }, 500);
    });
});
</script>

<?php include '../layouts/footer.php'; ?>