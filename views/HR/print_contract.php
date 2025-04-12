<?php
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . "/../../core/salary-calculate.php";

// Lấy kết nối từ singleton Database
$db = Database::getInstance()->getConnection();

// Lấy ID nhân viên từ URL
$employee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($employee_id <= 0) {
    die("ID nhân viên không hợp lệ.");
}

// Lấy thông tin nhân viên
$stmt = $db->prepare("SELECT e.*, p.name as position_name, d.name as department_name, p.salary as position_salary
                    FROM employees e
                    LEFT JOIN positions p ON e.position_id = p.id
                    LEFT JOIN departments d ON e.department_id = d.id
                    WHERE e.id = :id");
$stmt->execute([':id' => $employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    die("Không tìm thấy nhân viên.");
}

// Lấy hợp đồng mới nhất của nhân viên
$stmt = $db->prepare("SELECT * FROM contracts WHERE employee_id = :employee_id AND status = 'active' ORDER BY start_date DESC LIMIT 1");
$stmt->execute([':employee_id' => $employee['id']]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

// Lấy thông số từ bảng settings
$settingsStmt = $db->query("SELECT name, value FROM settings");
$settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Gán giá trị mặc định nếu không có trong bảng settings
$bhxh_rate = $settings['bhxh_rate'] ?? 8; // 8%
$bhyt_rate = $settings['bhyt_rate'] ?? 1.5; // 1.5%
$bhtn_rate = $settings['bhtn_rate'] ?? 1; // 1%
$personal_deduction = $settings['personal_deduction'] ?? 11000000; // 11 triệu
$tax_rates = [
    5000000 => $settings['tax_rate_1'] ?? 5,
    10000000 => $settings['tax_rate_2'] ?? 10,
    18000000 => $settings['tax_rate_3'] ?? 15,
    32000000 => $settings['tax_rate_4'] ?? 20,
    52000000 => $settings['tax_rate_5'] ?? 25,
    80000000 => $settings['tax_rate_6'] ?? 30,
    PHP_FLOAT_MAX => $settings['tax_rate_7'] ?? 35
];

// Tính toán lương
$basicSalary = $contract['basic_salary'] ?? ($employee['salary'] ?? ($employee['position_salary'] ?? 0));
$allowance = $contract['allowance'] ?? 0;

$bhxh = $basicSalary * ($bhxh_rate / 100);
$bhyt = $basicSalary * ($bhyt_rate / 100);
$bhtn = $basicSalary * ($bhtn_rate / 100);
$total_insurance = $bhxh + $bhyt + $bhtn;

$taxable_income = $basicSalary - $total_insurance - $personal_deduction;
$tax = 0;
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

$net_salary = $basicSalary + $allowance - $total_insurance - $tax;
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>In Hợp Đồng - <?= htmlspecialchars($employee['full_name']) ?></title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            margin: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 24px;
            text-transform: uppercase;
        }
        .section-title {
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 10px;
            text-decoration: underline;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        .label {
            font-weight: bold;
            width: 40%;
        }
        .value {
            width: 60%;
        }
        .amount {
            font-weight: bold;
        }
        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="container">
        <div class="header">
            <h1>HỢP ĐỒNG LAO ĐỘNG</h1>
            <p>Mã nhân viên: <?= htmlspecialchars($employee['id']) ?></p>
            <p>Ngày in: <?= date('d/m/Y') ?></p>
        </div>

        <div class="section-title">Thông Tin Nhân Viên</div>
        <div class="detail-row">
            <span class="label">Họ và Tên:</span>
            <span class="value"><?= htmlspecialchars($employee['full_name']) ?></span>
        </div>
        <div class="detail-row">
            <span class="label">Email:</span>
            <span class="value"><?= htmlspecialchars($employee['email']) ?></span>
        </div>
        <div class="detail-row">
            <span class="label">Phòng Ban:</span>
            <span class="value"><?= htmlspecialchars($employee['department_name'] ?? 'Chưa cập nhật') ?></span>
        </div>
        <div class="detail-row">
            <span class="label">Chức Vụ:</span>
            <span class="value"><?= htmlspecialchars($employee['position_name'] ?? 'Chưa cập nhật') ?></span>
        </div>

        <?php if ($contract): ?>
            <div class="section-title">Thông Tin Hợp Đồng</div>
            <div class="detail-row">
                <span class="label">Mã Hợp Đồng:</span>
                <span class="value"><?= htmlspecialchars($contract['contract_code']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label">Loại Hợp Đồng:</span>
                <span class="value"><?= htmlspecialchars($contract['contract_type']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label">Ngày Bắt Đầu:</span>
                <span class="value"><?= date('d/m/Y', strtotime($contract['start_date'])) ?></span>
            </div>
            <div class="detail-row">
                <span class="label">Ngày Kết Thúc:</span>
                <span class="value"><?= $contract['end_date'] ? date('d/m/Y', strtotime($contract['end_date'])) : 'Không xác định' ?></span>
            </div>
            <div class="detail-row">
                <span class="label">Thời Gian Làm Việc:</span>
                <span class="value"><?= htmlspecialchars($contract['work_time'] ?? 'Chưa cập nhật') ?></span>
            </div>
            <div class="detail-row">
                <span class="label">Mô Tả Công Việc:</span>
                <span class="value"><?= htmlspecialchars($contract['job_description'] ?? 'Chưa cập nhật') ?></span>
            </div>

            <div class="section-title">Các Khoản Thu Nhập</div>
            <div class="detail-row">
                <span class="label">Lương Cơ Bản:</span>
                <span class="value"><?= number_format($basicSalary, 0, ',', '.') ?> VNĐ</span>
            </div>
            <div class="detail-row">
                <span class="label">Phụ Cấp:</span>
                <span class="value"><?= number_format($allowance, 0, ',', '.') ?> VNĐ</span>
            </div>

            <div class="section-title">Các Khoản Trừ</div>
            <div class="detail-row">
                <span class="label">BHXH (<?= $bhxh_rate ?>%):</span>
                <span class="value"><?= number_format($bhxh, 0, ',', '.') ?> VNĐ</span>
            </div>
            <div class="detail-row">
                <span class="label">BHYT (<?= $bhyt_rate ?>%):</span>
                <span class="value"><?= number_format($bhyt, 0, ',', '.') ?> VNĐ</span>
            </div>
            <div class="detail-row">
                <span class="label">BHTN (<?= $bhtn_rate ?>%):</span>
                <span class="value"><?= number_format($bhtn, 0, ',', '.') ?> VNĐ</span>
            </div>
            <div class="detail-row">
                <span class="label">Tổng Bảo Hiểm:</span>
                <span class="value"><?= number_format($total_insurance, 0, ',', '.') ?> VNĐ</span>
            </div>
            <div class="detail-row">
                <span class="label">Thuế TNCN:</span>
                <span class="value"><?= number_format($tax, 0, ',', '.') ?> VNĐ</span>
            </div>

            <div class="section-title">Lương Thực Nhận</div>
            <div class="detail-row">
                <span class="label">Tổng Thu Nhập:</span>
                <span class="value"><?= number_format($basicSalary + $allowance, 0, ',', '.') ?> VNĐ</span>
            </div>
            <div class="detail-row">
                <span class="label">Tổng Trừ:</span>
                <span class="value"><?= number_format($total_insurance + $tax, 0, ',', '.') ?> VNĐ</span>
            </div>
            <div class="detail-row">
                <span class="label">Lương Thực Nhận:</span>
                <span class="value amount"><?= number_format($net_salary, 0, ',', '.') ?> VNĐ</span>
            </div>
        <?php else: ?>
            <p>Nhân viên này chưa có hợp đồng hoạt động.</p>
        <?php endif; ?>
    </div>

    <script>
        window.onafterprint = function() {
            window.location.href = '/HRMpv/views/HR/contract.php';
        };
    </script>
</body>
</html>