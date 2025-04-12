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

// Lấy lịch sử yêu cầu làm thêm giờ
$overtime_history_query = "SELECT * FROM overtime_requests WHERE employee_id = :employee_id ORDER BY created_at DESC";
$overtime_history_stmt = $db->prepare($overtime_history_query);
$overtime_history_stmt->execute([':employee_id' => $employee_id]);
$overtime_history = $overtime_history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Xử lý gửi yêu cầu làm thêm giờ
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_date = $_POST['request_date'];
    $hours = $_POST['hours'];
    $reason = $_POST['reason'];
    $status = 'Pending';

    try {
        // Kiểm tra xem nhân viên đã gửi yêu cầu trong tháng này chưa
        $month = date('Y-m', strtotime($request_date));
        $sql_check = "SELECT COUNT(*) as overtime_count FROM overtime_requests WHERE employee_id = :employee_id AND DATE_FORMAT(request_date, '%Y-%m') = :month";
        $stmt_check = $db->prepare($sql_check);
        $stmt_check->execute([':employee_id' => $employee_id, ':month' => $month]);
        $result = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($result['overtime_count'] >= 1) {
            echo "<script>alert('Bạn chỉ được phép làm thêm giờ 1 ngày trong tháng này!'); window.history.back();</script>";
            exit();
        }

        // Thêm yêu cầu làm thêm giờ
        $sql = "INSERT INTO overtime_requests (employee_id, request_date, hours, reason, status, created_at) 
                VALUES (:employee_id, :request_date, :hours, :reason, :status, NOW())";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':request_date' => $request_date,
            ':hours' => $hours,
            ':reason' => $reason,
            ':status' => $status
        ]);

        // Tự động tải lại trang sau khi gửi thành công
        echo "<script>alert('Yêu cầu làm thêm giờ đã được gửi!'); window.location.href='overtime-request.php';</script>";
        exit();
    } catch (PDOException $e) {
        echo "<script>alert('Có lỗi xảy ra: " . htmlspecialchars($e->getMessage()) . "');</script>";
    }
}

// Đặt ngày mặc định là ngày hôm nay
$default_request_date = date('Y-m-d');
?>

<link rel="stylesheet" href="/HRMpv/public/css/styles.css">

<style>
.overtime-container {
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

.info-card, .overtime-summary, .history-section {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.overtime-summary {
    background: #e9ecef;
}

.overtime-summary strong {
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
    .overtime-container {
        margin-left: 250px; /* Đảm bảo không bị che bởi sidebar */
    }
}

@media (max-width: 768px) {
    .overtime-container {
        margin-left: 0; /* Ẩn sidebar trên mobile */
        padding: 10px;
    }

    .form-section, .info-card, .overtime-summary, .history-section {
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
    .overtime-container {
        padding: 5px;
    }

    .form-section, .info-card, .overtime-summary, .history-section {
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

    .overtime-summary strong {
        font-size: 0.9rem;
    }
}
</style>
<link rel="stylesheet" href="/HRMpv/public/css/index_nv.css">
<div class="overtime-container">
    <h2>Đơn Xin Làm Thêm Giờ</h2>
    
    <div class="overtime-summary">
        <h4>Thông tin làm thêm giờ của bạn:</h4>
        <p>Số lần làm thêm giờ trong tháng này: 
            <strong>
                <?php
                $current_month = date('Y-m');
                $monthly_count_query = "SELECT COUNT(*) as count FROM overtime_requests WHERE employee_id = :employee_id AND DATE_FORMAT(request_date, '%Y-%m') = :month";
                $monthly_stmt = $db->prepare($monthly_count_query);
                $monthly_stmt->execute([':employee_id' => $employee_id, ':month' => $current_month]);
                $monthly_count = $monthly_stmt->fetch(PDO::FETCH_ASSOC)['count'];
                echo $monthly_count;
                ?> lần
            </strong>
        </p>
    </div>

    <div class="info-card">
        <h4>Quy định làm thêm giờ:</h4>
        <ul>
            <li>Mỗi nhân viên chỉ được làm thêm giờ 1 ngày trong tháng</li>
            <li>Đơn xin làm thêm giờ phải được HR phê duyệt</li>
            <li>Số giờ làm thêm tối đa là 8 giờ/ngày</li>
        </ul>
    </div>

    <div class="form-section">
        <h3>Tạo Đơn Xin Làm Thêm Giờ</h3>
        <form method="POST" action="">
            <div class="form-group">
                <label>Ngày Làm Thêm Giờ:</label>
                <input type="date" name="request_date" class="form-control" required
                       value="<?= $default_request_date ?>">
            </div>

            <div class="form-group">
                <label>Số Giờ Làm Thêm:</label>
                <input type="number" name="hours" class="form-control" required
                       min="1" max="8" step="1">
                <small class="text-muted">Tối đa 8 giờ</small>
            </div>

            <div class="form-group">
                <label>Lý Do Làm Thêm Giờ:</label>
                <textarea name="reason" class="form-control" required rows="3" 
                          placeholder="Vui lòng nêu rõ lý do xin làm thêm giờ..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Gửi Đơn Xin Làm Thêm Giờ</button>
        </form>
    </div>

    <!-- Hiển thị lịch sử làm thêm giờ -->
    <div class="history-section">
        <h3>Lịch Sử Yêu Cầu Làm Thêm Giờ</h3>
        <?php if (empty($overtime_history)): ?>
            <div class="no-data">Bạn chưa có yêu cầu làm thêm giờ nào.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Ngày Yêu Cầu</th>
                        <th>Số Giờ</th>
                        <th>Lý Do</th>
                        <th>Trạng Thái</th>
                        <th>Ngày Gửi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overtime_history as $request): ?>
                        <tr>
                            <td><?= htmlspecialchars($request['request_date']) ?></td>
                            <td><?= htmlspecialchars($request['hours']) ?></td>
                            <td><?= htmlspecialchars($request['reason']) ?></td>
                            <td class="status-<?= strtolower($request['status']) ?>">
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

<?php
require_once '../layouts/footer.php';
?>