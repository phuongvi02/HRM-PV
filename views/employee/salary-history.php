<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . "/../../core/salary-calculate.php";
require_once '../layouts/header_employee.php';
require_once '../layouts/sidebar_employee.php';
require_once '../layouts/navbar_employee.php';

// Kiểm tra phiên làm việc và vai trò (giả sử role_id = 5 là nhân viên)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 5) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

// Lấy employee_id từ session
$employee_id = $_SESSION['user_id'];

$db = Database::getInstance()->getConnection();

// Lấy thông tin nhân viên
$stmt = $db->prepare("
    SELECT e.*, p.name AS position_name, d.name AS department_name 
    FROM employees e 
    LEFT JOIN positions p ON e.position_id = p.id 
    LEFT JOIN departments d ON e.department_id = d.id 
    WHERE e.id = :employee_id
");
$stmt->execute([':employee_id' => $employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    echo "<script>alert('Không tìm thấy thông tin nhân viên!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

// Lấy lương cơ bản từ hợp đồng mới nhất còn hiệu lực
$stmt = $db->prepare("
    SELECT basic_salary 
    FROM contracts 
    WHERE employee_id = :employee_id 
    AND start_date <= :month_end 
    AND (end_date IS NULL OR end_date >= :month_start) 
    ORDER BY start_date DESC 
    LIMIT 1
");
$month = $_POST['month'] ?? date('Y-m');
$month_start = "$month-01";
$month_end = date('Y-m-t', strtotime($month_start)); // Ngày cuối tháng
$stmt->execute([':employee_id' => $employee_id, ':month_start' => $month_start, ':month_end' => $month_end]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract || !$contract['basic_salary']) {
    echo "<script>alert('Không tìm thấy hợp đồng hoặc lương cơ bản trong hợp đồng!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

// Lấy thông số từ bảng settings
$settingsStmt = $db->query("SELECT name, value FROM settings");
$settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Xử lý kỳ lương từ form (mặc định là tháng hiện tại)
$deductionPerViolation = 50000; // Mức phạt vi phạm mặc định
$penaltyPerUnexplainedDay = 100000; // Mức phạt nghỉ không giải trình mặc định

// Tính số ngày đi làm
$stmt = $db->prepare("
    SELECT COUNT(*) as attendance_days 
    FROM attendance 
    WHERE employee_id = :employee_id 
    AND DATE_FORMAT(check_in, '%Y-%m') = :month 
    AND check_in IS NOT NULL
");
$stmt->execute([':employee_id' => $employee_id, ':month' => $month]);
$attendanceDays = $stmt->fetch(PDO::FETCH_ASSOC)['attendance_days'];

// Tính số ngày nghỉ không giải trình
$stmt = $db->prepare("
    SELECT COUNT(*) as absent_days
    FROM attendance 
    WHERE employee_id = :employee_id 
    AND DATE_FORMAT(date, '%Y-%m') = :month 
    AND status = 'absent'
    AND date NOT IN (
        SELECT DATE(start_date) 
        FROM leave_requests 
        WHERE employee_id = :employee_id 
        AND status = 'Đã duyệt' 
        AND DATE_FORMAT(start_date, '%Y-%m') = :month
    )
");
$stmt->execute([':employee_id' => $employee_id, ':month' => $month]);
$absentDaysWithoutExplanation = $stmt->fetch(PDO::FETCH_ASSOC)['absent_days'];

// Tính giờ làm thêm
$stmt = $db->prepare("
    SELECT overtime_date, TIMESTAMPDIFF(HOUR, start_time, end_time) as hours 
    FROM overtime 
    WHERE employee_id = :employee_id 
    AND DATE_FORMAT(overtime_date, '%Y-%m') = :month 
    AND status = 'approved'
");
$stmt->execute([':employee_id' => $employee_id, ':month' => $month]);
$overtimeRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalOvertimePay = 0;
$fullBasicSalary = $contract['basic_salary']; // Lương cơ bản từ hợp đồng
$hourlyRate = $fullBasicSalary / 160; // Giả định 160 giờ/tháng
$overtimeDetails = [];
foreach ($overtimeRecords as $record) {
    $date = $record['overtime_date'];
    $hours = $record['hours'];
    $isWeekend = (date('N', strtotime($date)) >= 6);
    $isHoliday = false; // Có thể thêm bảng holidays
    $rateMultiplier = $isHoliday ? 3.0 : ($isWeekend ? 2.0 : 1.5);
    $overtimePay = $hours * $hourlyRate * $rateMultiplier;

    $overtimeDetails[] = [
        'date' => $date,
        'hours' => $hours,
        'pay' => $overtimePay,
        'type' => $isHoliday ? 'Ngày lễ' : ($isWeekend ? 'Cuối tuần' : 'Ngày thường')
    ];
    $totalOvertimePay += $overtimePay;
}

// Tính lương cơ bản thực tế
$basicSalary = ($attendanceDays >= 25) ? $fullBasicSalary : ($fullBasicSalary * $attendanceDays / 25);

// Tính tiền phạt và chi tiết vi phạm
$stmt = $db->prepare("
    SELECT filter_date, status, explanation, deduction_status 
    FROM accounting_attendance 
    WHERE employee_id = :employee_code 
    AND DATE_FORMAT(filter_date, '%Y-%m') = :month
");
$stmt->execute([':employee_code' => $employee['employee_code'], ':month' => $month]);
$violationRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

$violationCount = 0;
$violationDetails = [];
foreach ($violationRecords as $record) {
    $isViolation = ($record['deduction_status'] === 'deducted');
    if ($isViolation) {
        $violationCount++;
    }
    $violationDetails[] = [
        'date' => $record['filter_date'],
        'violation' => $record['status'],
        'explanation' => $record['explanation'],
        'deduction' => $isViolation ? $deductionPerViolation : 0,
        'status' => $isViolation ? 'Trừ lương' : 'Không trừ lương'
    ];
}
$deductions = $violationCount * $deductionPerViolation;

// Tính phạt nghỉ không giải trình
$unexplainedAbsencePenalty = $absentDaysWithoutExplanation * $penaltyPerUnexplainedDay;
$deductions += $unexplainedAbsencePenalty;

// Tính thưởng/phạt từ rewards
$stmt = $db->prepare("
    SELECT type, SUM(amount) as total_amount 
    FROM rewards 
    WHERE employee_id = :employee_id 
    AND DATE_FORMAT(date, '%Y-%m') = :month 
    GROUP BY type
");
$stmt->execute([':employee_id' => $employee_id, ':month' => $month]);
$rewardRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

$bonuses = 0;
$penalties = 0;
foreach ($rewardRecords as $record) {
    if ($record['type'] === 'Thưởng') {
        $bonuses = $record['total_amount'] ?? 0;
    } elseif ($record['type'] === 'Phạt') {
        $penalties = $record['total_amount'] ?? 0;
    }
}
$deductions += $penalties;

// Tính lương ứng
$stmt = $db->prepare("
    SELECT SUM(amount) as total_advance 
    FROM salary_advances 
    WHERE employee_id = :employee_id 
    AND DATE_FORMAT(request_date, '%Y-%m') = :month 
    AND status = 'Đã duyệt'
");
$stmt->execute([':employee_id' => $employee_id, ':month' => $month]);
$salaryAdvance = $stmt->fetch(PDO::FETCH_ASSOC)['total_advance'] ?? 0;
$deductions += $salaryAdvance;

// Tính lương với SalaryCalculator
$calculator = new SalaryCalculator($basicSalary, 0);
$salaryDetails = $calculator->getSalaryDetails();

// Điều chỉnh lương
$salaryDetails['deductions'] = $deductions;
$salaryDetails['bonuses'] = $bonuses;
$salaryDetails['overtime_pay'] = $totalOvertimePay;
$salaryDetails['penalties'] = $penalties;
$salaryDetails['unexplained_absence_penalty'] = $unexplainedAbsencePenalty;
$salaryDetails['salary_advance'] = $salaryAdvance;
$salaryDetails['net_salary'] = $salaryDetails['net_salary'] - $deductions + $bonuses + $totalOvertimePay;

// Cảnh báo nếu lương âm
$warningMessage = $salaryDetails['net_salary'] < 0 ? "Cảnh báo: Lương thực nhận âm do tổng tiền phạt và lương ứng vượt quá lương!" : "";
?>

<link rel="stylesheet" href="/HRMpv/public/css/styles.css">

<style>
.salary-container {
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
    min-height: calc(100vh - 200px);
    margin-left: 250px;
    transition: margin-left 0.3s ease;
}

.salary-container h2 {
    margin-bottom: 30px;
    text-align: center;
    color: #333;
    font-size: 2rem;
}

.salary-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-bottom: 20px;
}

.salary-info {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
}

.salary-details {
    flex: 1;
    min-width: 300px;
    margin-bottom: 15px;
}

.salary-details h4 {
    margin-bottom: 10px;
    color: #007bff;
    font-size: 1.25rem;
}

.salary-details p {
    margin: 5px 0;
    color: #555;
    font-size: 1rem;
}

.salary-details p strong {
    color: #333;
}

.salary-details p.deduction {
    color: #e74c3c;
}

.salary-details p.bonus {
    color: #28a745;
}

.total-salary {
    font-size: 1.2rem;
    font-weight: bold;
    color: #28a745;
    margin-top: 10px;
}

.alert {
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid transparent;
    border-radius: 4px;
    text-align: center;
}

.alert-info {
    color: #31708f;
    background-color: #d9edf7;
    border-color: #bce8f1;
}

.alert-warning {
    color: #856404;
    background-color: #fff3cd;
    border-color: #ffeeba;
}

.form-control {
    padding: 8px;
    border-radius: 4px;
    border: 1px solid #ccc;
    width: 100%;
    max-width: 200px;
}

.btn {
    padding: 8px 16px;
    border-radius: 4px;
    background-color: #007bff;
    color: white;
    border: none;
    cursor: pointer;
}

.btn:hover {
    background-color: #0056b3;
}

@media (max-width: 768px) {
    .salary-container {
        margin-left: 0;
        padding: 10px;
    }
    .salary-container h2 { font-size: 1.5rem; }
    .salary-info { flex-direction: column; align-items: flex-start; }
    .salary-details { min-width: 100%; }
}
</style>

<div class="salary-container">
    <h2>Lịch Sử Lương - <?= htmlspecialchars($employee['full_name'] ?? 'N/A') ?></h2>

    <form method="POST" style="margin-bottom: 20px; text-align: center;">
        <label for="month">Chọn tháng: </label>
        <input type="month" name="month" id="month" class="form-control" value="<?= $month ?>" required>
        <button type="submit" class="btn">Xem lương</button>
    </form>

    <?php if ($attendanceDays <= 0): ?>
        <div class="alert alert-info">Bạn chưa có dữ liệu chấm công cho kỳ lương <?= $month ?>.</div>
    <?php else: ?>
        <div class="salary-card">
            <div class="salary-info">
                <div class="salary-details">
                    <h4>Chức vụ: <?= htmlspecialchars($employee['position_name'] ?? 'N/A') ?></h4>
                    <p><strong>Lương Cơ Bản (Tháng):</strong> <?= SalaryCalculator::formatCurrency($fullBasicSalary) ?> <span style="color: #888;">(Theo hợp đồng)</span></p>
                    <p><strong>Số ngày làm việc:</strong> <?= $attendanceDays ?>/25</p>
                    <p><strong>Lương Thực Tế (Chấm công):</strong> <?= SalaryCalculator::formatCurrency($basicSalary) ?></p>
                    <p class="deduction"><strong>BHXH (<?= $settings['bhxh_rate'] ?? 8 ?>%):</strong> -<?= SalaryCalculator::formatCurrency($salaryDetails['bhxh']) ?></p>
                    <p class="deduction"><strong>BHYT (<?= $settings['bhyt_rate'] ?? 1.5 ?>%):</strong> -<?= SalaryCalculator::formatCurrency($salaryDetails['bhyt']) ?></p>
                    <p class="deduction"><strong>BHTN (<?= $settings['bhtn_rate'] ?? 1 ?>%):</strong> -<?= SalaryCalculator::formatCurrency($salaryDetails['bhtn']) ?></p>
                    <p class="deduction"><strong>Thuế TNCN:</strong> -<?= SalaryCalculator::formatCurrency($salaryDetails['income_tax']) ?></p>
                    <p class="bonus"><strong>Thưởng:</strong> +<?= SalaryCalculator::formatCurrency($bonuses) ?></p>
                    <p class="deduction"><strong>Phạt (Vi phạm + Nghỉ không giải trình):</strong> -<?= SalaryCalculator::formatCurrency($deductions - $salaryAdvance) ?></p>
                    <p class="deduction"><strong>Lương Ứng:</strong> -<?= SalaryCalculator::formatCurrency($salaryAdvance) ?></p>
                    <p class="bonus"><strong>Lương Làm Thêm:</strong> +<?= SalaryCalculator::formatCurrency($totalOvertimePay) ?></p>
                    <p><strong>Lương Thực Nhận:</strong> 
                        <span class="total-salary"><?= SalaryCalculator::formatCurrency($salaryDetails['net_salary']) ?></span>
                    </p>
                    <?php if ($warningMessage): ?>
                        <p class="alert alert-warning"><?= $warningMessage ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../layouts/footer.php'; ?>