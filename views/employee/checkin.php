<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";
require_once '../layouts/header_employee.php';
require_once '../layouts/sidebar_employee.php';
require_once '../layouts/navbar_employee.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

$db = Database::getInstance()->getConnection();
$employee_id = $_SESSION['user_id'] ?? null;

if (!$employee_id) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

// Lấy thông tin nhân viên
$query = "SELECT full_name, department_id FROM employees WHERE id = :employee_id";
$stmt = $db->prepare($query);
$stmt->execute([':employee_id' => $employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// Kiểm tra xem đã check-in hôm nay chưa
$query = "SELECT id, check_in, status, approval_note, explanation, explanation_status 
          FROM attendance WHERE employee_id = :employee_id AND DATE(check_in) = CURDATE()";
$stmt = $db->prepare($query);
$stmt->execute([':employee_id' => $employee_id]);
$attendance = $stmt->fetch(PDO::FETCH_ASSOC);

$alreadyCheckedIn = !empty($attendance);
$check_in_time = $alreadyCheckedIn ? new DateTime($attendance['check_in']) : null;
$current_status = $attendance['status'] ?? '';
$approval_note = $attendance['approval_note'] ?? '';
$explanation = $attendance['explanation'] ?? '';
$explanation_status = $attendance['explanation_status'] ?? 'pending';
$attendance_id = $attendance['id'] ?? null;

// Xác định trạng thái check-in
$standard_check_in = new DateTime('08:00:00');
$current_time = new DateTime();
$check_in_status = '';

if ($alreadyCheckedIn) {
    if ($check_in_time < $standard_check_in) {
        $check_in_status = '<span class="badge bg-success">Sớm</span>';
    } elseif ($check_in_time > $standard_check_in) {
        $check_in_status = '<span class="badge bg-danger">Muộn</span>';
    } else {
        $check_in_status = '<span class="badge bg-dark">Đúng giờ</span>';
    }
}

// Xử lý check-in
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkin') {
    if ($alreadyCheckedIn) {
        $error_message = "Bạn chỉ được phép check-in một lần mỗi ngày. Hôm nay bạn đã check-in lúc " . $check_in_time->format('H:i:s') . ".";
    } else {
        try {
            $current_time = date('Y-m-d H:i:s');
            $query = "INSERT INTO attendance (employee_id, check_in, status) VALUES (:employee_id, :check_in, 'pending')";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':employee_id' => $employee_id,
                ':check_in' => $current_time
            ]);
            
            $attendance_id = $db->lastInsertId();
            $check_in_time = new DateTime($current_time);
            if ($check_in_time < $standard_check_in) {
                $check_in_status = '<span class="badge bg-success">Sớm</span>';
            } elseif ($check_in_time > $standard_check_in) {
                $check_in_status = '<span class="badge bg-danger">Muộn</span>';
            } else {
                $check_in_status = '<span class="badge bg-dark">Đúng giờ</span>';
            }
            
            $alreadyCheckedIn = true;
            $current_status = 'pending';
            $success_message = "Check-in thành công lúc " . date('H:i:s', strtotime($current_time)) . ". Đang chờ HR phê duyệt.";
        } catch (PDOException $e) {
            $error_message = "Lỗi: " . $e->getMessage();
        }
    }
}

// Xử lý gửi giải trình (giữ nguyên phần này)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_explanation') {
    $explanation_text = trim($_POST['explanation'] ?? '');
    if ($explanation_text && $attendance_id) {
        try {
            $query = "UPDATE attendance SET explanation = :explanation, explanation_status = 'pending' WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':explanation' => $explanation_text,
                ':id' => $attendance_id
            ]);
            $explanation = $explanation_text;
            $explanation_status = 'pending';
            $success_message = "Giải trình của bạn đã được gửi. Đang chờ HR xem xét.";
        } catch (PDOException $e) {
            $error_message = "Lỗi khi gửi giải trình: " . $e->getMessage();
        }
    } else {
        $error_message = "Vui lòng nhập nội dung giải trình!";
    }
}
?>
   <link rel="stylesheet" href="/HRMpv/public/css/index_nv.css">
