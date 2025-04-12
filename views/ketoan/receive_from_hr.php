<?php
ob_start();

require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../views/layouts/sidebar_kt.php';
require_once __DIR__ . '/../../views/layouts/header_kt.php';

$db = Database::getInstance()->getConnection();

// Xử lý cập nhật trạng thái trừ lương
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_violations'])) {
    try {
        $violation_ids = $_POST['violation_ids'] ?? [];
        $deduction_statuses = $_POST['deduction_status'] ?? [];
        $filter_date = $_POST['filter_date'] ?? date('Y-m-d'); // Lấy filter_date từ form

        // Bắt đầu giao dịch
        $db->beginTransaction();

        if (empty($violation_ids)) {
            throw new Exception("Không có vi phạm nào được chọn để xử lý.");
        }

        $update_query = "UPDATE accounting_attendance 
                         SET deduction_status = :deduction_status,
                             updated_at = NOW()
                         WHERE id = :id";
        $stmt = $db->prepare($update_query);

        foreach ($violation_ids as $index => $id) {
            $status = $deduction_statuses[$index] ?? 'pending';
            $stmt->execute([
                ':deduction_status' => $status,
                ':id' => $id
            ]);
        }
        $db->commit();

        $success_message = "Cập nhật trạng thái trừ lương thành công!";
        // Thêm filter_date vào URL khi chuyển hướng
        header("Location: receive_from_hr.php?success=" . urlencode($success_message) . "&filter_date=" . urlencode($filter_date));
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Lỗi khi xử lý vi phạm: " . $e->getMessage();
        // Thêm filter_date vào URL khi chuyển hướng
        header("Location: receive_from_hr.php?error=" . urlencode($error_message) . "&filter_date=" . urlencode($filter_date));
        exit();
    }
}

// Lấy ngày lọc
$filter_date = $_GET['filter_date'] ?? date('Y-m-d');

