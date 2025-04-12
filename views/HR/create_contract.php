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

// Lấy ngày hiện tại
$current_date = date('Y-m-d');

// Lấy toàn bộ danh sách nhân viên
$stmt = $db->query("SELECT e.*, p.name as position_name, d.name as department_name
                    FROM employees e
                    LEFT JOIN positions p ON e.position_id = p.id
                    LEFT JOIN departments d ON e.department_id = d.id
                    ORDER BY e.full_name");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Tạo mã hợp đồng tự động: HD + YYYYMMDD + số thứ tự 4 chữ số
        $today = date('Ymd');
        $stmt = $db->query("SELECT MAX(SUBSTRING(contract_code, -4)) as max_num 
                           FROM contracts 
                           WHERE contract_code LIKE 'HD{$today}%'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_num = str_pad(((int)($result['max_num'] ?? 0) + 1), 4, '0', STR_PAD_LEFT);
        $contract_code = "HD{$today}{$next_num}";

        // Validate và lấy dữ liệu từ form
        $employee_id = $_POST['employee_id'];
        $contract_type = $_POST['contract_type'];
        $start_date = $_POST['start_date'];
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        
        // Xử lý lương cơ bản
        $basic_salary = str_replace('.', '', $_POST['basic_salary']);
        $basic_salary = (float) $basic_salary;

        // Xử lý phụ cấp
        $allowance = !empty($_POST['allowance']) ? str_replace('.', '', $_POST['allowance']) : 0;
        $allowance = (float) $allowance;

        // Tỷ lệ bảo hiểm và thuế
        $insurance_rate = $_POST['insurance_rate'] ?? 0;
        $tax_rate = $_POST['tax_rate'] ?? 0;

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

        // Thêm hợp đồng mới
        $sql = "INSERT INTO contracts (
                    contract_code, employee_id, contract_type, start_date, end_date,
                    basic_salary, allowance, insurance_rate, tax_rate,
                    work_time, job_description, notes, status
                ) VALUES (
                    :contract_code, :employee_id, :contract_type, :start_date, :end_date,
                    :basic_salary, :allowance, :insurance_rate, :tax_rate,
                    :work_time, :job_description, :notes, 'active'
                )";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            ':contract_code' => $contract_code,
            ':employee_id' => $employee_id,
            ':contract_type' => $contract_type,
            ':start_date' => $start_date,
            ':end_date' => $end_date,
            ':basic_salary' => $basic_salary,
            ':allowance' => $allowance,
            ':insurance_rate' => $insurance_rate,
            ':tax_rate' => $tax_rate,
            ':work_time' => $work_time,
            ':job_description' => $job_description,
            ':notes' => $notes
        ]);

        if ($result) {
            // Cập nhật thông tin lương và bảo hiểm cho nhân viên
            $sql = "UPDATE employees SET 
                    salary = :net_salary,  -- Cập nhật lương thực nhận
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

            // Chuyển hướng đến trang danh sách hợp đồng
            header("Location: /HRMpv/views/HR/contract.php?success=1");
            exit();
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
ob_end_flush();
?>
<style> /* Reset và cơ bản */
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

/* Navbar */
.navbar {
    width: 100%;
    height: 70px;
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: white;
    padding: 0 20px;
    position: fixed;
    top: 0;
    left: 0;
    z-index: 100;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    transition: height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.navbar.open {
    height: 70px;
}

.navbar-header {
    display: flex;
    align-items: center;
    gap: 15px;
}

.toggle-btn {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #ffffff;
    font-size: 22px;
    cursor: pointer;
    padding: 8px;
    border-radius: 50%;
    transition: all 0.3s ease;
    display: none;
}

.toggle-btn:hover {
    transform: rotate(180deg);
    background: rgba(255, 255, 255, 0.2);
}

.navbar h2 {
    font-size: 26px;
    font-weight: 700;
    letter-spacing: 2px;
    color: #ffffff;
    text-transform: uppercase;
    background: linear-gradient(90deg, #ffffff, #a3bffa);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.navbar ul {
    list-style: none;
    display: flex;
    gap: 10px;
    margin: 0;
    padding: 0;
}

.navbar ul li {
    padding: 10px 15px;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s ease;
    border-radius: 8px;
    position: relative;
    overflow: hidden;
}

.navbar ul li::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.1);
    transition: left 0.3s ease;
    z-index: 0;
}

.navbar ul li:hover::before {
    left: 0;
}

.navbar ul li a {
    text-decoration: none;
    color: #e0e7ff;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 16px;
    font-weight: 500;
    white-space: nowrap;
    transition: color 0.3s ease;
    position: relative;
    z-index: 1;
}

.navbar ul li i {
    font-size: 20px;
    min-width: 25px;
    text-align: center;
    transition: transform 0.3s ease;
}

.navbar ul li:hover a {
    color: #ffffff;
}

.navbar ul li:hover i {
    transform: scale(1.15);
}

.navbar ul li.active {
    background: linear-gradient(90deg, #3b82f6, #1e40af);
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
}

.navbar ul li.active a {
    color: #ffffff;
    font-weight: 600;
}

/* Content */


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

/* Responsive cho Navbar */
@media (max-width: 768px) {
    .navbar {
        height: 60px;
        flex-direction: column;
        align-items: flex-start;
        padding: 10px 20px;
    }

    .navbar-header {
        width: 100%;
        justify-content: space-between;
    }

    .toggle-btn {
        display: block;
    }

    .navbar ul {
        display: none;
        flex-direction: column;
        width: 100%;
        padding-bottom: 10px;
    }

    .navbar.open ul {
        display: flex;
    }

    .content {
        margin-top: 60px;
    }

    .content.open {
        margin-top: 200px;
    }
}

@media (max-width: 480px) {
    .navbar {
        height: 50px;
    }

    .navbar h2 {
        font-size: 20px;
    }

    .navbar ul li a {
        font-size: 14px;
    }

    .content {
        margin-top: 50px;
    }

    .content.open {
        margin-top: 180px;
    }
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

@media (max-width: 480px) {
    .card-header h2 {
        font-size: 20px;
    }

    .form-control {
        font-size: 14px;
        padding: 8px 12px;
    }

    label {
        font-size: 14px;
    }
}</style>
<link rel="stylesheet" href="/HRMpv/public/css/style.css">

<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>Tạo Hợp Đồng Mới</h2>
        </div>
        <div class="card-body">
            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <?php if (empty($employees)): ?>
                <div class="alert alert-info">Không có nhân viên nào trong hệ thống.</div>
            <?php else: ?>
                <form method="POST" class="needs-validation" novalidate>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="employee_id">Nhân Viên</label>
                            <select class="form-control" name="employee_id" id="employee_id" required>
                                <option value="">Chọn nhân viên</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= $employee['id'] ?>" 
                                            data-salary="<?= $employee['salary'] ?? 0 ?>">
                                        <?= htmlspecialchars($employee['full_name']) ?> - 
                                        <?= htmlspecialchars($employee['department_name']) ?> - 
                                        <?= htmlspecialchars($employee['position_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="contract_type">Loại Hợp Đồng</label>
                            <select class="form-control" name="contract_type" id="contract_type" required>
                                <option value="">Chọn loại hợp đồng</option>
                                <option value="Hợp đồng thử việc">Hợp đồng thử việc</option>
                                <option value="Hợp đồng xác định thời hạn">Hợp đồng xác định thời hạn</option>
                                <option value="Hợp đồng không xác định thời hạn">Hợp đồng không xác định thời hạn</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_date">Ngày Bắt Đầu</label>
                            <input type="date" class="form-control" name="start_date" 
                                   value="<?= $current_date ?>" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="end_date">Ngày Kết Thúc</label>
                            <input type="date" class="form-control" name="end_date" id="end_date">
                            <small class="text-muted">Để trống nếu là hợp đồng không xác định thời hạn</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="basic_salary">Lương Cơ Bản</label>
                            <input type="text" class="form-control" name="basic_salary" id="basic_salary" required
                                   oninput="this.value = formatNumber(this.value)"
                                   placeholder="Nhập lương cơ bản">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="allowance">Phụ Cấp</label>
                            <input type="text" class="form-control" name="allowance"
                                   oninput="this.value = formatNumber(this.value)"
                                   placeholder="Nhập phụ cấp (nếu có)">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="work_time">Thời Gian Làm Việc</label>
                        <input type="text" class="form-control" name="work_time" 
                               placeholder="VD: Thứ 2 - Thứ 6, 8:00 - 17:00">
                    </div>

                    <div class="mb-3">
                        <label for="job_description">Mô Tả Công Việc</label>
                        <textarea class="form-control" name="job_description" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="notes">Ghi Chú</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>

                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary">Tạo Hợp Đồng</button>
                        <a href="/HRMpv/views/HR/contract.php" class="btn btn-secondary">Hủy</a>
                    </div>
                </form>
            <?php endif; ?>
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
    const employeeSelect = document.querySelector('#employee_id');
    const basicSalaryInput = document.querySelector('#basic_salary');

    // Hiển thị lương cơ bản khi chọn nhân viên
    if (employeeSelect) {
        employeeSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const salary = selectedOption.getAttribute('data-salary') || 0;
            basicSalaryInput.value = salary ? formatNumber(salary.toString()) : '';
        });

        // Trigger change event ngay khi load để hiển thị lương nếu đã chọn nhân viên
        if (employeeSelect.value) {
            employeeSelect.dispatchEvent(new Event('change'));
        } else {
            basicSalaryInput.value = ''; // Đặt rỗng nếu chưa chọn nhân viên
        }
    }

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
    }

    // Format các trường số khi load trang
    document.querySelectorAll('input[name="basic_salary"], input[name="allowance"]').forEach(input => {
        if (input.value) {
            input.value = formatNumber(input.value);
        }
    });
});
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>