<?php
ob_start();
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';

// Lấy kết nối từ singleton Database
$db = Database::getInstance()->getConnection();

// Lấy thông số từ bảng settings
$settingsStmt = $db->query("SELECT name, value FROM settings");
$settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Gán giá trị mặc định nếu không có trong bảng settings
$bhxh_rate = $settings['bhxh_rate'] ?? 8; // 8%
$bhyt_rate = $settings['bhyt_rate'] ?? 1.5; // 1.5%
$bhtn_rate = $settings['bhtn_rate'] ?? 1; // 1%
$personal_deduction = $settings['personal_deduction'] ?? 11000000; // 11 triệu
$tax_rates = [
    5000000 => $settings['tax_rate_1'] ?? 5, // 5%
    10000000 => $settings['tax_rate_2'] ?? 10, // 10%
    18000000 => $settings['tax_rate_3'] ?? 15, // 15%
    32000000 => $settings['tax_rate_4'] ?? 20, // 20%
    52000000 => $settings['tax_rate_5'] ?? 25, // 25%
    80000000 => $settings['tax_rate_6'] ?? 30, // 30%
    PHP_FLOAT_MAX => $settings['tax_rate_7'] ?? 35 // 35%
];

// Lấy danh sách tất cả nhân viên
$stmt = $db->query("SELECT e.*, p.name as position_name, d.name as department_name
                    FROM employees e
                    LEFT JOIN positions p ON e.position_id = p.id
                    LEFT JOIN departments d ON e.department_id = d.id
                    ORDER BY e.full_name");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_end_flush();
?>
<style>/* style.css */

/* Tổng thể */
body {
    font-family: Arial, sans-serif;
    background-color: #f4f6f9;
    color: #333;
}

.container {
    max-width: 1200px;
    margin: 20px auto;
    padding: 0 15px;
}

/* Card */
.card {
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.card-header {
    background-color: #007bff;
    color: #fff;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-header h2 {
    margin: 0;
    font-size: 24px;
}

.card-body {
    padding: 20px;
}

/* Bảng */
.table {
    width: 100%;
    margin-bottom: 0;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 12px 15px;
    vertical-align: middle;
    border: 1px solid #dee2e6;
}

.table thead th {
    background-color: #f8f9fa;
    font-weight: bold;
    text-align: left;
    color: #495057;
}

.table tbody tr:hover {
    background-color: #f1f3f5;
    transition: background-color 0.2s;
}

.table tbody td {
    color: #555;
}

/* Nút */
.btn {
    padding: 8px 16px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 14px;
    transition: background-color 0.3s, box-shadow 0.3s;
}

.btn-primary {
    background-color: #007bff;
    border: none;
    color: #fff;
}

.btn-primary:hover {
    background-color: #0056b3;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.btn-info {
    background-color: #17a2b8;
    border: none;
    color: #fff;
}

.btn-info:hover {
    background-color: #117a8b;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.float-right {
    float: right;
}

/* Modal */
.modal-content {
    border-radius: 8px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
}

.modal-header {
    background-color: #007bff;
    color: #fff;
    border-bottom: none;
    padding: 15px 20px;
}

.modal-header .btn-close {
    filter: invert(1);
}

.modal-title {
    font-size: 20px;
}

.modal-body {
    padding: 20px;
}

.modal-body h6 {
    color: #007bff;
    font-size: 16px;
    margin-top: 20px;
    margin-bottom: 10px;
    border-bottom: 1px solid #dee2e6;
    padding-bottom: 5px;
}

.modal-body .table {
    font-size: 14px;
}

.modal-body .table th {
    background-color: #f8f9fa;
    width: 40%;
}

.modal-body .table td {
    color: #555;
}

/* Alert */
.alert {
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 20px;
}

.alert-success {
    background-color: #d4edda;
    border-color: #c3e6cb;
    color: #155724;
}

.alert-info {
    background-color: #cce5ff;
    border-color: #b8daff;
    color: #004085;
}

/* Responsive */
@media (max-width: 768px) {
    .card-header {
        flex-direction: column;
        text-align: center;
    }

    .card-header .btn {
        margin-top: 10px;
    }

    .table th,
    .table td {
        padding: 8px;
        font-size: 14px;
    }

    .modal-dialog {
        margin: 10px;
    }
}</style>
<link rel="stylesheet" href="/HRMpv/public/css/style.css">
<!-- Bootstrap CSS (nếu chưa có trong header_hr.php) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>Danh Sách Nhân Viên và Hợp Đồng</h2>
            <a href="/HRMpv/views/HR/create_contract.php" class="btn btn-primary float-right">Tạo Hợp Đồng Mới</a>
        </div>
        <div class="card-body">
            <?php if (empty($employees)): ?>
                <div class="alert alert-info">Chưa có nhân viên nào trong hệ thống.</div>
            <?php else: ?>
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>Tên Nhân Viên</th>
                            <th>Phòng Ban</th>
                            <th>Chức Vụ</th>
                            <th>Loại Hợp Đồng</th>
                            <th>Ngày Bắt Đầu</th>
                            <th>Ngày Kết Thúc</th>
                            <th>Hành Động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $employee): ?>
                            <?php
                            // Lấy thông tin hợp đồng mới nhất của nhân viên (nếu có)
                            $stmt = $db->prepare("SELECT * FROM contracts WHERE employee_id = :employee_id AND status = 'active' ORDER BY start_date DESC LIMIT 1");
                            $stmt->execute([':employee_id' => $employee['id']]);
                            $contract = $stmt->fetch(PDO::FETCH_ASSOC);

                            // Tính toán các khoản trừ nếu có hợp đồng
                            $basic_salary = $contract['basic_salary'] ?? 0;
                            $allowance = $contract['allowance'] ?? 0;
                            $bhxh = $basic_salary * ($bhxh_rate / 100);
                            $bhyt = $basic_salary * ($bhyt_rate / 100);
                            $bhtn = $basic_salary * ($bhtn_rate / 100);
                            $total_insurance = $bhxh + $bhyt + $bhtn;

                            $tax = 0;
                            $taxable_income = $basic_salary - $total_insurance - $personal_deduction;
                            if ($taxable_income > 0) {
                                $remaining_income = $taxable_income;
                                $previous_limit = 0;
                                foreach ($tax_rates as $limit => $rate) {
                                    if ($remaining_income <= 0) break;
                                    $taxable_in_bracket = min($remaining_income, $limit - $previous_limit);
                                    $tax += $taxable_in_bracket * ($rate / 100);
                                    $remaining_income -= $taxable_in_bracket;
                                    $previous_limit = $limit;
                                }
                            }
                            $net_salary = $basic_salary + $allowance - $total_insurance - $tax;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($employee['full_name']) ?></td>
                                <td><?= htmlspecialchars($employee['department_name']) ?></td>
                                <td><?= htmlspecialchars($employee['position_name']) ?></td>
                                <td><?= $contract ? htmlspecialchars($contract['contract_type']) : 'Chưa có' ?></td>
                                <td><?= $contract ? htmlspecialchars($contract['start_date']) : '-' ?></td>
                                <td><?= $contract && $contract['end_date'] ? htmlspecialchars($contract['end_date']) : 'Không xác định' ?></td>
                                <td>
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" 
                                            data-bs-target="#contractModal<?= $employee['id'] ?>">Xem Chi Tiết</button>
                                </td>
                            </tr>

                            <!-- Modal hiển thị chi tiết hợp đồng và các khoản trừ -->
                            <div class="modal fade" id="contractModal<?= $employee['id'] ?>" tabindex="-1" 
                                 aria-labelledby="contractModalLabel<?= $employee['id'] ?>" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="contractModalLabel<?= $employee['id'] ?>">
                                                Chi Tiết Hợp Đồng - <?= htmlspecialchars($employee['full_name']) ?>
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" 
                                                    aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <?php if ($contract): ?>
                                                <h6>Thông Tin Hợp Đồng</h6>
                                                <table class="table table-sm">
                                                    <tr>
                                                        <th>Mã Hợp Đồng</th>
                                                        <td><?= htmlspecialchars($contract['contract_code']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Loại Hợp Đồng</th>
                                                        <td><?= htmlspecialchars($contract['contract_type']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Ngày Bắt Đầu</th>
                                                        <td><?= htmlspecialchars($contract['start_date']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Ngày Kết Thúc</th>
                                                        <td><?= $contract['end_date'] ? htmlspecialchars($contract['end_date']) : 'Không xác định' ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Thời Gian Làm Việc</th>
                                                        <td><?= htmlspecialchars($contract['work_time']) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <th>Mô Tả Công Việc</th>
                                                        <td><?= htmlspecialchars($contract['job_description']) ?></td>
                                                    </tr>
                                                </table>

                                                <h6>Các Khoản Thu Nhập</h6>
                                                <table class="table table-sm">
                                                    <tr>
                                                        <th>Lương Cơ Bản</th>
                                                        <td><?= number_format($basic_salary, 0, ',', '.') ?> VNĐ</td>
                                                    </tr>
                                                    <tr>
                                                        <th>Phụ Cấp</th>
                                                        <td><?= number_format($allowance, 0, ',', '.') ?> VNĐ</td>
                                                    </tr>
                                                </table>

                                                <h6>Các Khoản Trừ</h6>
                                                <table class="table table-sm">
                                                    <tr>
                                                        <th>BHXH (<?= $bhxh_rate ?>%)</th>
                                                        <td><?= number_format($bhxh, 0, ',', '.') ?> VNĐ</td>
                                                    </tr>
                                                    <tr>
                                                        <th>BHYT (<?= $bhyt_rate ?>%)</th>
                                                        <td><?= number_format($bhyt, 0, ',', '.') ?> VNĐ</td>
                                                    </tr>
                                                    <tr>
                                                        <th>BHTN (<?= $bhtn_rate ?>%)</th>
                                                        <td><?= number_format($bhtn, 0, ',', '.') ?> VNĐ</td>
                                                    </tr>
                                                    <tr>
                                                        <th>Tổng Bảo Hiểm</th>
                                                        <td><?= number_format($total_insurance, 0, ',', '.') ?> VNĐ</td>
                                                    </tr>
                                                    <tr>
                                                        <th>Thuế TNCN</th>
                                                        <td><?= number_format($tax, 0, ',', '.') ?> VNĐ</td>
                                                    </tr>
                                                </table>

                                                <h6>Lương Thực Nhận</h6>
                                                <table class="table table-sm">
                                                    <tr>
                                                        <th>Tổng Thu Nhập</th>
                                                        <td><?= number_format($basic_salary + $allowance, 0, ',', '.') ?> VNĐ</td>
                                                    </tr>
                                                    <tr>
                                                        <th>Tổng Trừ</th>
                                                        <td><?= number_format($total_insurance + $tax, 0, ',', '.') ?> VNĐ</td>
                                                    </tr>
                                                    <tr>
                                                        <th>Lương Thực Nhận</th>
                                                        <td><?= number_format($net_salary, 0, ',', '.') ?> VNĐ</td>
                                                    </tr>
                                                </table>
                                            <?php else: ?>
                                                <p>Nhân viên này chưa có hợp đồng hoạt động.</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" 
                                                    data-bs-dismiss="modal">Đóng</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bootstrap JS (nếu chưa có trong footer.php) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Hiệu ứng hover cho bảng
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

<?php include __DIR__ . '/../layouts/footer.php'; ?>