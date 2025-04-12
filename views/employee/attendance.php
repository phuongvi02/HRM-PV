<?php
ob_start(); // Start output buffering
require_once __DIR__ . "/../../core/Database.php";
require_once '../layouts/header_employee.php';
require_once '../layouts/sidebar_employee.php';
require_once '../layouts/navbar_employee.php';
require_once __DIR__ . "/../../core/ChatBot.php";

$db = Database::getInstance()->getConnection();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 5) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}
date_default_timezone_set('Asia/Ho_Chi_Minh');

$employee_id = $_SESSION['user_id'];

// Lấy thông tin nhân viên
$stmt = $db->prepare("SELECT full_name FROM employees WHERE id = :employee_id");
$stmt->execute([':employee_id' => $employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// Lấy dữ liệu chấm công tháng hiện tại
$current_month = date('Y-m');
$stmt = $db->prepare("SELECT DATE(check_in) as date, status, explanation, explanation_status 
                      FROM attendance 
                      WHERE employee_id = :employee_id 
                      AND DATE_FORMAT(check_in, '%Y-%m') = :current_month");
$stmt->execute([
    ':employee_id' => $employee_id,
    ':current_month' => $current_month
]);
$attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tạo mảng trạng thái theo ngày
$attendance_status = [];
foreach ($attendance_records as $record) {
    $date = date('j', strtotime($record['date']));
    if ($record['status'] === 'approved' && !$record['explanation']) {
        $attendance_status[$date] = 'on_time'; // Xanh
    } elseif ($record['explanation']) {
        $attendance_status[$date] = 'explained'; // Xám
    } else {
        $attendance_status[$date] = 'pending_explanation'; // Đỏ
    }
}

// Xử lý gửi giải trình
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_explanation'])) {
    $selected_date = $_POST['selected_date'];
    $explanation = trim($_POST['explanation']);
    
    if ($explanation && $selected_date) {
        // Kiểm tra xem ngày đã có bản ghi chấm công chưa
        $stmt = $db->prepare("SELECT id FROM attendance WHERE employee_id = :employee_id AND DATE(check_in) = :selected_date");
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':selected_date' => $selected_date
        ]);
        $existing_record = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_record) {
            // Cập nhật giải trình cho bản ghi hiện có
            $stmt = $db->prepare("UPDATE attendance 
                                 SET explanation = :explanation, 
                                     explanation_status = 'pending' 
                                 WHERE employee_id = :employee_id 
                                 AND DATE(check_in) = :selected_date");
            $stmt->execute([
                ':explanation' => $explanation,
                ':employee_id' => $employee_id,
                ':selected_date' => $selected_date
            ]);
        } else {
            // Tạo bản ghi mới cho ngày chưa chấm công
            $stmt = $db->prepare("INSERT INTO attendance (employee_id, check_in, status, explanation, explanation_status) 
                                 VALUES (:employee_id, :check_in, 'pending', :explanation, 'pending')");
            $stmt->execute([
                ':employee_id' => $employee_id,
                ':check_in' => $selected_date . ' 00:00:00',
                ':explanation' => $explanation
            ]);
        }
        
        
        $stmt->execute([
            ':explanation' => $explanation,
            ':employee_id' => $employee_id,
            ':selected_date' => $selected_date
        ]);
        header("Location: attendance.php?success=Giải trình cho ngày $selected_date đã được gửi");
        exit();
    } else {
        $error_message = "Vui lòng nhập lý do giải trình!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Chấm Công Nhân Viên</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .calendar {
            max-width: 800px;
            margin: 20px auto;
        }
        .calendar table {
            width: 100%;
            border-collapse: collapse;
        }
        .calendar th, .calendar td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: center;
        }
        .on-time {
            background-color: #28a745 !important; /* Xanh */
            color: white;
        }
        .pending-explanation {
            background-color: #dc3545 !important; /* Đỏ */
            color: white;
            cursor: pointer;
        }
        .explained {
            background-color: #6c757d !important; /* Xám */
            color: white;
        }
        .current-day {
            border: 2px solid #007bff;
        }
        .pending-explanation:hover {
            background-color: #c82333 !important; /* Đỏ đậm hơn khi hover */
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2 class="text-center mb-4">Chấm Công - <?php echo htmlspecialchars($employee['full_name'] ?? ''); ?></h2>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success"><?php echo $_GET['success']; ?></div>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Bảng lịch -->
        <div class="calendar">
            <?php
            $first_day = new DateTime(date('Y-m-01'));
            $days_in_month = cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y'));
            $current_day = date('j');
            
            echo '<table class="table">';
            echo '<thead><tr>';
            $days = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
            foreach ($days as $day) {
                echo "<th>$day</th>";
            }
            echo '</tr></thead><tbody><tr>';
            
            // Điền khoảng trống đầu tháng
            $start_day = $first_day->format('N') - 1;
            for ($i = 0; $i < $start_day; $i++) {
                echo '<td></td>';
            }
            
            // Điền các ngày trong tháng
            for ($day = 1; $day <= $days_in_month; $day++) {
                $class = 'pending-explanation'; // Mặc định là đỏ (chưa giải trình hoặc chưa chấm công)
                if (isset($attendance_status[$day])) {
                    $class = $attendance_status[$day]; // Ghi đè nếu có trạng thái từ database
                }
                if ($day == $current_day) {
                    $class .= ' current-day';
                }
                
                $date_str = date('Y-m') . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
                if ($class === 'pending-explanation') {
                    echo "<td class='$class' data-bs-toggle='modal' data-bs-target='#explainModal' data-date='$date_str'>$day</td>";
                } else {
                    echo "<td class='$class'>$day</td>";
                }
                
                if (($start_day + $day) % 7 == 0) {
                    echo '</tr><tr>';
                }
            }
            
            echo '</tr></tbody></table>';
            ?>
            
            <div class="mt-3">
                <span class="badge bg-success me-2">Đúng giờ</span>
                <span class="badge bg-danger me-2">Chưa giải trình/Chưa chấm công (Click để giải trình)</span>
                <span class="badge bg-secondary">Đã giải trình</span>
            </div>
        </div>
    </div>

    <!-- Modal giải trình -->
    <div class="modal fade" id="explainModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Gửi Giải Trình</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="selected_date" id="selectedDate">
                        <div class="mb-3">
                            <label class="form-label">Ngày: <span id="displayDate"></span></label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Lý do giải trình</label>
                            <textarea class="form-control" name="explanation" rows="3" required placeholder="Nhập lý do tại sao bạn cần giải trình cho ngày này..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                        <button type="submit" name="submit_explanation" class="btn btn-primary">Gửi Giải Trình</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const explainModal = document.getElementById('explainModal');
        explainModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const date = button.getAttribute('data-date');
            
            document.getElementById('selectedDate').value = date;
            document.getElementById('displayDate').textContent = new Date(date)
                .toLocaleDateString('vi-VN', { 
                    weekday: 'long', 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric' 
                });
        });
    });
    </script>
</body>
</html>
<?php
ob_end_flush(); // End output buffering
?>