<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";
require_once '../layouts/header_employee.php';
require_once '../layouts/sidebar_employee.php';
require_once '../layouts/navbar_employee.php';

// Kiểm tra phiên làm việc và vai trò
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 5) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

// Lấy employee_id từ session
$employee_id = $_SESSION['user_id'];

$db = Database::getInstance()->getConnection();

// Lấy thông tin nhân viên
$stmt = $db->prepare("SELECT * FROM employees WHERE id = :employee_id");
$stmt->execute([':employee_id' => $employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    echo "<script>alert('Không tìm thấy thông tin nhân viên!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

// Lấy lịch sử yêu cầu nghỉ phép
$leave_history_query = "SELECT * FROM leave_requests WHERE employee_id = :employee_id ORDER BY created_at DESC";
$leave_history_stmt = $db->prepare($leave_history_query);
$leave_history_stmt->execute([':employee_id' => $employee_id]);
$leave_history = $leave_history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Xử lý gửi yêu cầu nghỉ phép
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = $_POST['reason'];
    $status = 'Chờ duyệt';

    try {
        // Kiểm tra yêu cầu phải được gửi trước ít nhất 2 ngày
        $today = new DateTime();
        $start = new DateTime($start_date);
        $interval = $today->diff($start);
        $days_until_start = $interval->days;

        if ($start < $today || $days_until_start < 2) {
            echo "<script>alert('Yêu cầu nghỉ phép phải được gửi trước ít nhất 2 ngày!'); window.history.back();</script>";
            exit();
        }

        // Kiểm tra số ngày nghỉ còn lại
        $used_days = $employee['used_leave_days'] ?? 0;
        $remaining_days = 12 - $used_days;

        // Tính số ngày xin nghỉ
        $end = new DateTime($end_date);
        $days_requested = $start->diff($end)->days + 1;

        if ($days_requested > $remaining_days) {
            echo "<script>alert('Số ngày nghỉ vượt quá số ngày còn lại trong năm! Bạn còn " . $remaining_days . " ngày.'); window.history.back();</script>";
            exit();
        }

        // Thêm yêu cầu nghỉ phép
        $sql = "INSERT INTO leave_requests (employee_id, start_date, end_date, reason, status, created_at) 
                VALUES (:employee_id, :start_date, :end_date, :reason, :status, NOW())";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':start_date' => $start_date,
            ':end_date' => $end_date,
            ':reason' => $reason,
            ':status' => $status
        ]);

        // Tự động tải lại trang sau khi gửi thành công
        echo "<script>alert('Yêu cầu nghỉ phép đã được gửi!'); window.location.href='leave-request.php';</script>";
        exit();
    } catch (PDOException $e) {
        echo "<script>alert('Có lỗi xảy ra: " . htmlspecialchars($e->getMessage()) . "');</script>";
    }
}

// Tính ngày tối thiểu cho ngày bắt đầu (2 ngày sau ngày hiện tại)
$min_start_date = date('Y-m-d', strtotime('+2 days'));
?>

<link rel="stylesheet" href="/HRMpv/public/css/styles.css">
<style>
.leave-container {
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
    min-height: calc(100vh - 200px);
    margin-left: 250px; /* Đảm bảo không bị che bởi sidebar */
    transition: margin-left 0.3s ease;
}

.form-section {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-bottom: 20px;
}

