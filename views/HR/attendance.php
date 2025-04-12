<?php
ob_start();

require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';

$db = Database::getInstance()->getConnection();

// Lấy danh sách nhân viên
$employeeQuery = "SELECT e.id, e.full_name, e.email, d.name as department_name, p.name as position_name 
                 FROM employees e
                 LEFT JOIN departments d ON e.department_id = d.id
                 LEFT JOIN positions p ON e.position_id = p.id
                 ORDER BY e.full_name ASC";
$employeeStmt = $db->prepare($employeeQuery);
$employeeStmt->execute();
$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

// Xử lý phê duyệt/từ chối chấm công và giải trình
// Xử lý phê duyệt/từ chối chấm công và giải trình
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $attendance_id = intval($_POST['attendance_id'] ?? 0);
    $action = $_POST['action'];
    $note = $_POST['note'] ?? '';

    if ($attendance_id > 0) {
        try {
            $status = 'pending'; // Giá trị mặc định
            $explanation_status = '';

            // Lấy thông tin chấm công để kiểm tra trạng thái hiện tại
            $stmt = $db->prepare("SELECT check_out, status, explanation, explanation_status FROM attendance WHERE id = ?");
            $stmt->execute([$attendance_id]);
            $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
            $has_check_out = !empty($attendance['check_out']);
            $has_explanation = !empty($attendance['explanation']);
            $current_status = $attendance['status'];
            $current_explanation_status = $attendance['explanation_status'];

            switch ($action) {
                case 'approve_checkin':
                    if ($current_status === 'pending') {
                        $status = 'approved_checkin';
                    } else {
                        throw new Exception("Không thể duyệt Check-in khi trạng thái không phải 'pending'.");
                    }
                    break;
                case 'approve_checkout':
                    if ($current_status === 'approved_checkin' && $has_check_out) {
                        $status = 'approved';
                    } else {
                        throw new Exception("Không thể duyệt Check-out khi chưa duyệt Check-in hoặc chưa có Check-out.");
                    }
                    break;
                case 'approve':
                    if ($current_status === 'approved_checkin' && !$has_check_out) {
                        $status = 'approved';
                    } else {
                        throw new Exception("Không thể duyệt toàn bộ khi đã có Check-out hoặc chưa duyệt Check-in.");
                    }
                    break;
                case 'reject':
                    $status = 'rejected';
                    break;
                case 'mark_absent':
                    $status = 'absent';
                    break;
                case 'mark_leave':
                    $status = 'leave';
                    break;
                case 'approve_explanation':
                    if ($current_status === 'approved' && $has_explanation && $current_explanation_status === 'pending') {
                        $explanation_status = 'approved';
                    } else {
                        throw new Exception("Không thể duyệt giải trình khi chấm công chưa hoàn tất hoặc không có giải trình cần duyệt.");
                    }
                    break;
                case 'reject_explanation':
                    if ($has_explanation && $current_explanation_status === 'pending') {
                        $explanation_status = 'rejected';
                    } else {
                        throw new Exception("Không thể từ chối giải trình khi không có giải trình cần xử lý.");
                    }
                    break;
            }

            if ($status !== 'pending') {
                $stmt = $db->prepare("UPDATE attendance SET 
                                    status = ?, 
                                    approval_note = ?,
                                    approved_by = ?,
                                    approved_at = NOW() 
                                    WHERE id = ?");
                $stmt->execute([$status, $note, $_SESSION['user_id'] ?? 1, $attendance_id]);
                $_SESSION['success_message'] = "Cập nhật trạng thái chấm công thành công!";
            } elseif ($explanation_status) {
                $stmt = $db->prepare("UPDATE attendance SET 
                                    explanation_status = ?, 
                                    approval_note = ?,
                                    approved_by = ?,
                                    approved_at = NOW() 
                                    WHERE id = ?");
                $stmt->execute([$explanation_status, $note, $_SESSION['user_id'] ?? 1, $attendance_id]);
                $_SESSION['success_message'] = "Cập nhật trạng thái giải trình thành công!";
            } else {
                throw new Exception("Không có hành động hợp lệ để cập nhật.");
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Lỗi: " . $e->getMessage();
        }
        header("Location: attendance.php");
        exit();
    }
}

// Xử lý gửi thông báo nếu không giải trình
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['notify_employee'])) {
    $attendance_id = intval($_POST['attendance_id']);
    $stmt = $db->prepare("SELECT e.email, a.check_in FROM attendance a 
                          JOIN employees e ON a.employee_id = e.id 
                          WHERE a.id = ? AND a.explanation IS NULL");
    $stmt->execute([$attendance_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record) {
        $email = $record['email'];
        $check_in = $record['check_in'];
        $subject = "Thông báo: Vui lòng gửi giải trình chấm công";
        $message = "Bạn đã check-in lúc " . date('H:i:s d/m/Y', strtotime($check_in)) . " nhưng chưa gửi giải trình. Vui lòng giải trình trong 24 giờ, nếu không sẽ bị trừ lương.";
        $_SESSION['success_message'] = "Đã gửi thông báo đến nhân viên qua email: $email";
    }
    header("Location: attendance.php");
    exit();
}

// Xử lý gửi danh sách lên kế toán
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_to_accounting'])) {
    $filter_date = $_POST['filter_date'] ?? date('Y-m-d');
    $stmt = $db->prepare("SELECT e.full_name, a.check_in, a.check_out, a.status, a.explanation 
                          FROM attendance a 
                          JOIN employees e ON a.employee_id = e.id 
                          WHERE DATE(a.check_in) = ? AND (a.explanation IS NULL OR a.explanation_status = 'rejected')");
    $stmt->execute([$filter_date]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $message = "Danh sách nhân viên không giải trình hoặc giải trình bị từ chối ngày $filter_date:\n";
    foreach ($records as $record) {
        $hours = $record['check_out'] ? (strtotime($record['check_out']) - strtotime($record['check_in'])) / 3600 : 0;
        $message .= "- {$record['full_name']}: Check-in {$record['check_in']}, Check-out {$record['check_out']}, Giờ làm: " . number_format($hours, 2) . " giờ\n";
    }
    $_SESSION['success_message'] = "Đã gửi danh sách lên kế toán!";
    header("Location: attendance.php");
    exit();
}

// Xử lý lọc chấm công
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_status = $_GET['status'] ?? '';
$filter_employee = $_GET['employee'] ?? '';
$filter_department = $_GET['department'] ?? '';

$departmentQuery = "SELECT id, name FROM departments ORDER BY name";
$departmentStmt = $db->prepare($departmentQuery);
$departmentStmt->execute();
$departments = $departmentStmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT 
            a.id,
            e.full_name AS employee_name,
            e.employee_code,
            e.email,
            d.name AS department_name,
            p.name AS position_name,
            a.check_in,
            a.check_out,
            COALESCE(a.status, 'pending') AS status,
            a.approval_note,
            a.explanation,
            a.explanation_status,
            CASE 
                WHEN a.approved_by IS NOT NULL THEN 'HR'
                ELSE 'Chưa duyệt'
            END AS approved_by_info,
            DATE_FORMAT(a.approved_at, '%d/%m/%Y %H:%i') AS approved_at_formatted
        FROM attendance a
        LEFT JOIN employees e ON a.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN positions p ON e.position_id = p.id
        WHERE DATE(a.check_in) = ?";
$params = [$filter_date];



if ($filter_status) {
    $sql .= " AND a.status = ?";
    $params[] = $filter_status;
}

if ($filter_employee) {
    $sql .= " AND e.id = ?";
    $params[] = $filter_employee;
}

if ($filter_department) {
    $sql .= " AND d.id = ?";
    $params[] = $filter_department;
}

$sql .= " ORDER BY a.check_in DESC, e.full_name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: Kiểm tra số 

// Tính toán thống kê
$total_employees = count($employees);
$present_count = 0;
$absent_count = 0;
$late_count = 0;
$leave_count = 0;
$pending_count = 0;
$approved_checkin_count = 0;

foreach ($attendances as $attendance) {
    // Kiểm tra nếu $attendance['check_in'] không rỗng trước khi sử dụng strtotime
    $check_in_time = !empty($attendance['check_in']) ? strtotime($attendance['check_in']) : null;
    
    switch ($attendance['status']) {
        case 'approved':
            if ($check_in_time && $check_in_time > strtotime('08:00:00')) {
                $late_count++;
            } else {
                $present_count++;
            }
            break;
        case 'approved_checkin':
            $approved_checkin_count++;
            break;
        case 'absent':
            $absent_count++;
            break;
        case 'leave':
            $leave_count++;
            break;
        case 'pending':
            $pending_count++;
            break;
        default:
            // Debug: Ghi log nếu có trạng thái không xác định
            error_log("Trạng thái không xác định trong thống kê: " . $attendance['status']);
            break;
    }
}
// Thêm đoạn mã này ở đầu trang để kiểm tra dữ liệu
echo '<div style="display:none">';
echo '<h3>Debug Information:</h3>';
echo '<pre>';
echo 'Filter Status: ' . var_export($filter_status, true) . "\n";
echo 'SQL Query: ' . $sql . "\n";
echo 'SQL Params: ' . var_export($params, true) . "\n";
foreach ($attendances as $idx => $att) {
    echo "Attendance #$idx Status: " . var_export($att['status'], true) . "\n";
}
echo '</pre>';
echo '</div>';
?>
    <link rel="stylesheet" href="/HRMpv/public/css/a.css">

<!-- HTML -->
<div class="container-fluid">
    <h2>Quản Lý Chấm Công</h2>

    <!-- Thống kê -->
    <div class="stats-row">
        <div class="stats-card">
            <h6>Tổng nhân viên</h6>
            <p><?= $total_employees ?></p>
        </div>
        <div class="stats-card">
            <h6>Đi làm đúng giờ</h6>
            <p><?= $present_count ?></p>
        </div>
        <div class="stats-card">
            <h6>Đã Check-in</h6>
            <p><?= $approved_checkin_count ?></p>
        </div>
        <div class="stats-card">
            <h6>Đi muộn</h6>
            <p><?= $late_count ?></p>
        </div>
        <div class="stats-card">
            <h6>Vắng mặt</h6>
            <p><?= $absent_count ?></p>
        </div>
        <div class="stats-card">
            <h6>Nghỉ phép</h6>
            <p><?= $leave_count ?></p>
        </div>
    </div>

    <!-- Form lọc -->
    <div class="card mb-4">
        <div class="card-header">
            <h5>Bộ lọc chấm công</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-form">
                <div>
                    <label class="form-label">Ngày:</label>
                    <input type="date" name="date" class="form-control" value="<?= $filter_date ?>">
                </div>
                <div>
                    <label class="form-label">Phòng ban:</label>
                    <select name="department" class="form-select">
                        <option value="">Tất cả phòng ban</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" <?= $filter_department == $dept['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Nhân viên:</label>
                    <select name="employee" class="form-select">
                        <option value="">Tất cả nhân viên</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $filter_employee == $emp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['full_name']) ?> - <?= htmlspecialchars($emp['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Trạng thái:</label>
                    <select name="status" class="form-select">
    <option value="">Tất cả trạng thái</option>
    <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Đang xử lý</option>
    <option value="approved_checkin" <?= $filter_status === 'approved_checkin' ? 'selected' : '' ?>>Đã duyệt Check-in</option>
    <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Đã duyệt</option>
    <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Từ chối</option>
    <option value="absent" <?= $filter_status === 'absent' ? 'selected' : '' ?>>Vắng mặt</option>
    <option value="leave" <?= $filter_status === 'leave' ? 'selected' : '' ?>>Nghỉ phép</option>
</select>
                </div>
                <div class="form-buttons">
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-search"></i> Lọc
    </button>
    <a href="attendance.php" class="btn btn-secondary">
        <i class="fas fa-sync-alt"></i> Đặt lại
    </a>
    <a href="send_to_accounting.php" class="btn btn-warning">
        <i class="fas fa-file-export"></i> Gửi Kế Toán
    </a>
    <button type="button" class="btn btn-info" onclick="printAttendance()">
        <i class="fas fa-print"></i> In
    </button>
</div>
            </form>
        </div>
    </div>

    <!-- Hiển thị thông báo -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $_SESSION['success_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= $_SESSION['error_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Bảng chấm công -->
<!-- Bảng chấm công -->
<div class="card-body">
    <div class="table-responsive" id="attendanceTable">
        <table class="table table-bordered table-hover">
            <thead class="table-light">
                <tr>
                    <th>Mã NV</th>
                    <th>Họ tên</th>
                    <th>Phòng ban</th>
                    <th>Chức vụ</th>
                    <th>Giờ vào</th>
                    <th>Giờ ra</th>
                    <th>Thời gian làm</th>
                    <th>Giải trình</th>
                    <th>Trạng thái</th>
                    <th>Người duyệt</th>
                    <th>Ghi chú</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attendances)): ?>
                    <tr>
                        <td colspan="12" class="text-center">Không có dữ liệu chấm công cho ngày <?= date('d/m/Y', strtotime($filter_date)) ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($attendances as $attendance): 
                        $check_in_time = $attendance['check_in'] ? new DateTime($attendance['check_in']) : null;
                        $check_out_time = $attendance['check_out'] ? new DateTime($attendance['check_out']) : null;
                        $hours_worked = '-';
                        if ($check_in_time) {
                            if ($check_out_time) {
                                $interval = $check_in_time->diff($check_out_time);
                                $hours_worked = sprintf("%.2f giờ", $interval->h + ($interval->i / 60));
                            } elseif ($attendance['status'] == 'approved_checkin' || $attendance['status'] == 'pending') {
                                $current_time = new DateTime();
                                $interval = $check_in_time->diff($current_time);
                                $hours_worked = sprintf("%.2f giờ (đang làm)", $interval->h + ($interval->i / 60));
                            }
                        }
                        $needs_explanation = ($check_in_time && $check_in_time > new DateTime('08:00:00')) || ($check_out_time && $hours_worked !== '-' && floatval(explode(' ', $hours_worked)[0]) < 8);
                    ?>
                    <tr>
                        <!-- Cột 1: Mã NV -->
                        <td><?= htmlspecialchars($attendance['employee_code']) ?></td>
                        
                        <!-- Cột 2: Họ tên -->
                        <td><?= htmlspecialchars($attendance['employee_name']) ?></td>
                        
                        <!-- Cột 3: Phòng ban -->
                        <td><?= htmlspecialchars($attendance['department_name']) ?></td>
                        
                        <!-- Cột 4: Chức vụ -->
                        <td><?= htmlspecialchars($attendance['position_name']) ?></td>
                        
                        <!-- Cột 5: Giờ vào -->
                        <td>
                            <?php
                            if ($check_in_time) {
                                $status_class_time = $check_in_time > new DateTime('08:00:00') ? 'text-danger' : 'text-success';
                                echo '<span class="' . $status_class_time . '">' . $check_in_time->format('H:i:s') . '</span>';
                            } else {
                                echo '<span class="text-muted">Chưa check-in</span>';
                            }
                            ?>
                        </td>
                        
                        <!-- Cột 6: Giờ ra -->
                        <td>
                            <?php
                            if ($check_out_time) {
                                $status_class_time = $hours_worked !== '-' && floatval(explode(' ', $hours_worked)[0]) < 8 ? 'text-danger' : 'text-success';
                                echo '<span class="' . $status_class_time . '">' . $check_out_time->format('H:i:s') . '</span>';
                            } else {
                                echo '<span class="text-muted">Chưa check-out</span>';
                            }
                            ?>
                        </td>
                        
                        <!-- Cột 7: Thời gian làm -->
                        <td><?= $hours_worked ?></td>
                        
                        <!-- Cột 8: Giải trình -->
                        <td>
                            <?php if ($attendance['explanation']): ?>
                                <?= htmlspecialchars($attendance['explanation']) ?> 
                                (<span class="badge <?= $attendance['explanation_status'] == 'pending' ? 'bg-warning text-dark' : ($attendance['explanation_status'] == 'approved' ? 'bg-success' : 'bg-danger') ?>">
                                    <?= $attendance['explanation_status'] == 'pending' ? 'Chưa phê duyệt giải trình' : ($attendance['explanation_status'] == 'approved' ? 'Đã duyệt' : 'Từ chối') ?></span>)
                            <?php elseif ($needs_explanation): ?>
                                <span class="text-danger">Chưa giải trình</span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        
                        <!-- Cột 9: Trạng thái (bỏ CSS) -->
                        <td>
                            <?php
                            // Chuẩn hóa giá trị status
                            $status_value = strtolower(trim($attendance['status'] ?? 'pending'));

                            // Khởi tạo biến trạng thái
                            $status_text = 'Đang xử lý';

                            // Xác định trạng thái
                            switch ($status_value) {
                                case 'pending':
                                    $status_text = 'Đang xử lý';
                                    break;
                                case 'approved_checkin':
                                    $status_text = 'Đã duyệt Check-in';
                                    break;
                                case 'approved':
                                    $status_text = 'Đã duyệt';
                                    break;
                                case 'rejected':
                                    $status_text = 'Từ chối';
                                    break;
                                case 'absent':
                                    $status_text = 'Vắng mặt';
                                    break;
                                case 'leave':
                                    $status_text = 'Nghỉ phép';
                                    break;
                                default:
                                    $status_text = 'Không xác định';
                                    error_log("Trạng thái không xác định: " . $status_value);
                                    break;
                            }
                            ?>
                            <!-- Hiển thị trạng thái mà không dùng CSS -->
                            <?= htmlspecialchars($status_text) ?>
                        </td>
                        
                        <!-- Cột 10: Người duyệt -->
                        <td>
                            <?php 
                            if ($attendance['approved_by_info'] === 'HR') {
                                echo 'HR (' . ($attendance['approved_at_formatted'] ?? 'Không xác định') . ')';
                            } else {
                                echo 'Chưa duyệt';
                            }
                            ?>
                        </td>
                        
                        <!-- Cột 11: Ghi chú -->
                        <td><?= htmlspecialchars($attendance['approval_note'] ?? '-') ?></td>
                        
                        <!-- Cột 12: Thao tác -->
                        <td>
                            <?php 
                            $has_check_out = !empty($attendance['check_out']);
                            $has_explanation = !empty($attendance['explanation']);
                            $needs_explanation = ($check_in_time && $check_in_time > new DateTime('08:00:00')) || ($check_out_time && $hours_worked !== '-' && floatval(explode(' ', $hours_worked)[0]) < 8);
                            ?>

                            <?php if ($attendance['status'] == 'pending'): ?>
                                <div class="action-dropdown">
                                    <button class="action-btn dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i> Thao tác
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="#" onclick="showNoteModal(<?= $attendance['id'] ?>, 'approve_checkin', this)">
                                            <i class="fas fa-clock"></i> Duyệt Check-in</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="showNoteModal(<?= $attendance['id'] ?>, 'reject', this)">
                                            <i class="fas fa-times"></i> Từ chối</a></li>
                                        <?php if ($needs_explanation && !$has_explanation): ?>
                                            <li><a class="dropdown-item" href="#" onclick="showNoteModal(<?= $attendance['id'] ?>, 'notify_employee', this)">
                                                <i class="fas fa-bell"></i> Thông báo NV</a></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            <?php elseif ($attendance['status'] == 'approved_checkin'): ?>
                                <div class="action-dropdown">
                                    <button class="action-btn dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i> Thao tác
                                    </button>
                                    <ul class="dropdown-menu">
                                        <?php if ($has_check_out): ?>
                                            <li><a class="dropdown-item" href="#" onclick="showNoteModal(<?= $attendance['id'] ?>, 'approve_checkout', this)">
                                                <i class="fas fa-check"></i> Duyệt Check-out</a></li>
                                        <?php else: ?>
                                            <li><a class="dropdown-item" href="#" onclick="showNoteModal(<?= $attendance['id'] ?>, 'approve', this)">
                                                <i class="fas fa-check"></i> Duyệt toàn bộ</a></li>
                                        <?php endif; ?>
                                        <li><a class="dropdown-item" href="#" onclick="showNoteModal(<?= $attendance['id'] ?>, 'reject', this)">
                                            <i class="fas fa-times"></i> Từ chối</a></li>
                                    </ul>
                                </div>
                            <?php elseif ($attendance['status'] == 'approved' && $has_explanation && $attendance['explanation_status'] == 'pending'): ?>
                                <div class="action-dropdown">
                                    <button class="action-btn dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i> Thao tác
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="#" onclick="showNoteModal(<?= $attendance['id'] ?>, 'approve_explanation', this)">
                                            <i class="fas fa-check"></i> Duyệt giải trình</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="showNoteModal(<?= $attendance['id'] ?>, 'reject_explanation', this)">
                                            <i class="fas fa-times"></i> Từ chối giải trình</a></li>
                                    </ul>
                                </div>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary btn-sm" disabled>
                                    <i class="fas fa-lock"></i> Đã xử lý
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
    <!-- Modal nhập ghi chú -->
    <div class="modal fade" id="noteModal" tabindex="-1" aria-labelledby="noteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="noteModalLabel">Nhập Ghi Chú</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="noteForm" method="POST">
                    <input type="hidden" name="attendance_id" id="modal_attendance_id">
                    <input type="hidden" name="action" id="modal_action">
                    <!-- Trường nhập ghi chú -->
                    <div class="detail-row">
                        <span class="label">Ghi Chú:</span>
                        <input type="text" name="note" id="modal_note" class="value form-control" placeholder="Nhập ghi chú (nếu có)">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Xác nhận</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Script giữ nguyên -->
<script>
    function printAttendance() {
    // Có thể thêm logic bổ sung nếu cần, ví dụ: lưu trạng thái trước khi in
    window.print();
}
function showNoteModal(attendanceId, action, element) {
    document.getElementById('modal_attendance_id').value = attendanceId;
    document.getElementById('modal_action').value = action;
    document.getElementById('modal_note').value = '';

    const modalTitle = document.getElementById('noteModalLabel');
    switch (action) {
        case 'approve_checkin':
            modalTitle.textContent = 'Nhập Ghi Chú - Duyệt Check-in';
            break;
        case 'approve_checkout':
            modalTitle.textContent = 'Nhập Ghi Chú - Duyệt Check-out';
            break;
        case 'approve':
            modalTitle.textContent = 'Nhập Ghi Chú - Duyệt Toàn Bộ';
            break;
        case 'reject':
            modalTitle.textContent = 'Nhập Ghi Chú - Từ Chối';
            break;
        case 'notify_employee':
            modalTitle.textContent = 'Nhập Ghi Chú - Thông Báo Nhân Viên';
            break;
        case 'approve_explanation':
            modalTitle.textContent = 'Nhập Ghi Chú - Duyệt Giải Trình';
            break;
        case 'reject_explanation':
            modalTitle.textContent = 'Nhập Ghi Chú - Từ Chối Giải Trình';
            break;
        default:
            modalTitle.textContent = 'Nhập Ghi Chú';
    }

    const noteModal = new bootstrap.Modal(document.getElementById('noteModal'));
    noteModal.show();
}
function printAttendance() {
    window.print();
}

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            bootstrap.Alert.getInstance(alert)?.close();
        });
    }, 3000);
});
</script>

<?php require_once '../layouts/footer.php'; ?>