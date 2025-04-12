<?php
ob_start();

require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . "/../../vendor/autoload.php";
require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Kết nối cơ sở dữ liệu
$db = Database::getInstance()->getConnection();

// Kiểm tra session và quyền truy cập (role_id = 3 là HR)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Xử lý lọc và gửi danh sách lên kế toán
$success_message = '';
$error_message = '';
$records = [];
$filter_date = $_POST['filter_date'] ?? date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lọc dữ liệu để hiển thị
    try {
        $query = "SELECT 
                    e.employee_code AS employee_id, 
                    e.full_name, 
                    d.name AS department, 
                    p.name AS position, 
                    a.check_in, 
                    a.check_out, 
                    a.explanation, 
                    a.explanation_status, 
                    a.approved_by, 
                    a.approved_at, 
                    a.approval_note 
                  FROM attendance a 
                  JOIN employees e ON a.employee_id = e.id 
                  LEFT JOIN departments d ON e.department_id = d.id 
                  LEFT JOIN positions p ON e.position_id = p.id 
                  WHERE DATE(a.check_in) = :filter_date";
        $stmt = $db->prepare($query);
        $stmt->execute([':filter_date' => $filter_date]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_message = "Lỗi khi lọc dữ liệu: " . $e->getMessage();
    }

    if (isset($_POST['send_to_accounting'])) {
        if (empty($records)) {
            $success_message = "Không có bản ghi chấm công nào trong ngày $filter_date để gửi lên kế toán.";
            header("Location: send_to_accounting.php?success=" . urlencode($success_message));
            exit();
        }

        // Chuẩn bị dữ liệu để xuất
        $data = [];
        foreach ($records as $record) {
            $check_in = new DateTime($record['check_in']);
            $check_out = $record['check_out'] ? new DateTime($record['check_out']) : null;
            $hours_worked = $check_out ? $check_in->diff($check_out)->h + ($check_in->diff($check_out)->i / 60) : 0;
            $status = '';
            if ($check_in > new DateTime($filter_date . ' 08:00:00')) {
                $status .= "Check-in muộn";
            }
            if ($check_out && $hours_worked < 8) {
                $status .= $status ? " và Check-out sớm" : "Check-out sớm";
            }
            if (empty($status)) {
                $status = "Đúng giờ";
            }

            $approved_by = $record['approved_by'] ? "HR (ID: {$record['approved_by']})" : '-';
            $approved_at = $record['approved_at'] ? $record['approved_at'] : '-';
            $approved_info = $approved_by === '-' ? '-' : "$approved_by ($approved_at)";

            // Xử lý hiển thị giải trình
            $explanation_text = $record['explanation'] ?: 'Chưa giải trình';
            if ($record['explanation']) {
                switch ($record['explanation_status']) {
                    case 'pending':
                        $explanation_text .= ' (Đang chờ duyệt)';
                        break;
                    case 'approved':
                        $explanation_text .= ' (Đã được duyệt)';
                        break;
                    case 'rejected':
                        $explanation_text .= ' (Đã bị từ chối)';
                        break;
                    default:
                        $explanation_text .= ' (Trạng thái không xác định)';
                }
            }

            $data[] = [
                'employee_id' => $record['employee_id'],
                'full_name' => $record['full_name'],
                'department' => $record['department'] ?: 'N/A',
                'position' => $record['position'] ?: 'N/A',
                'check_in' => $record['check_in'],
                'check_out' => $record['check_out'] ?: 'Chưa check-out',
                'hours_worked' => $hours_worked ? number_format($hours_worked, 2) . ' giờ' : '-',
                'status' => $status,
                'explanation' => $explanation_text,
                'approved_by' => $approved_info,
                'approval_note' => $record['approval_note'] ?: '-'
            ];
        }

        $output_type = $_POST['output_type'] ?? 'excel';

        if ($output_type === 'excel') {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle("Danh sách chấm công $filter_date");

            $headers = ['Mã NV', 'Họ tên', 'Phòng ban', 'Chức vụ', 'Giờ vào', 'Giờ ra', 'Thời gian làm việc', 'Trạng thái', 'Giải trình', 'Người duyệt', 'Ghi chú'];
            $sheet->fromArray($headers, NULL, 'A1');
            $sheet->fromArray($data, NULL, 'A2');

            foreach (range('A', 'K') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $sheet->getStyle('A1:K1')->getFont()->setBold(true);
            $sheet->getStyle('A1:K1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $filename = "Attendance_$filter_date.xlsx";
            $writer = new Xlsx($spreadsheet);
            $writer->save($filename);

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header("Content-Disposition: attachment; filename=\"$filename\"");
            header('Cache-Control: max-age=0');
            readfile($filename);
            unlink($filename);
            exit();

        } elseif ($output_type === 'email') {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'sandbox.smtp.mailtrap.io';
                $mail->SMTPAuth = true;
                $mail->Username = '58394033ce622e';
                $mail->Password = 'c6fa0dcf3f7f26';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;

                $mail->setFrom('hr@email.com', 'HR Team');
                $mail->addAddress('ketoan@email.com');

                $mail->isHTML(true);
                $subject = "Danh sách chấm công ngày $filter_date";
                $body = "<h3>Danh sách chấm công ngày $filter_date</h3>";
                $body .= "<p>Vui lòng truy cập <a href='http://localhost/HRMpv/views/ketoan/receive_from_hr.php'>liên kết này</a> để xem chi tiết và xử lý.</p>";
                $body .= "<table border='1' cellpadding='5' cellspacing='0'>";
                $body .= "<tr><th>Mã NV</th><th>Họ tên</th><th>Phòng ban</th><th>Chức vụ</th><th>Giờ vào</th><th>Giờ ra</th><th>Thời gian làm việc</th><th>Trạng thái</th><th>Giải trình</th><th>Người duyệt</th><th>Ghi chú</th></tr>";
                foreach ($data as $item) {
                    $body .= "<tr>";
                    $body .= "<td>{$item['employee_id']}</td>";
                    $body .= "<td>{$item['full_name']}</td>";
                    $body .= "<td>{$item['department']}</td>";
                    $body .= "<td>{$item['position']}</td>";
                    $body .= "<td>{$item['check_in']}</td>";
                    $body .= "<td>{$item['check_out']}</td>";
                    $body .= "<td>{$item['hours_worked']}</td>";
                    $body .= "<td>{$item['status']}</td>";
                    $body .= "<td>{$item['explanation']}</td>";
                    $body .= "<td>{$item['approved_by']}</td>";
                    $body .= "<td>{$item['approval_note']}</td>";
                    $body .= "</tr>";
                }
                $body .= "</table><p>Trân trọng,<br>HR Team</p>";

                $mail->Subject = $subject;
                $mail->Body = $body;

                $insert_query = "INSERT INTO accounting_attendance 
                                (employee_id, full_name, department, position, check_in, check_out, hours_worked, status, explanation, approved_by, filter_date, hr_submitted) 
                                VALUES (:employee_id, :full_name, :department, :position, :check_in, :check_out, :hours_worked, :status, :explanation, :approved_by, :filter_date, 1)";
                $stmt_insert = $db->prepare($insert_query);
                foreach ($data as $item) {
                    $hours_worked_value = str_replace(' giờ', '', $item['hours_worked']) ?: 0;
                    $stmt_insert->execute([
                        ':employee_id' => $item['employee_id'],
                        ':full_name' => $item['full_name'],
                        ':department' => $item['department'],
                        ':position' => $item['position'],
                        ':check_in' => $item['check_in'],
                        ':check_out' => $item['check_out'],
                        ':hours_worked' => $hours_worked_value,
                        ':status' => $item['status'],
                        ':explanation' => $item['explanation'],
                        ':approved_by' => $item['approved_by'],
                        ':filter_date' => $filter_date
                    ]);
                }

                $mail->send();
                $success_message = "Danh sách chấm công đã được gửi qua email tới ketoan@email.com và lưu vào hệ thống!";
            } catch (Exception $e) {
                $error_message = "Lỗi gửi email: " . $e->getMessage();
            }
            header("Location: send_to_accounting.php?success=" . urlencode($success_message) . "&error=" . urlencode($error_message));
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gửi Danh Sách Chấm Công Lên Kế Toán</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="/HRMpv/public/css/g.css">
    <style>
        .badge-pending { background-color: #ffc107; color: black; }
        .badge-approved { background-color: #28a745; color: white; }
        .badge-rejected { background-color: #dc3545; color: white; }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <h2 class="mb-4">Gửi Danh Sách Chấm Công Lên Kế Toán</h2>

        <?php if (isset($_GET['success']) && !empty($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars(urldecode($_GET['success'])) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && !empty($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars(urldecode($_GET['error'])) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Form lọc và gửi danh sách -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Gửi Danh Sách Chấm Công</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="row g-3">
                    <div class="col-md-3">
                        <label for="filter_date" class="form-label">Chọn ngày:</label>
                        <input type="date" name="filter_date" id="filter_date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label for="output_type" class="form-label">Định dạng đầu ra:</label>
                        <select name="output_type" id="output_type" class="form-select">
                            <option value="excel">File Excel</option>
                            <option value="email">Email</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-info me-2">
                            <i class="fas fa-filter me-1"></i> Lọc Dữ Liệu
                        </button>
                        <button type="submit" name="send_to_accounting" class="btn btn-primary me-2">
                            <i class="fas fa-file-export me-1"></i> Gửi Lên Kế Toán
                        </button>
                        <a href="attendance.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Quay Lại
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Hiển thị danh sách chấm công -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Danh Sách Chấm Công Ngày <?= htmlspecialchars($filter_date) ?></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Mã NV</th>
                                <th>Họ tên</th>
                                <th>Phòng ban</th>
                                <th>Chức vụ</th>
                                <th>Giờ vào</th>
                                <th>Giờ ra</th>
                                <th>Thời gian làm việc</th>
                                <th>Trạng thái</th>
                                <th>Giải trình</th>
                                <th>Người duyệt</th>
                                <th>Ghi chú</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr class="no-data-row">
                                    <td colspan="11">Không có bản ghi chấm công trong ngày <?= htmlspecialchars($filter_date) ?>.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $record): ?>
                                    <?php
                                    $check_in = new DateTime($record['check_in']);
                                    $check_out = $record['check_out'] ? new DateTime($record['check_out']) : null;
                                    $hours_worked = $check_out ? $check_in->diff($check_out)->h + ($check_in->diff($check_out)->i / 60) : 0;
                                    $status = '';
                                    if ($check_in > new DateTime($filter_date . ' 08:00:00')) {
                                        $status .= "Check-in muộn";
                                    }
                                    if ($check_out && $hours_worked < 8) {
                                        $status .= $status ? " và Check-out sớm" : "Check-out sớm";
                                    }
                                    if (empty($status)) {
                                        $status = "Đúng giờ";
                                    }

                                    $approved_by = $record['approved_by'] ? "HR (ID: {$record['approved_by']})" : '-';
                                    $approved_at = $record['approved_at'] ? $record['approved_at'] : '-';
                                    $approved_info = $approved_by === '-' ? '-' : "$approved_by ($approved_at)";

                                    $explanation_text = $record['explanation'] ?: 'Chưa giải trình';
                                    $explanation_class = '';
                                    if ($record['explanation']) {
                                        switch ($record['explanation_status']) {
                                            case 'pending':
                                                $explanation_text .= ' (Đang chờ duyệt)';
                                                $explanation_class = 'badge-pending';
                                                break;
                                            case 'approved':
                                                $explanation_text .= ' (Đã được duyệt)';
                                                $explanation_class = 'badge-approved';
                                                break;
                                            case 'rejected':
                                                $explanation_text .= ' (Đã bị từ chối)';
                                                $explanation_class = 'badge-rejected';
                                                break;
                                            default:
                                                $explanation_text .= ' (Trạng thái không xác định)';
                                                $explanation_class = 'badge-secondary';
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($record['employee_id']) ?></td>
                                        <td><?= htmlspecialchars($record['full_name']) ?></td>
                                        <td><?= htmlspecialchars($record['department'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($record['position'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($record['check_in']) ?></td>
                                        <td><?= htmlspecialchars($record['check_out'] ?: 'Chưa check-out') ?></td>
                                        <td><?= $hours_worked ? number_format($hours_worked, 2) . ' giờ' : '-' ?></td>
                                        <td><?= htmlspecialchars($status) ?></td>
                                        <td>
                                            <?= htmlspecialchars($record['explanation'] ?: 'Chưa giải trình') ?>
                                            <?php if ($record['explanation']): ?>
                                                <span class="badge <?= $explanation_class ?>">
                                                    <?= $record['explanation_status'] === 'pending' ? 'Đang chờ duyệt' : 
                                                        ($record['explanation_status'] === 'approved' ? 'Đã được duyệt' : 
                                                        ($record['explanation_status'] === 'rejected' ? 'Đã bị từ chối' : 'Không xác định')) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($approved_info) ?></td>
                                        <td><?= htmlspecialchars($record['approval_note'] ?: '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php require_once '../layouts/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
<?php ob_end_flush(); ?>