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

// Lấy thông tin hợp đồng và nhân viên dựa trên ID
$employee_id = $_GET['id'] ?? '';
$error_message = '';
$success_message = '';

if (empty($employee_id)) {
    header("Location: /HRMpv/views/HR/contract.php?error=Không tìm thấy nhân viên");
    exit();
}

// Lấy thông tin nhân viên
$stmt = $db->prepare("SELECT e.*, p.name as position_name, d.name as department_name
                      FROM employees e
                      LEFT JOIN positions p ON e.position_id = p.id
                      LEFT JOIN departments d ON e.department_id = d.id
                      WHERE e.id = :id");
$stmt->execute([':id' => $employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header("Location: /HRMpv/views/HR/contract.php?error=Nhân viên không tồn tại");
    exit();
}

// Lấy thông tin hợp đồng mới nhất
$stmt = $db->prepare("SELECT * FROM contracts WHERE employee_id = :employee_id AND status = 'active' ORDER BY start_date DESC LIMIT 1");
$stmt->execute([':employee_id' => $employee_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    header("Location: /HRMpv/views/HR/contract.php?error=Nhân viên chưa có hợp đồng");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate và lấy dữ liệu từ form
        $contract_type = $_POST['contract_type'];
        $start_date = $_POST['start_date'];
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

        // Xử lý lương cơ bản
        $basic_salary = str_replace('.', '', $_POST['basic_salary']);
        $basic_salary = (float) $basic_salary;

        // Xử lý phụ cấp
        $allowance = !empty($_POST['allowance']) ? str_replace('.', '', $_POST['allowance']) : 0;
        $allowance = (float) $allowance;

        $work_time = $_POST['work_time'];
        $job_description = $_POST['job_description'];
        $notes = $_POST['notes'];

        // Kiểm tra ngày bắt đầu và kết thúc
        if ($end_date && strtotime($end_date) <= strtotime($start_date)) {
            throw new Exception('Ngày kết thúc phải sau ngày bắt đầu');
        }

        // Tính toán các khoản bảo hiểm
        $bhxh = $basic_salary * ($bhxh_rate / 100);
        $bhyt = $basic_salary * ($bhyt_rate / 100);
        $bhtn = $basic_salary * ($bhtn_rate / 100);
        $total_insurance = $bhxh + $bhyt + $bhtn;

        // Tính thuế TNCN
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

        // Tính lương thực nhận
        $net_salary = $basic_salary + $allowance - $total_insurance - $tax;

        // Cập nhật hợp đồng
        $sql = "UPDATE contracts SET 
                    contract_type = :contract_type,
                    start_date = :start_date,
                    end_date = :end_date,
                    basic_salary = :basic_salary,
                    allowance = :allowance,
                    work_time = :work_time,
                    job_description = :job_description,
                    notes = :notes
                WHERE employee_id = :employee_id AND contract_code = :contract_code";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            ':contract_type' => $contract_type,
            ':start_date' => $start_date,
            ':end_date' => $end_date,
            ':basic_salary' => $basic_salary,
            ':allowance' => $allowance,
            ':work_time' => $work_time,
            ':job_description' => $job_description,
            ':notes' => $notes,
            ':employee_id' => $employee_id,
            ':contract_code' => $contract['contract_code']
        ]);

        if ($result) {
            // Cập nhật thông tin lương và bảo hiểm cho nhân viên
            $sql = "UPDATE employees SET 
                    salary = :net_salary,
                    contract_type = :contract_type,
                    contract_end_date = :contract_end_date,
                    bhxh = :bhxh,
                    bhyt = :bhyt,
                    bhtn = :bhtn,
                    total_insurance = :total_insurance,
                    tax = :tax,
                    hire_date = :hire_date
                    WHERE id = :employee_id";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':net_salary' => $net_salary,
                ':contract_type' => $contract_type,
                ':contract_end_date' => $end_date,
                ':bhxh' => $bhxh,
                ':bhyt' => $bhyt,
                ':bhtn' => $bhtn,
                ':total_insurance' => $total_insurance,
                ':tax' => $tax,
                ':hire_date' => $start_date,
                ':employee_id' => $employee_id
            ]);

            header("Location: /HRMpv/views/HR/contract.php?success=Hợp đồng đã được cập nhật");
            exit();
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
ob_end_flush();
?>

<!-- CSS giống create_contract.php -->
<style>
/* Reset và cơ bản */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #f4f7fa;
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
}

/* Container */
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

/* Card */
.card {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    margin-top: 20px;
}

/* Card Header */
.card-header {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    padding: 20px;
    text-align: center;
}

.card-header h2 {
    color: #ffffff;
    font-size: 24px;
    font-weight: 600;
    letter-spacing: 1px;
    margin: 0;
}

/* Card Body */
.card-body {
    padding: 30px;
}

/* Alert */
.alert {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 16px;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Form */
.form-control {
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 10px 15px;
    font-size: 16px;
    transition: all 0.3s ease;
    width: 100%;
}

.form-control:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    outline: none;
}

.form-control:disabled {
    background: #f3f4f6;
    cursor: not-allowed;
}

textarea.form-control {
    resize: vertical;
}

/* Label */
label {
    font-weight: 500;
    color: #374151;
    margin-bottom: 8px;
    display: block;
}

/* Small text */
small.text-muted {
    font-size: 14px;
    color: #6b7280;
}

/* Row và Col */
.row {
    display: flex;
    flex-wrap: wrap;
    margin: 0 -15px;
}

