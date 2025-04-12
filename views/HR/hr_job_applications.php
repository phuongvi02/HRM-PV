<?php
session_start();
session_regenerate_id(true); // Bảo mật session

// Kiểm tra đăng nhập và quyền HR
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
    header("Location: /HRMpv/views/auth/login.php");
    exit();
}

require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . "/../../core/PHPMailer/src/PHPMailer.php";
require_once __DIR__ . "/../../core/PHPMailer/src/SMTP.php";
require_once __DIR__ . "/../../core/PHPMailer/src/Exception.php";
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$db = Database::getInstance()->getConnection();

// Hàm gửi email
function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = '20211104@eaut.edu.vn';
        $mail->Password = 'fwhwszmvkhuzyehl';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('20211104@eaut.edu.vn', 'HR - Công ty TNHH Phát Triển Nông Nghiệp Lục Ngạn');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Lỗi gửi email: " . $mail->ErrorInfo);
        return false;
    }
}

// Xử lý phê duyệt/từ chối đơn ứng tuyển
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && isset($_POST['application_id'])) {
    $application_id = filter_input(INPUT_POST, 'application_id', FILTER_SANITIZE_NUMBER_INT);
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    $user_id = $_SESSION['user_id'];

    $new_status = ($action === 'approve') ? 'approved' : 'rejected';

    try {
        $stmt = $db->prepare("SELECT fullname, email, position FROM job_applications WHERE id = :id");
        $stmt->execute([':id' => $application_id]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($application) {
            // Cập nhật trạng thái (không cần approved_by vì đã bỏ cột Người Duyệt)
            $stmt = $db->prepare("UPDATE job_applications SET status = :status WHERE id = :id");
            $stmt->execute([':status' => $new_status, ':id' => $application_id]);

            $subject = $action === 'approve' 
                ? "Thông báo chấp nhận đơn ứng tuyển - {$application['position']}"
                : "Thông báo từ chối đơn ứng tuyển - {$application['position']}";
            
            $body = $action === 'approve' 
                ? "<h3>Kính gửi {$application['fullname']},</h3>
                   <p>Chúng tôi rất vui mừng thông báo rằng đơn ứng tuyển của bạn cho vị trí <strong>{$application['position']}</strong> tại Công ty TNHH Phát Triển Nông Nghiệp Lục Ngạn đã được <strong>chấp nhận</strong>.</p>
                   <p>Vui lòng liên hệ với chúng tôi qua email <a href='mailto:20211104@eaut.edu.vn'>20211104@eaut.edu.vn</a> hoặc số điện thoại 0562044109 trong vòng 3 ngày để trao đổi thêm về các bước tiếp theo.</p>
                   <p>Trân trọng,<br>Phòng Nhân Sự<br>Công ty TNHH Phát Triển Nông Nghiệp Lục Ngạn</p>"
                : "<h3>Kính gửi {$application['fullname']},</h3>
                   <p>Chúng tôi rất tiếc phải thông báo rằng đơn ứng tuyển của bạn cho vị trí <strong>{$application['position']}</strong> tại Công ty TNHH Phát Triển Nông Nghiệp Lục Ngạn đã <strong>không được chấp nhận</strong> vào thời điểm này.</p>
                   <p>Cảm ơn bạn đã dành thời gian ứng tuyển. Chúng tôi hy vọng sẽ có cơ hội hợp tác với bạn trong tương lai.</p>
                   <p>Trân trọng,<br>Phòng Nhân Sự<br>Công ty TNHH Phát Triển Nông Nghiệp Lục Ngạn</p>";

            if (sendEmail($application['email'], $subject, $body)) {
                $_SESSION['success_message'] = "Đã gửi thông báo về Gmail ({$application['email']}) thành công!";
            } else {
                $_SESSION['success_message'] = "Đã cập nhật trạng thái nhưng gửi thông báo về Gmail ({$application['email']}) thất bại!";
            }
        } else {
            $_SESSION['error_message'] = "Không tìm thấy đơn ứng tuyển!";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Lỗi khi cập nhật trạng thái: " . $e->getMessage();
        error_log("Lỗi khi cập nhật trạng thái đơn ứng tuyển: " . $e->getMessage());
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Include sidebar
require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';

// Xử lý lọc ngày tháng
$from_date = isset($_GET['from_date']) ? filter_input(INPUT_GET, 'from_date', FILTER_SANITIZE_STRING) : '';
$to_date = isset($_GET['to_date']) ? filter_input(INPUT_GET, 'to_date', FILTER_SANITIZE_STRING) : '';

// Lấy danh sách đơn ứng tuyển với lọc ngày tháng
try {
    $query = "
        SELECT ja.* 
        FROM job_applications ja
        WHERE 1=1
    ";
    $params = [];

    if ($from_date) {
        $query .= " AND DATE(ja.application_date) >= :from_date";
        $params[':from_date'] = $from_date;
    }
    if ($to_date) {
        $query .= " AND DATE(ja.application_date) <= :to_date";
        $params[':to_date'] = $to_date;
    }

    $query .= " ORDER BY ja.application_date DESC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($applications) && ($from_date || $to_date)) {
        $_SESSION['info_message'] = "Không tìm thấy đơn ứng tuyển nào trong khoảng từ '$from_date' đến '$to_date'.";
    }
} catch (PDOException $e) {
    error_log("Lỗi khi lấy danh sách đơn ứng tuyển: " . $e->getMessage());
    $_SESSION['error_message'] = "Lỗi truy vấn cơ sở dữ liệu: " . $e->getMessage();
    $applications = [];
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Lý Đơn Ứng Tuyển - HR</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 1400px;
        }
        .table-responsive {
            margin-top: 20px;
        }
        .status-pending {
            color: #ffc107;
            font-weight: bold;
        }
        .status-approved {
            color: #28a745;
            font-weight: bold;
        }
        .status-rejected {
            color: #dc3545;
            font-weight: bold;
        }
        .btn-action {
            margin-right: 5px;
        }
        .text-truncate {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .text-truncate:hover {
            white-space: normal;
            overflow: visible;
            text-overflow: inherit;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4">Quản Lý Đơn Ứng Tuyển</h1>

        <!-- Form lọc ngày tháng -->
        <div class="mb-3">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="from_date" class="form-label">Từ ngày:</label>
                    <input type="date" class="form-control" id="from_date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>">
                </div>
                <div class="col-md-3">
                    <label for="to_date" class="form-label">Đến ngày:</label>
                    <input type="date" class="form-control" id="to_date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                <div class="col-md-3 align-self-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Lọc
                    </button>
                    <button type="button" class="btn btn-primary" onclick="printApplications()">
                        <i class="fas fa-print"></i> In danh sách
                    </button>
                </div>
            </form>
        </div>

        <!-- Thông báo -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php 
                    echo $_SESSION['success_message'];
                    unset($_SESSION['success_message']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                    echo $_SESSION['error_message'];
                    unset($_SESSION['error_message']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['info_message'])): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php 
                    echo $_SESSION['info_message'];
                    unset($_SESSION['info_message']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Bảng danh sách đơn ứng tuyển -->
        <div class="table-responsive">
            <table class="table table-striped table-bordered" id="applications-table">
                <thead class="table-dark">
                    <tr>
                        <th>STT</th>
                        <th>Họ và Tên</th>
                        <th>Email</th>
                        <th>Số Điện Thoại</th>
                        <th>Vị Trí</th>
                        <th>Kinh Nghiệm</th>
                        <th>Học Vấn</th>
                        <th>Mức Lương Mong Muốn</th>
                        <th>Ngày Bắt Đầu</th>
                        <th>CV</th>
                        <th>Ngày Nộp</th>
                        <th>Trạng Thái</th>
                        <th>Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applications)): ?>
                        <tr>
                            <td colspan="13" class="text-center">Không có đơn ứng tuyển nào trong khoảng thời gian này.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($applications as $index => $app): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($app['fullname']); ?></td>
                                <td><?php echo htmlspecialchars($app['email']); ?></td>
                                <td><?php echo htmlspecialchars($app['phone']); ?></td>
                                <td><?php echo htmlspecialchars($app['position']); ?></td>
                                <td class="text-truncate"><?php echo htmlspecialchars($app['experience']); ?></td>
                                <td class="text-truncate"><?php echo htmlspecialchars($app['education']); ?></td>
                                <td><?php echo number_format($app['expected_salary'], 0, ',', '.') . ' VNĐ'; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($app['start_date'])); ?></td>
                                <td>
                                    <a href="https://drive.google.com/file/d/<?php echo htmlspecialchars($app['cv_path']); ?>/view" target="_blank" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i> Xem CV
                                    </a>
                                    <a href="https://drive.google.com/uc?export=download&id=<?php echo htmlspecialchars($app['cv_path']); ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-download"></i> Tải CV
                                    </a>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($app['application_date'])); ?></td>
                                <td class="status-<?php echo strtolower($app['status']); ?>">
                                    <?php 
                                        switch ($app['status']) {
                                            case 'pending': echo 'Đang chờ'; break;
                                            case 'approved': echo 'Đã duyệt'; break;
                                            case 'rejected': echo 'Đã từ chối'; break;
                                            default: echo $app['status'];
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($app['status'] === 'pending'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-sm btn-success btn-action">
                                                <i class="fas fa-check"></i> Phê duyệt
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-sm btn-danger btn-action">
                                                <i class="fas fa-times"></i> Từ chối
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function printApplications() {
            const table = document.getElementById('applications-table');
            const rows = table.getElementsByTagName('tr');
            const fromDate = document.getElementById('from_date').value;
            const toDate = document.getElementById('to_date').value;
            let dateRange = '';
            if (fromDate && toDate) {
                dateRange = `Từ ${new Date(fromDate).toLocaleDateString('vi-VN')} đến ${new Date(toDate).toLocaleDateString('vi-VN')}`;
            } else if (fromDate) {
                dateRange = `Từ ${new Date(fromDate).toLocaleDateString('vi-VN')}`;
            } else if (toDate) {
                dateRange = `Đến ${new Date(toDate).toLocaleDateString('vi-VN')}`;
            }

            let printContent = `
                <html>
                <head>
                    <title>In Danh Sách Ứng Tuyển</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        @media print {
                            body { margin: 20mm; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
                            table { width: 100%; border-collapse: collapse; }
                            th, td { border: 1px solid black; padding: 8px; font-size: 12px; }
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h2 class="text-center">DANH SÁCH ỨNG TUYỂN</h2>
                    <p class="text-center">Công ty TNHH Phát Triển Nông Nghiệp Lục Ngạn</p>
                    <p class="text-center">${dateRange ? dateRange : 'Toàn bộ thời gian'}</p>
                    <p class="text-center">Ngày in: ${new Date().toLocaleDateString('vi-VN')}</p>
                    <table class="table table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>STT</th>
                                <th>Họ và Tên</th>
                                <th>Email</th>
                                <th>Số Điện Thoại</th>
                                <th>Vị Trí</th>
                                <th>Kinh Nghiệm</th>
                                <th>Học Vấn</th>
                                <th>Mức Lương Mong Muốn</th>
                                <th>Ngày Bắt Đầu</th>
                                <th>Ngày Nộp</th>
                                <th>Trạng Thái</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                if (cells.length > 0) {
                    printContent += `
                        <tr>
                            <td>${cells[0].innerText}</td>
                            <td>${cells[1].innerText}</td>
                            <td>${cells[2].innerText}</td>
                            <td>${cells[3].innerText}</td>
                            <td>${cells[4].innerText}</td>
                            <td>${cells[5].innerText}</td>
                            <td>${cells[6].innerText}</td>
                            <td>${cells[7].innerText}</td>
                            <td>${cells[8].innerText}</td>
                            <td>${cells[10].innerText}</td>
                            <td>${cells[11].innerText}</td>
                        </tr>
                    `;
                }
            }

            printContent += `
                        </tbody>
                    </table>
                    <div style="margin-top: 50px;">
                        <p class="text-center">Người lập danh sách</p>
                        <p class="text-center"><em>(Ký, ghi rõ họ tên)</em></p>
                    </div>
                </body>
                </html>
            `;

            const printWindow = window.open('', '_blank');
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
            printWindow.close();
        }
    </script>
</body>
</html>