.info-card, .leave-summary, .history-section {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.leave-summary {
    background: #e9ecef;
}

.leave-summary strong {
    color: #28a745;
}

.history-section table {
    width: 100%;
    border-collapse: collapse;
}

.history-section th, .history-section td {
    padding: 10px;
    border: 1px solid #dee2e6;
    text-align: left;
}

.history-section th {
    background: #e9ecef;
    font-weight: bold;
}

.history-section .status-pending {
    color: #ffc107;
    font-weight: bold;
}

.history-section .status-approved {
    color: #28a745;
    font-weight: bold;
}

.history-section .status-rejected {
    color: #dc3545;
    font-weight: bold;
}

.no-data {
    background-color: #f8f9fa;
    font-style: italic;
    text-align: center;
    padding: 20px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    font-weight: bold;
    margin-bottom: 5px;
    display: block;
}

.form-group .form-control {
    width: 100%;
    padding: 8px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    box-sizing: border-box;
}

.form-group small {
    color: #6c757d;
    font-size: 0.875rem;
}

.form-group textarea.form-control {
    resize: vertical;
    min-height: 100px;
}

.btn-primary {
    background-color: #007bff;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.btn-primary:hover {
    background-color: #0056b3;
}

/* Responsive Design */
@media (min-width: 769px) {
    .leave-container {
        margin-left: 250px; /* Đảm bảo không bị che bởi sidebar */
    }
}

@media (max-width: 768px) {
    .leave-container {
        margin-left: 0; /* Ẩn sidebar trên mobile */
        padding: 10px;
    }

    .form-section, .info-card, .leave-summary, .history-section {
        padding: 10px;
    }

    .history-section th, .history-section td {
        padding: 8px;
        font-size: 0.9rem;
    }

    .form-group .form-control {
        padding: 6px;
    }

    .btn-primary {
        padding: 8px 16px;
        font-size: 0.9rem;
    }
}

@media (max-width: 576px) {
    .leave-container {
        padding: 5px;
    }

    .form-section, .info-card, .leave-summary, .history-section {
        padding: 8px;
    }

    .history-section table {
        font-size: 0.85rem;
    }

    .history-section th, .history-section td {
        padding: 6px;
    }

    .form-group label {
        font-size: 0.9rem;
    }

    .form-group .form-control {
        font-size: 0.9rem;
    }

    .form-group textarea.form-control {
        min-height: 80px;
    }

    .btn-primary {
        width: 100%;
        padding: 10px;
        font-size: 0.85rem;
    }

    .leave-summary strong {
        font-size: 0.9rem;
    }
}
</style>
<link rel="stylesheet" href="/HRMpv/public/css/index_nv.css">
<div class="leave-container">
    <h2>Đơn Xin Nghỉ Phép</h2>
    
    <div class="leave-summary">
        <h4>Thông tin nghỉ phép của bạn:</h4>
        <p>Số ngày nghỉ phép đã sử dụng: <strong><?= $employee['used_leave_days'] ?? 0 ?> ngày</strong></p>
        <p>Số ngày nghỉ phép còn lại: <strong><?= 12 - ($employee['used_leave_days'] ?? 0) ?> ngày</strong></p>
    </div>

    <div class="info-card">
        <h4>Quy định nghỉ phép:</h4>
        <ul>
            <li>Mỗi nhân viên có 12 ngày nghỉ phép trong năm</li>
            <li>Không được nghỉ quá số ngày phép còn lại</li>
            <li>Yêu cầu phải được gửi trước ít nhất 2 ngày</li>
            <li>Đơn xin nghỉ phép phải được quản lý phê duyệt</li>
        </ul>
    </div>

    <div class="form-section">
        <h3>Tạo Đơn Xin Nghỉ Phép</h3>
        <form method="POST" action="">
            <div class="form-group">
                <label>Ngày Bắt Đầu:</label>
                <input type="date" name="start_date" class="form-control" required
                       min="<?= $min_start_date ?>">
                <small class="text-muted">Phải đặt trước ít nhất 2 ngày</small>
            </div>

            <div class="form-group">
                <label>Ngày Kết Thúc:</label>
                <input type="date" name="end_date" class="form-control" required
                       min="<?= $min_start_date ?>">
            </div>

            <div class="form-group">
                <label>Lý Do Nghỉ Phép:</label>
                <textarea name="reason" class="form-control" required rows="3" 
                          placeholder="Vui lòng nêu rõ lý do xin nghỉ phép..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Gửi Đơn Xin Nghỉ</button>
        </form>
    </div>

    <!-- Hiển thị lịch sử nghỉ phép -->
    <div class="history-section">
        <h3>Lịch Sử Yêu Cầu Nghỉ Phép</h3>
        <?php if (empty($leave_history)): ?>
            <div class="no-data">Bạn chưa có yêu cầu nghỉ phép nào.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Ngày Bắt Đầu</th>
                        <th>Ngày Kết Thúc</th>
                        <th>Lý Do</th>
                        <th>Trạng Thái</th>
                        <th>Ngày Gửi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leave_history as $request): ?>
                        <tr>
                            <td><?= htmlspecialchars($request['start_date']) ?></td>
                            <td><?= htmlspecialchars($request['end_date']) ?></td>
                            <td><?= htmlspecialchars($request['reason']) ?></td>
                            <td class="status-<?= strtolower(str_replace(' ', '-', $request['status'])) ?>">
                                <?= htmlspecialchars($request['status']) ?>
                            </td>
                            <td><?= htmlspecialchars($request['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelector('input[name="end_date"]').addEventListener('change', function() {
    var startDate = document.querySelector('input[name="start_date"]').value;
    if (this.value < startDate) {
        alert('Ngày kết thúc không thể trước ngày bắt đầu!');
        this.value = startDate;
    }
});

document.querySelector('input[name="start_date"]').addEventListener('change', function() {
    var endDate = document.querySelector('input[name="end_date"]');
    if (endDate.value && this.value > endDate.value) {
        endDate.value = this.value;
    }
});
</script>

<?php
require_once '../layouts/footer.php'; 
?>