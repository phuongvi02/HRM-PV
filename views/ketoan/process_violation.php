<?php
ob_start();

require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . "/../../vendor/autoload.php";
require_once __DIR__ . '/../../views/layouts/sidebar_kt.php';
require_once __DIR__ . '/../../views/layouts/header_kt.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Kết nối cơ sở dữ liệu
$db = Database::getInstance()->getConnection();

// Kiểm tra file navbar và footer



// Xử lý cập nhật trạng thái vi phạm và trừ lương
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_violations'])) {
    try {
        $violation_ids = $_POST['violation_ids'] ?? [];
        $processing_statuses = $_POST['processing_status'] ?? [];

        if (empty($violation_ids)) {
            throw new Exception("Không có vi phạm nào được chọn để xử lý.");
        }

        $update_query = "UPDATE accounting_violations 
                         SET processing_status = :processing_status, 
                             deduction_status = CASE 
                                WHEN explanation IS NULL OR explanation = '' OR explanation_status = 'rejected' THEN 'deducted' 
                                ELSE 'not_deducted' 
                             END 
                         WHERE id = :id";
        $stmt = $db->prepare($update_query);

        foreach ($violation_ids as $index => $id) {
            $status = $processing_statuses[$index] ?? 'pending';
            $stmt->execute([
                ':processing_status' => $status,
                ':id' => $id
            ]);
        }

        $success_message = "Cập nhật trạng thái xử lý và trừ lương thành công!";
        header("Location: process_violation.php?success=" . urlencode($success_message));
        exit();
    } catch (Exception $e) {
        $error_message = "Lỗi khi xử lý vi phạm: " . $e->getMessage();
        header("Location: process_violation.php?error=" . urlencode($error_message));
        exit();
    }
}

// Lấy ngày lọc từ query string hoặc mặc định là hôm nay
$filter_date = $_GET['filter_date'] ?? date('Y-m-d');

// Truy vấn dữ liệu từ bảng accounting_violations
$query = "SELECT id, full_name, email, check_in, check_out, hours_worked, violation, explanation, explanation_status, processing_status, deduction_status 
          FROM accounting_violations 
          WHERE filter_date = :filter_date";
$stmt = $db->prepare($query);
$stmt->execute([':filter_date' => $filter_date]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xử Lý Vi Phạm Chấm Công</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .card { margin-bottom: 1.5rem; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15); }
        .card-header { font-weight: 500; padding: 1rem; }
        .table-responsive { max-height: 500px; }
        .status-pending { color: #ffc107; font-weight: bold; }
        .status-processed { color: #28a745; font-weight: bold; }
        .status-deducted { color: #dc3545; font-weight: bold; }
        .status-not-deducted { color: #28a745; font-weight: bold; }
    </style>
</head>
<body>
    <?php require_once '../layouts/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <h2 class="mb-4">Xử Lý Vi Phạm Chấm Công</h2>

        <!-- Hiển thị thông báo -->
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

        <!-- Form xử lý vi phạm -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Danh Sách Vi Phạm Ngày <?= htmlspecialchars($filter_date) ?></h5>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label for="filter_date" class="form-label">Chọn ngày:</label>
                        <input type="date" name="filter_date" id="filter_date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>" required>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-filter me-1"></i> Lọc
                        </button>
                    </div>
                </form>

                <form method="POST" action="">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead class="table-dark">
                                <tr>
                                    <th><input type="checkbox" id="select-all"></th>
                                    <th>Họ tên</th>
                                    <th>Email</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Thời gian làm việc</th>
                                    <th>Vi phạm</th>
                                    <th>Giải trình</th>
                                    <th>Trạng thái giải trình</th>
                                    <th>Trạng thái xử lý</th>
                                    <th>Trạng thái trừ lương</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($records)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center">Không có dữ liệu cho ngày <?= htmlspecialchars($filter_date) ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($records as $record): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="violation_ids[]" value="<?= $record['id'] ?>" class="violation-checkbox">
                                            </td>
                                            <td><?= htmlspecialchars($record['full_name']) ?></td>
                                            <td><?= htmlspecialchars($record['email']) ?></td>
                                            <td><?= htmlspecialchars($record['check_in']) ?></td>
                                            <td><?= htmlspecialchars($record['check_out']) ?></td>
                                            <td><?= $record['hours_worked'] ? number_format($record['hours_worked'], 2) . ' giờ' : '-' ?></td>
                                            <td><?= htmlspecialchars($record['violation']) ?></td>
                                            <td><?= htmlspecialchars($record['explanation']) ?></td>
                                            <td><?= htmlspecialchars($record['explanation_status']) ?></td>
                                            <td>
                                                <select name="processing_status[]" class="form-select">
                                                    <option value="pending" <?= $record['processing_status'] === 'pending' ? 'selected' : '' ?>>Chưa xử lý</option>
                                                    <option value="processed" <?= $record['processing_status'] === 'processed' ? 'selected' : '' ?>>Đã xử lý</option>
                                                </select>
                                            </td>
                                            <td class="<?= $record['deduction_status'] === 'deducted' ? 'status-deducted' : 'status-not-deducted' ?>">
                                                <?= $record['deduction_status'] === 'deducted' ? 'Trừ lương' : 'Không trừ lương' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($records)): ?>
                        <button type="submit" name="process_violations" class="btn btn-primary mt-3">
                            <i class="fas fa-save me-1"></i> Cập nhật trạng thái
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <?php require_once '../layouts/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tự động đóng thông báo sau 3 giây
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    bootstrap.Alert.getInstance(alert)?.close();
                });
            }, 3000);

            // Checkbox "Chọn tất cả"
            const selectAllCheckbox = document.getElementById('select-all');
            const violationCheckboxes = document.querySelectorAll('.violation-checkbox');

            selectAllCheckbox.addEventListener('change', function() {
                violationCheckboxes.forEach(checkbox => {
                    checkbox.checked = selectAllCheckbox.checked;
                });
            });

            violationCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    if (!this.checked) {
                        selectAllCheckbox.checked = false;
                    }
                });
            });
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>