<!-- HTML và phần còn lại của code giữ nguyên -->
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h2 class="mb-0 text-center">Chấm Công - Check-in</h2>
                </div>
                <div class="card-body text-center">
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success">
                            <?= $success_message ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger">
                            <?= $error_message ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="time-display mb-4" id="currentTime"></div>
                    
                    <div class="employee-info mb-4">
                        <h4><?= htmlspecialchars($employee['full_name'] ?? 'Nhân viên') ?></h4>
                        <p class="text-muted">Giờ làm việc: 08:00 - 17:00 (Yêu cầu đủ 8 tiếng mỗi ngày)</p>
                    </div>
                    
                    <?php if ($alreadyCheckedIn): ?>
                        <?php if ($current_status === 'pending'): ?>
                            <div class="alert alert-warning">
                                <p class="mb-0">Bạn đã check-in lúc <strong><?= $check_in_time->format('H:i:s') ?></strong></p>
                                <p class="mb-0">Trạng thái: <?= $check_in_status ?></p>
                                <p class="text-muted mt-2">Đang xử lý bởi HR. Vui lòng chờ để bắt đầu làm việc.</p>
                            </div>
                            <?php if ($check_in_time > $standard_check_in && !$explanation): ?>
                                <div class="alert alert-danger mt-3">
                                    <p class="mb-2">Bạn đã check-in muộn! Vui lòng gửi giải trình dưới đây trong 24 giờ, nếu không sẽ bị trừ lương.</p>
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="submit_explanation">
                                        <textarea class="form-control mb-2" name="explanation" rows="3" placeholder="Nhập lý do check-in muộn..."></textarea>
                                        <button type="submit" class="btn btn-warning">Gửi Giải Trình</button>
                                    </form>
                                </div>
                            <?php elseif ($explanation): ?>
                                <div class="alert alert-info mt-3">
                                    <p class="mb-0">Giải trình của bạn: <strong><?= htmlspecialchars($explanation) ?></strong></p>
                                    <p class="mb-0">Trạng thái: <span class="badge <?= $explanation_status == 'pending' ? 'bg-warning text-dark' : ($explanation_status == 'approved' ? 'bg-success' : 'bg-danger') ?>">
                                        <?= $explanation_status == 'pending' ? 'Đang xử lý' : ($explanation_status == 'approved' ? 'Đã duyệt' : 'Từ chối') ?></span></p>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($current_status === 'approved_checkin' || $current_status === 'approved'): ?>
                            <div class="alert alert-success">
                                <p class="mb-0">Bạn đã check-in lúc <strong><?= $check_in_time->format('H:i:s') ?></strong></p>
                                <p class="mb-0">Trạng thái: <?= $check_in_status ?></p>
                                <p class="text-muted mt-2">Đã được HR phê duyệt. Bạn có thể bắt đầu làm việc.</p>
                                <?php if ($approval_note): ?>
                                    <p class="mt-2"><strong>Ghi chú từ HR:</strong> <?= htmlspecialchars($approval_note) ?></p>
                                <?php endif; ?>
                                <?php if ($explanation): ?>
                                    <p class="mt-2"><strong>Giải trình của bạn:</strong> <?= htmlspecialchars($explanation) ?> 
                                        (<span class="badge <?= $explanation_status == 'approved' ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $explanation_status == 'approved' ? 'Đã duyệt' : 'Từ chối' ?></span>)</p>
                                <?php endif; ?>
                            </div>
                            <a href="checkout.php" class="btn btn-danger btn-lg mt-3">
                                <i class="fas fa-sign-out-alt me-2"></i> Đi đến Checkout
                            </a>
                        <?php elseif ($current_status === 'rejected'): ?>
                            <div class="alert alert-danger">
                                <p class="mb-0">Check-in lúc <strong><?= $check_in_time->format('H:i:s') ?></strong> đã bị từ chối</p>
                                <p class="mb-0">Trạng thái: <?= $check_in_status ?></p>
                                <p class="text-muted mt-2">HR đã từ chối. Bạn không được phép làm việc hôm nay.</p>
                                <?php if ($approval_note): ?>
                                    <p class="mt-2"><strong>Ghi chú từ HR:</strong> <?= htmlspecialchars($approval_note) ?></p>
                                <?php endif; ?>
                                <?php if ($explanation): ?>
                                    <p class="mt-2"><strong>Giải trình của bạn:</strong> <?= htmlspecialchars($explanation) ?> 
                                        (<span class="badge <?= $explanation_status == 'approved' ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $explanation_status == 'approved' ? 'Đã duyệt' : 'Từ chối' ?></span>)</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="checkin">
                            <button type="submit" class="btn btn-success btn-lg pulse-button">
                                <i class="fas fa-sign-in-alt me-2"></i> Check-in Ngay
                            </button>
                        </form>
                        
                        <?php if ($current_time > $standard_check_in): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="fas fa-exclamation-triangle me-2"></i> Bạn đang đến muộn! Giờ làm việc bắt đầu lúc 08:00.
                                <p class="mb-0 mt-2">Lưu ý: Nếu check-in muộn, bạn cần gửi giải trình trong 24 giờ.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card mt-4 shadow">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0">Lịch sử chấm công gần đây</h4>
                </div>
                <div class="card-body">
                    <?php
                    $query = "SELECT check_in, check_out, status, approval_note, explanation, explanation_status 
                              FROM attendance WHERE employee_id = :employee_id ORDER BY check_in DESC LIMIT 5";
                    $stmt = $db->prepare($query);
                    $stmt->execute([':employee_id' => $employee_id]);
                    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (count($history) > 0):
                    ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Ngày</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Thời gian làm việc</th>
                                    <th>Trạng thái</th>
                                    <th>Ghi chú HR</th>
                                    <th>Giải trình</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $record): 
                                    $check_in = new DateTime($record['check_in']);
                                    $hours_worked = 0;
                                    $statusBadge = '';
                                    
                                    switch ($record['status']) {
                                        case 'pending':
                                            $statusBadge = '<span class="badge bg-warning text-dark">Đang xử lý</span>';
                                            break;
                                        case 'approved_checkin':
                                            $statusBadge = '<span class="badge bg-primary">Đã duyệt Check-in</span>';
                                            break;
                                        case 'approved':
                                            $statusBadge = '<span class="badge bg-success">Đã duyệt</span>';
                                            break;
                                        case 'rejected':
                                            $statusBadge = '<span class="badge bg-danger">Đã từ chối</span>';
                                            break;
                                        case 'absent':
                                            $statusBadge = '<span class="badge bg-secondary">Vắng mặt</span>';
                                            break;
                                        case 'leave':
                                            $statusBadge = '<span class="badge bg-info">Nghỉ phép</span>';
                                            break;
                                    }
                                    
                                    if (!empty($record['check_out'])) {
                                        $check_out = new DateTime($record['check_out']);
                                        $interval = $check_in->diff($check_out);
                                        $hours_worked = $interval->h + ($interval->i / 60);
                                    }
                                ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($record['check_in'])) ?></td>
                                    <td><?= date('H:i:s', strtotime($record['check_in'])) ?></td>
                                    <td><?= !empty($record['check_out']) ? date('H:i:s', strtotime($record['check_out'])) : '<span class="badge bg-info">Đang làm việc</span>' ?></td>
                                    <td><?= !empty($record['check_out']) ? sprintf("%.2f giờ", $hours_worked) : '-' ?></td>
                                    <td><?= $statusBadge ?></td>
                                    <td><?= htmlspecialchars($record['approval_note'] ?? '-') ?></td>
                                    <td><?= $record['explanation'] ? htmlspecialchars($record['explanation']) . ' (' . $record['explanation_status'] . ')' : '-' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-center">Không có dữ liệu chấm công gần đây.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