.col-md-6 {
    flex: 0 0 50%;
    max-width: 50%;
    padding: 0 15px;
}

.mb-3 {
    margin-bottom: 20px;
}

/* Button */
.btn {
    padding: 12px 25px;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 500;
    text-align: center;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-primary {
    background: linear-gradient(90deg, #3b82f6, #1e40af);
    color: #ffffff;
}

.btn-primary:hover {
    background: linear-gradient(90deg, #2563eb, #1e3a8a);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
}

.btn-secondary {
    background: #6b7280;
    color: #ffffff;
    margin-left: 10px;
}

.btn-secondary:hover {
    background: #4b5563;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(107, 114, 128, 0.3);
}

/* Validation */
.was-validated .form-control:invalid,
.form-control.is-invalid {
    border-color: #dc3545;
    background-image: none;
}

.was-validated .form-control:invalid:focus,
.form-control.is-invalid:focus {
    box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
}

.was-validated .form-control:valid,
.form-control.is-valid {
    border-color: #28a745;
}

.was-validated .form-control:valid:focus,
.form-control.is-valid:focus {
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
}

/* Responsive cho Form */
@media (max-width: 768px) {
    .col-md-6 {
        flex: 0 0 100%;
        max-width: 100%;
    }

    .card-body {
        padding: 20px;
    }

    .btn {
        padding: 10px 20px;
        font-size: 14px;
    }
}
</style>

<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>Chỉnh Sửa Hợp Đồng - <?= htmlspecialchars($employee['full_name']) ?></h2>
        </div>
        <div class="card-body">
            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <form method="POST" class="needs-validation" novalidate>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="employee_id">Nhân Viên</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($employee['full_name']) ?> - <?= htmlspecialchars($employee['department_name']) ?> - <?= htmlspecialchars($employee['position_name']) ?>" disabled>
                        <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="contract_type">Loại Hợp Đồng</label>
                        <select class="form-control" name="contract_type" id="contract_type" required>
                            <option value="Hợp đồng thử việc" <?= $contract['contract_type'] === 'Hợp đồng thử việc' ? 'selected' : '' ?>>Hợp đồng thử việc</option>
                            <option value="Hợp đồng xác định thời hạn" <?= $contract['contract_type'] === 'Hợp đồng xác định thời hạn' ? 'selected' : '' ?>>Hợp đồng xác định thời hạn</option>
                            <option value="Hợp đồng không xác định thời hạn" <?= $contract['contract_type'] === 'Hợp đồng không xác định thời hạn' ? 'selected' : '' ?>>Hợp đồng không xác định thời hạn</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="start_date">Ngày Bắt Đầu</label>
                        <input type="date" class="form-control" name="start_date" 
                               value="<?= htmlspecialchars($contract['start_date']) ?>" required>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="end_date">Ngày Kết Thúc</label>
                        <input type="date" class="form-control" name="end_date" id="end_date" 
                               value="<?= $contract['end_date'] ? htmlspecialchars($contract['end_date']) : '' ?>">
                        <small class="text-muted">Để trống nếu là hợp đồng không xác định thời hạn</small>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="basic_salary">Lương Cơ Bản</label>
                        <input type="text" class="form-control" name="basic_salary" id="basic_salary" required
                               value="<?= number_format($contract['basic_salary'], 0, ',', '.') ?>"
                               oninput="this.value = formatNumber(this.value)"
                               placeholder="Nhập lương cơ bản">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="allowance">Phụ Cấp</label>
                        <input type="text" class="form-control" name="allowance"
                               value="<?= $contract['allowance'] ? number_format($contract['allowance'], 0, ',', '.') : '' ?>"
                               oninput="this.value = formatNumber(this.value)"
                               placeholder="Nhập phụ cấp (nếu có)">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="work_time">Thời Gian Làm Việc</label>
                    <input type="text" class="form-control" name="work_time" 
                           value="<?= htmlspecialchars($contract['work_time'] ?? '') ?>"
                           placeholder="VD: Thứ 2 - Thứ 6, 8:00 - 17:00">
                </div>

                <div class="mb-3">
                    <label for="job_description">Mô Tả Công Việc</label>
                    <textarea class="form-control" name="job_description" rows="3"><?= htmlspecialchars($contract['job_description'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="notes">Ghi Chú</label>
                    <textarea class="form-control" name="notes" rows="2"><?= htmlspecialchars($contract['notes'] ?? '') ?></textarea>
                </div>

                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary">Cập Nhật Hợp Đồng</button>
                    <a href="/HRMpv/views/HR/contract.php" class="btn btn-secondary">Hủy</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function formatNumber(num) {
    num = num.replace(/[^\d]/g, '');
    return num.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.needs-validation');
    const contractTypeSelect = document.querySelector('#contract_type');
    const endDateInput = document.querySelector('#end_date');

    if (form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    }

    if (contractTypeSelect) {
        contractTypeSelect.addEventListener('change', function() {
            if (this.value === 'Hợp đồng không xác định thời hạn') {
                endDateInput.value = '';
                endDateInput.disabled = true;
            } else {
                endDateInput.disabled = false;
            }
        });

        // Kích hoạt logic khi load trang
        if (contractTypeSelect.value === 'Hợp đồng không xác định thời hạn') {
            endDateInput.disabled = true;
        }
    }

    // Format các trường số khi load trang
    document.querySelectorAll('input[name="basic_salary"], input[name="allowance"]').forEach(input => {
        if (input.value) {
            input.value = formatNumber(input.value.replace(/\./g, ''));
        }
    });
});
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>