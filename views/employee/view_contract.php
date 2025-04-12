<?php
ob_start(); // Start output buffering
require_once __DIR__ . "/../../core/Database.php";
require_once '../layouts/header_employee.php';
require_once '../layouts/sidebar_employee.php';
require_once '../layouts/navbar_employee.php';

$db = Database::getInstance()->getConnection();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kiểm tra quyền truy cập
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 5) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

// Lấy employee_id từ URL
$employee_id = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['user_id'];

// Kiểm tra quyền truy cập: chỉ cho phép nhân viên xem hợp đồng của chính mình
if ($employee_id != $_SESSION['user_id']) {
    header('Location: /HRMpv/views/employee/index_employee.php?error=unauthorized');
    exit();
}

// Lấy thông tin hợp đồng
$stmt = $db->prepare("SELECT * FROM contracts WHERE employee_id = ? ORDER BY start_date DESC LIMIT 1");
$stmt->execute([$employee_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    header("Location: /HRMpv/views/employee/index_employee.php?error=no_contract_found");
    exit();
}

// Lấy thông tin nhân viên
$stmt = $db->prepare("SELECT e.full_name, e.id as employee_code, p.name as position_name, d.name as department_name
                      FROM employees e
                      LEFT JOIN positions p ON e.position_id = p.id
                      LEFT JOIN departments d ON e.department_id = d.id
                      WHERE e.id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi tiết hợp đồng - <?= htmlspecialchars($employee['full_name']) ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/HRMpv/public/css/profile.css">
    <style>
        .contract-container {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin: 20px auto;
            max-width: 900px;
        }

        .contract-header {
            text-align: center;
            margin-bottom: 20px;
        }

        .contract-header h2 {
            color: #007bff;
        }

        .table-hover tbody tr {
            transition: background-color 0.2s ease;
        }

        .btn-group {
            margin-top: 20px;
            text-align: center;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <div class="contract-container">
            <div class="contract-header">
                <h2><i class="fas fa-file-contract mr-2"></i> Chi tiết hợp đồng</h2>
                <p>Mã hợp đồng: <?= htmlspecialchars($contract['contract_code']) ?></p>
            </div>

            <table class="table table-hover">
                <tbody>
                    <tr>
                        <td class="fw-bold">Tên nhân viên</td>
                        <td><?= htmlspecialchars($employee['full_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Mã nhân viên</td>
                        <td><?= htmlspecialchars($employee['employee_code']) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Phòng ban</td>
                        <td><?= htmlspecialchars($employee['department_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Chức vụ</td>
                        <td><?= htmlspecialchars($employee['position_name']) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Loại hợp đồng</td>
                        <td><?= htmlspecialchars($contract['contract_type']) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Ngày bắt đầu</td>
                        <td><?= date('d/m/Y', strtotime($contract['start_date'])) ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Ngày kết thúc</td>
                        <td><?= $contract['end_date'] ? date('d/m/Y', strtotime($contract['end_date'])) : 'Không xác định' ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Lương cơ bản</td>
                        <td><?= number_format($contract['basic_salary'], 0, ',', '.') ?> VNĐ</td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Phụ cấp</td>
                        <td><?= number_format($contract['allowance'] ?? 0, 0, ',', '.') ?> VNĐ</td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Thời gian làm việc</td>
                        <td><?= htmlspecialchars($contract['work_time'] ?? 'Không có thông tin') ?></td>
                    </tr>
                    <tr>
                        <td class="fw-bold">Trạng thái</td>
                        <td>
                            <?php
                            $status = '';
                            $statusClass = '';
                            $today = new DateTime();
                            $startDate = new DateTime($contract['start_date']);
                            $endDate = $contract['end_date'] ? new DateTime($contract['end_date']) : null;

                            if ($startDate > $today) {
                                $status = 'Chưa hiệu lực';
                                $statusClass = 'text-warning';
                            } elseif (!$endDate || $endDate >= $today) {
                                $status = 'Đang hiệu lực';
                                $statusClass = 'text-success';
                            } else {
                                $status = 'Hết hiệu lực';
                                $statusClass = 'text-danger';
                            }
                            ?>
                            <span class="<?= $statusClass ?>"><?= $status ?></span>
                        </td>
                    </tr>
                    <?php if (!empty($contract['job_description'])): ?>
                        <tr>
                            <td class="fw-bold">Mô tả công việc</td>
                            <td><?= nl2br(htmlspecialchars($contract['job_description'])) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if (!empty($contract['notes'])): ?>
                        <tr>
                            <td class="fw-bold">Ghi chú</td>
                            <td><?= nl2br(htmlspecialchars($contract['notes'])) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="btn-group">
                <a href="/HRMpv/views/employee/index_employee.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left mr-1"></i> Quay lại
                </a>
                <a href="../employees/print_contract.php?id=<?= $employee_id ?>" class="btn btn-primary">
                    <i class="fas fa-print mr-1"></i> In hợp đồng
                </a>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS and Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.10.2/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const rows = document.querySelectorAll('.table-hover tbody tr');
        rows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#f5f5f5';
            });
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
        });
    });
    </script>
</body>
</html>

<?php
ob_end_flush(); // End output buffering and send content
require_once '../layouts/footer.php';
?>