// Kiểm tra dữ liệu từ HR
$stmt = $db->prepare("SELECT COUNT(*) 
                      FROM accounting_attendance 
                      WHERE filter_date = :filter_date 
                      AND hr_submitted = 1");
$stmt->execute([':filter_date' => $filter_date]);
$hr_data_exists = $stmt->fetchColumn();

if ($hr_data_exists > 0) {
    $query = "SELECT DISTINCT aa.id, aa.employee_id, aa.full_name, aa.department, aa.position, 
              aa.check_in, aa.check_out, aa.hours_worked, aa.status, aa.explanation, 
              aa.approved_by, aa.deduction_status
              FROM accounting_attendance aa
              WHERE aa.filter_date = :filter_date 
              AND aa.hr_submitted = 1
              ORDER BY aa.employee_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':filter_date' => $filter_date]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $records = [];
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nhận Danh Sách Vi Phạm Từ HR</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Roboto', sans-serif;
        }
        .navbar {
            background-color: #2c3e50;
            padding: 10px 20px;
        }
        .navbar a {
            color: white;
            margin-right: 20px;
            text-decoration: none;
            font-weight: 500;
        }
        .navbar a:hover {
            color: #18bc9c;
        }
        .container {
            max-width: 1200px;
            margin: 20px auto;
        }
        h2 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: none;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .alert-warning, .alert-info {
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .form-section {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .form-section label {
            font-weight: 500;
            color: #2c3e50;
        }
        .form-section .form-control {
            border-radius: 5px;
            border: 1px solid #ced4da;
        }
        .btn-primary {
            background-color: #007bff;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
        .btn-secondary {
            background-color: #6c757d;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        .btn-success {
            background-color: #28a745;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        .btn-success:hover {
            background-color: #218838;
        }
        .table-section {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .table {
            margin-bottom: 0;
        }
        .table thead th {
            background-color: #e9ecef;
            color: #2c3e50;
            font-weight: 600;
            text-align: center;
        }
        .table tbody td {
            text-align: center;
            vertical-align: middle;
        }
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        .status-pending {
            color: #ffc107;
            font-weight: 500;
        }
        .status-deducted {
            color: #dc3545;
            font-weight: 500;
        }
        .status-not-deducted {
            color: #28a745;
            font-weight: 500;
        }
        .no-data {
            background-color: #f8f9fa;
            color: #6c757d;
            font-style: italic;
            text-align: center;
            padding: 20px;
        }
    </style>
</head>
<body>

    <div class="container">
        <h2>Nhận Danh Sách Vi Phạm Từ HR</h2>

        <!-- Thông báo -->
        <?php if (isset($_GET['success']) && !empty($_GET['success'])): ?>
            <div class="alert alert-success" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars(urldecode($_GET['success'])) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && !empty($_GET['error'])): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars(urldecode($_GET['error'])) ?>
            </div>
        <?php endif; ?>

        <!-- Thông báo dữ liệu từ HR -->
        <?php if ($hr_data_exists == 0): ?>
            <div class="alert alert-warning" role="alert">
                <i class="fas fa-info-circle me-2"></i>Chưa có dữ liệu chấm công nào từ HR cho ngày <?= htmlspecialchars($filter_date) ?>. Vui lòng kiểm tra với phòng HR.
            </div>
        <?php elseif (empty($records)): ?>
            <div class="alert alert-info" role="alert">
                <i class="fas fa-info-circle me-2"></i>Không có dữ liệu nào cho ngày <?= htmlspecialchars($filter_date) ?>.
            </div>
        <?php endif; ?>

        <!-- Form lọc -->
        <div class="form-section">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label for="filter_date" class="form-label">Chọn ngày:</label>
                    <input type="date" name="filter_date" id="filter_date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>" required>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-filter me-1"></i> Lọc
                    </button>
                    <a href="receive_from_hr.php" class="btn btn-secondary">
                        <i class="fas fa-sync me-1"></i> Quay Lại
                    </a>
                </div>
            </form>
        </div>

        <!-- Bảng danh sách vi phạm -->
        <div class="table-section">
            <h5 class="text-primary mb-3">Danh Sách Vi Phạm Ngày <?= htmlspecialchars($filter_date) ?></h5>
            <form method="POST" action="" id="violation-form">
                <!-- Thêm input ẩn để gửi filter_date -->
                <input type="hidden" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all"></th>
                                <th>Mã NV</th>
                                <th>Họ Tên</th>
                                <th>Phòng Ban</th>
                                <th>Chức Vụ</th>
                                <th>Giờ Vào</th>
                                <th>Giờ Ra</th>
                                <th>Thời Gian Làm</th>
                                <th>Trạng Thái</th>
                                <th>Giải Trình</th>
                                <th>Người Duyệt</th>
                                <th>Ghi Chú</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records) && $hr_data_exists > 0): ?>
                                <tr class="no-data">
                                    <td colspan="12">Không có dữ liệu nào cho ngày <?= htmlspecialchars($filter_date) ?></td>
                                </tr>
                            <?php elseif ($hr_data_exists == 0): ?>
                                <tr class="no-data">
                                    <td colspan="12">Chưa có dữ liệu từ HR cho ngày <?= htmlspecialchars($filter_date) ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $record): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="violation_ids[]" value="<?= $record['id'] ?>" class="violation-checkbox">
                                        </td>
                                        <td><?= htmlspecialchars($record['employee_id']) ?></td>
                                        <td><?= htmlspecialchars($record['full_name']) ?></td>
                                        <td><?= htmlspecialchars($record['department'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($record['position'] ?: 'N/A') ?></td>
                                        <td><?= htmlspecialchars($record['check_in']) ?></td>
                                        <td><?= htmlspecialchars($record['check_out'] ?: '-') ?></td>
                                        <td><?= $record['hours_worked'] ? number_format($record['hours_worked'], 2) . ' giờ' : '-' ?></td>
                                        <td class="<?= 'status-' . $record['deduction_status'] ?>">
                                            <?= htmlspecialchars($record['status']) ?>
                                        </td>
                                        <td><?= htmlspecialchars($record['explanation'] ?: 'Chưa giải trình') ?></td>
                                        <td><?= htmlspecialchars($record['approved_by'] ?: '-') ?></td>
                                        <td>
                                            <select name="deduction_status[]" class="form-select <?= 'status-' . $record['deduction_status'] ?>">
                                                <option value="pending" <?= $record['deduction_status'] === 'pending' ? 'selected' : '' ?>>Chưa xử lý</option>
                                                <option value="deducted" <?= $record['deduction_status'] === 'deducted' ? 'selected' : '' ?>>Trừ lương</option>
                                                <option value="not_deducted" <?= $record['deduction_status'] === 'not_deducted' ? 'selected' : '' ?>>Không trừ lương</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Thêm nút Xác nhận -->
                <?php if (!empty($records)): ?>
                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" name="process_violations" class="btn btn-success" id="confirm-btn">
                            <i class="fas fa-check me-1"></i> Xác nhận
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('select-all');
            const violationCheckboxes = document.querySelectorAll('.violation-checkbox');
            const confirmBtn = document.getElementById('confirm-btn');
            const form = document.getElementById('violation-form');

            // Xử lý checkbox "Chọn tất cả"
            selectAllCheckbox.addEventListener('change', function() {
                violationCheckboxes.forEach(checkbox => checkbox.checked = this.checked);
            });

            violationCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    if (!this.checked) selectAllCheckbox.checked = false;
                });
            });

            // Kiểm tra trước khi gửi form
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function(event) {
                    const checkedBoxes = document.querySelectorAll('.violation-checkbox:checked');
                    if (checkedBoxes.length === 0) {
                        event.preventDefault();
                        alert('Vui lòng chọn ít nhất một vi phạm để xử lý.');
                    }
                });
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>