:root {
    --primary-color: #1abc9c;
    --success-color: 39, 174, 96; /* Màu RGB để sử dụng trong hiệu ứng */
}

.sidebar {
    width: 250px;
    height: 100vh;
    background: #2c3e50;
    color: white;
    position: fixed;
    top: 0;
    left: 0;
    padding-top: 20px;
    box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
}

.sidebar-header {
    text-align: center;
    padding: 10px;
    font-size: 1.2rem;
    font-weight: bold;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.sidebar ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar ul li {
    padding: 10px 20px;
}

.sidebar ul li a {
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    transition: background 0.3s;
}

.sidebar ul li a i {
    margin-right: 10px;
}

.sidebar ul li:hover, 
.sidebar ul li.active {
    background: #34495e;
    border-left: 4px solid var(--primary-color);
}

.sidebar ul li a:hover {
    color: var(--primary-color);
}

.sidebar ul li:last-child {
    margin-top: 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.2);
}

.sidebar ul li a.dropdown-item {
    color: #e74c3c;
    font-weight: bold;
    text-align: center;
}

/* Hiển thị thời gian */
.time-display {
    font-size: 28px;
    font-weight: bold;
    color: var(--primary-color);
    text-align: center;
    margin-bottom: 10px;
}

/* Nút hiệu ứng nhấp nháy */
.pulse-button {
    animation: pulse 1.5s infinite;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    background-color: var(--primary-color);
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    display: block;
    text-align: center;
    margin: 10px auto;
    width: 80%;
}

.pulse-button:hover {
    transform: scale(1.05);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
}

@keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(var(--success-color), 0.7); }
    70% { box-shadow: 0 0 0 10px rgba(var(--success-color), 0); }
    100% { box-shadow: 0 0 0 0 rgba(var(--success-color), 0); }
}

</style>

<?php require_once '../layouts/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function updateTime() {
        const now = new Date();
        const options = { 
            hour: '2-digit', 
            minute: '2-digit', 
            second: '2-digit',
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        };
        const formattedDate = now.toLocaleDateString('vi-VN', options);
        document.getElementById('currentTime').textContent = formattedDate;
    }
    updateTime();
    setInterval(updateTime, 1000);
});
</script>