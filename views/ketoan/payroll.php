<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . "/../../core/salary-calculate.php";

require_once __DIR__ . '/../../views/layouts/sidebar_kt.php';
require_once __DIR__ . '/../../views/layouts/header_kt.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

$db = Database::getInstance()->getConnection();

// Kiểm tra phiên làm việc và quyền truy cập
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id'])) {
    echo "<script>alert('Vui lòng đăng nhập để truy cập!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

if (!in_array($_SESSION['role_id'], [1, 2, 4])) {
    echo "<script>alert('Bạn không có quyền truy cập trang này (yêu cầu role_id = 1, 2, hoặc 4)!'); window.location.href='/HRMpv/views/auth/login.php';</script>";
    exit();
}

// Lấy thông số từ bảng settings
$settingsStmt = $db->query("SELECT name, value FROM settings");
$settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Xử lý lọc theo tháng
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$year = substr($filter_month, 0, 4);
$month = substr($filter_month, 5, 2);

// Lấy danh sách nhân viên
$stmt = $db->query("SELECT e.*, p.name as position_name, d.name as department_name
                    FROM employees e
                    LEFT JOIN positions p ON e.position_id = p.id
                    LEFT JOIN departments d ON e.department_id = d.id
                    ORDER BY e.full_name");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tính lương cho tất cả nhân viên
$payroll_data = [];
$deductionPerViolation = 50000; // Mức phạt vi phạm
$penaltyPerUnexplainedDay = 100000; // Mức phạt nghỉ không giải trình

foreach ($employees as $employee) {
    $employee_id = $employee['id'];

    // Lấy lương cơ bản từ hợp đồng mới nhất còn hiệu lực
    $month_start = "$filter_month-01";
    $month_end = date('Y-m-t', strtotime($month_start)); // Ngày cuối tháng
    $stmt = $db->prepare("
        SELECT basic_salary 
        FROM contracts 
        WHERE employee_id = ? 
        AND start_date <= ? 
        AND (end_date IS NULL OR end_date >= ?) 
        ORDER BY start_date DESC 
        LIMIT 1
    ");
    $stmt->execute([$employee_id, $month_end, $month_start]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract || !$contract['basic_salary']) {
        $fullBasicSalary = 0; // Nếu không có hợp đồng, mặc định là 0
    } else {
        $fullBasicSalary = $contract['basic_salary']; // Lương cơ bản từ hợp đồng
    }

    // Tính số ngày đi làm (chỉ cần có check_in là tính)
    $stmt = $db->prepare("SELECT COUNT(*) as attendance_days 
                          FROM attendance 
                          WHERE employee_id = ? 
                          AND DATE_FORMAT(check_in, '%Y-%m') = ? 
                          AND check_in IS NOT NULL");
    $stmt->execute([$employee_id, $filter_month]);
    $attendanceDays = $stmt->fetch(PDO::FETCH_ASSOC)['attendance_days'];

    // Tính tổng giờ làm việc
    $stmt = $db->prepare("SELECT check_in, check_out 
                          FROM attendance 
                          WHERE employee_id = ? 
                          AND DATE_FORMAT(check_in, '%Y-%m') = ? 
                          AND check_in IS NOT NULL 
                          AND check_out IS NOT NULL");
    $stmt->execute([$employee_id, $filter_month]);
    $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalHours = 0;
    foreach ($attendanceRecords as $record) {
        $check_in = new DateTime($record['check_in']);
        $check_out = new DateTime($record['check_out']);
        $interval = $check_in->diff($check_out);
        $hours_worked = $interval->h + ($interval->i / 60);
        $totalHours += $hours_worked;
    }

    // Tính số ngày nghỉ không giải trình
    $stmt = $db->prepare("SELECT COUNT(*) as absent_days
                          FROM attendance 
                          WHERE employee_id = ? 
                          AND DATE_FORMAT(date, '%Y-%m') = ? 
                          AND status = 'absent'
                          AND date NOT IN (
                              SELECT DATE(start_date) 
                              FROM leave_requests 
                              WHERE employee_id = ? 
                              AND status = 'Đã duyệt' 
                              AND DATE_FORMAT(start_date, '%Y-%m') = ?
                          )");
    $stmt->execute([$employee_id, $filter_month, $employee_id, $filter_month]);
    $absentDaysWithoutExplanation = $stmt->fetch(PDO::FETCH_ASSOC)['absent_days'];

    // Tính giờ làm thêm
    $stmt = $db->prepare("SELECT overtime_date, TIMESTAMPDIFF(HOUR, start_time, end_time) as hours 
                          FROM overtime 
                          WHERE employee_id = ? 
                          AND DATE_FORMAT(overtime_date, '%Y-%m') = ? 
                          AND status = 'approved'");
    $stmt->execute([$employee_id, $filter_month]);
    $overtimeRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalOvertimePay = 0;
    $hourlyRate = $fullBasicSalary / 160; // Giả định 160 giờ/tháng
    $overtimeDetails = [];
    foreach ($overtimeRecords as $record) {
        $date = $record['overtime_date'];
        $hours = $record['hours'];
        $isWeekend = (date('N', strtotime($date)) >= 6); // Thứ 7, CN
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
    $stmt = $db->prepare("SELECT filter_date, status, explanation, deduction_status 
                          FROM accounting_attendance 
                          WHERE employee_id = ? 
                          AND DATE_FORMAT(filter_date, '%Y-%m') = ?");
    $stmt->execute([$employee['employee_code'], $filter_month]);
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
    $stmt = $db->prepare("SELECT type, SUM(amount) as total_amount 
                          FROM rewards 
                          WHERE employee_id = ? 
                          AND DATE_FORMAT(date, '%Y-%m') = ?
                          GROUP BY type");
    $stmt->execute([$employee_id, $filter_month]);
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

    // Lấy chi tiết thưởng/phạt
    $stmt = $db->prepare("SELECT type, amount, reason, date 
                          FROM rewards 
                          WHERE employee_id = ? 
                          AND DATE_FORMAT(date, '%Y-%m') = ?");
    $stmt->execute([$employee_id, $filter_month]);
    $rewardDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deductions += $penalties;

    // Tính lương ứng
    $stmt = $db->prepare("SELECT SUM(amount) as total_advance 
                          FROM salary_advances 
                          WHERE employee_id = ? 
                          AND DATE_FORMAT(request_date, '%Y-%m') = ? 
                          AND status = 'Đã duyệt'");
    $stmt->execute([$employee_id, $filter_month]);
    $salaryAdvance = $stmt->fetch(PDO::FETCH_ASSOC)['total_advance'] ?? 0;

    // Lấy chi tiết tạm ứng
    $stmt = $db->prepare("SELECT amount, reason, request_date, approved_date 
                          FROM salary_advances 
                          WHERE employee_id = ? 
                          AND DATE_FORMAT(request_date, '%Y-%m') = ? 
                          AND status = 'Đã duyệt'");
    $stmt->execute([$employee_id, $filter_month]);
    $salaryAdvanceDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    // Lưu vào bảng payroll nếu chưa tồn tại
    $stmt = $db->prepare("SELECT COUNT(*) FROM payroll WHERE employee_id = ? AND month = ?");
    $stmt->execute([$employee_id, $filter_month]);
    if ($stmt->fetchColumn() == 0) {
        $stmt = $db->prepare("INSERT INTO payroll (employee_id, month, basic_salary, allowance, bonus, penalty, net_salary) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $employee_id,
            $filter_month,
            $basicSalary,
            $salaryDetails['allowance'],
            $bonuses + $totalOvertimePay,
            $deductions,
            $salaryDetails['net_salary']
        ]);
    }

    // Lưu dữ liệu vào mảng
    $payroll_data[] = [
        'employee_id' => $employee_id,
        'employee_name' => $employee['full_name'],
        'total_hours' => $totalHours,
        'basic_salary' => $salaryDetails['basic_salary'],
        'bonuses' => $bonuses,
        'overtime_pay' => $totalOvertimePay,
        'deductions' => $deductions,
        'salary_advance' => $salaryAdvance,
        'salary_advance_details' => $salaryAdvanceDetails,
        'income_tax' => $salaryDetails['income_tax'],
        'net_salary' => $salaryDetails['net_salary'],
        'attendance_days' => $attendanceDays,
        'absent_days_without_explanation' => $absentDaysWithoutExplanation,
        'overtime_details' => $overtimeDetails,
        'violation_details' => $violationDetails,
        'reward_details' => $rewardDetails,
        'unexplained_absence_penalty' => $unexplainedAbsencePenalty,
        'penalties' => $penalties,
        'salary_details' => $salaryDetails,
        'full_basic_salary' => $fullBasicSalary // Thêm lương cơ bản đầy đủ từ hợp đồng
    ];
}
?>

<link rel="stylesheet" href="/HRMpv/public/css/bangluong.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

<div class="container">
    <div class="salary-card">
        <h2 class="mb-4">Bảng Lương Nhân Viên</h2>

        <!-- Form lọc theo tháng và tìm kiếm -->
        <form method="GET" class="row mb-4">
            <div class="col-md-4">
                <label for="month" class="form-label">Chọn tháng:</label>
                <input type="month" name="month" id="month" class="form-control" value="<?= htmlspecialchars($filter_month) ?>">
            </div>
            <div class="col-md-4">
                <label for="search" class="form-label">Tìm kiếm:</label>
                <input type="text" id="search" class="form-control" placeholder="Nhập mã NV hoặc tên nhân viên">
            </div>
            <div class="col-md-2 align-self-end">
                <button type="submit" class="btn btn-primary">Lọc</button>
            </div>
        </form>

        <!-- Bảng hiển thị lương -->
        <div class="table-responsive">
            <table class="table table-striped" id="payrollTable">
                <thead>
                    <tr>
                        <th data-sort="employee_id"><span class="sort-icon">Mã NV</span></th>
                        <th data-sort="employee_name"><span class="sort-icon">Tên Nhân Viên</span></th>
                        <th data-sort="total_hours"><span class="sort-icon">Tổng Giờ Làm</span></th>
                        <th data-sort="basic_salary"><span class="sort-icon">Lương Cơ Bản</span></th>
                        <th data-sort="bonuses"><span class="sort-icon">Tiền Thưởng</span></th>
                        <th data-sort="overtime_pay"><span class="sort-icon">Tiền Làm Thêm</span></th>
                        <th data-sort="deductions"><span class="sort-icon">Tiền Phạt</span></th>
                        <th data-sort="salary_advance"><span class="sort-icon">Lương Ứng</span></th>
                        <th data-sort="income_tax"><span class="sort-icon">Thuế TNCN</span></th>
                        <th data-sort="net_salary"><span class="sort-icon">Lương Thực Nhận</span></th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody id="payrollTableBody">
                    <?php if (count($payroll_data) > 0): ?>
                        <?php foreach ($payroll_data as $data): ?>
                            <tr>
                                <td><?= htmlspecialchars($data['employee_id']) ?></td>
                                <td><?= htmlspecialchars($data['employee_name']) ?></td>
                                <td><?= number_format($data['total_hours'], 2) ?> giờ</td>
                                <td><?= SalaryCalculator::formatCurrency($data['basic_salary']) ?></td>
                                <td class="bonus">+ <?= SalaryCalculator::formatCurrency($data['bonuses']) ?></td>
                                <td class="bonus">+ <?= SalaryCalculator::formatCurrency($data['overtime_pay']) ?></td>
                                <td class="deduction">- <?= SalaryCalculator::formatCurrency($data['deductions']) ?></td>
                                <td class="deduction">- <?= SalaryCalculator::formatCurrency($data['salary_advance']) ?></td>
                                <td class="deduction">- <?= SalaryCalculator::formatCurrency($data['income_tax']) ?></td>
                                <td><?= SalaryCalculator::formatCurrency($data['net_salary']) ?></td>
                                <td>
                                    <button type="button" class="btn btn-info btn-sm" onclick='showSalaryDetails(<?= json_encode($data) ?>)'>
                                        Xem Chi Tiết
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="text-center">Không có dữ liệu chấm công cho tháng này.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal hiển thị chi tiết lương -->
<div class="modal fade" id="salaryDetailsModal" tabindex="-1" aria-labelledby="salaryDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="salaryDetailsModalLabel">Chi Tiết Lương</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="employee-info mb-4">
                    <h3>Thông tin nhân viên: <span id="modal_employee_name"></span></h3>
                </div>

                <div class="attendance-info">
                    <h4>Số công trong tháng: <span id="modal_attendance_days"></span>/25</h4>
                    <p id="modal_attendance_warning" class="text-warning"></p>
                    <p id="modal_absence_penalty" class="text-danger"></p>
                </div>

                <div id="modal_overtime_details" class="overtime-details" style="display: none;">
                    <h4>Chi tiết làm thêm</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Ngày</th>
                                <th>Số giờ</th>
                                <th>Loại</th>
                                <th>Tiền làm thêm</th>
                            </tr>
                        </thead>
                        <tbody id="modal_overtime_table"></tbody>
                    </table>
                </div>

                <div id="modal_violation_details" class="violation-details" style="display: none;">
                    <h4>Chi tiết vi phạm</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Ngày</th>
                                <th>Trạng thái vi phạm</th>
                                <th>Giải trình</th>
                                <th>Trạng thái</th>
                                <th>Tiền phạt</th>
                            </tr>
                        </thead>
                        <tbody id="modal_violation_table"></tbody>
                    </table>
                </div>

                <div id="modal_reward_details" class="reward-details" style="display: none;">
                    <h4>Chi tiết Thưởng/Phạt</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Ngày</th>
                                <th>Loại</th>
                                <th>Lý do</th>
                                <th>Số tiền</th>
                            </tr>
                        </thead>
                        <tbody id="modal_reward_table"></tbody>
                    </table>
                </div>

                <div id="modal_salary_advance_details" class="salary-advance-details" style="display: none;">
                    <h4>Chi tiết Tạm Ứng Lương</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Ngày Yêu Cầu</th>
                                <th>Lý Do</th>
                                <th>Số Tiền</th>
                                <th>Ngày Phê Duyệt</th>
                            </tr>
                        </thead>
                        <tbody id="modal_salary_advance_table"></tbody>
                    </table>
                </div>

                <div class="salary-info" id="modal_salary_info"></div>

                <div class="net-salary">
                    <h3>Lương Thực Nhận</h3>
                    <div class="amount" id="modal_net_salary"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function formatCurrency(amount) {
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(amount);
}

// Dữ liệu gốc của bảng
const originalData = <?php echo json_encode($payroll_data); ?>;

// Hàm hiển thị dữ liệu trong bảng
function renderTable(data) {
    const tableBody = document.getElementById('payrollTableBody');
    tableBody.innerHTML = '';

    if (data.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="11" class="text-center">Không có dữ liệu chấm công cho tháng này.</td></tr>';
        return;
    }

    data.forEach(item => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${item.employee_id}</td>
            <td>${item.employee_name}</td>
            <td>${Number(item.total_hours).toFixed(2)} giờ</td>
            <td>${formatCurrency(item.basic_salary)}</td>
            <td class="bonus">+ ${formatCurrency(item.bonuses)}</td>
            <td class="bonus">+ ${formatCurrency(item.overtime_pay)}</td>
            <td class="deduction">- ${formatCurrency(item.deductions)}</td>
            <td class="deduction">- ${formatCurrency(item.salary_advance)}</td>
            <td class="deduction">- ${formatCurrency(item.income_tax)}</td>
            <td>${formatCurrency(item.net_salary)}</td>
            <td>
                <button type="button" class="btn btn-info btn-sm" onclick='showSalaryDetails(${JSON.stringify(item)})'>
                    Xem Chi Tiết
                </button>
            </td>
        `;
        tableBody.appendChild(row);
    });
}

// Tìm kiếm
const searchInput = document.getElementById('search');
searchInput.addEventListener('input', function() {
    const searchTerm = this.value.trim().toLowerCase();
    const filteredData = originalData.filter(item => 
        item.employee_id.toString().toLowerCase().includes(searchTerm) ||
        item.employee_name.toLowerCase().includes(searchTerm)
    );
    renderTable(filteredData);
});

// Sắp xếp
let sortDirection = {};
document.querySelectorAll('#payrollTable th[data-sort]').forEach(header => {
    header.addEventListener('click', () => {
        const sortKey = header.getAttribute('data-sort');
        const isAsc = sortDirection[sortKey] === 'asc';
        sortDirection[sortKey] = isAsc ? 'desc' : 'asc';

        // Xóa trạng thái sắp xếp của các cột khác
        document.querySelectorAll('#payrollTable th').forEach(th => {
            th.classList.remove('asc', 'desc');
        });
        header.classList.add(sortDirection[sortKey]);

        // Sắp xếp dữ liệu
        const sortedData = [...originalData].sort((a, b) => {
            let valueA = a[sortKey];
            let valueB = b[sortKey];

            // Chuyển đổi giá trị số nếu cần
            if (sortKey !== 'employee_name') {
                valueA = parseFloat(valueA);
                valueB = parseFloat(valueB);
            } else {
                valueA = valueA.toLowerCase();
                valueB = valueB.toLowerCase();
            }

            if (sortDirection[sortKey] === 'asc') {
                return valueA > valueB ? 1 : -1;
            } else {
                return valueA < valueB ? 1 : -1;
            }
        });

        renderTable(sortedData);
    });
});

// Hiển thị chi tiết lương
function showSalaryDetails(data) {
    // Điền thông tin nhân viên
    document.getElementById('modal_employee_name').textContent = data.employee_name;

    // Điền số công
    const attendanceDays = data.attendance_days;
    document.getElementById('modal_attendance_days').textContent = attendanceDays;
    if (attendanceDays < 25) {
        document.getElementById('modal_attendance_warning').textContent = `Chỉ nhận ${Math.round(attendanceDays / 25 * 100, 2)}% lương cơ bản do chưa đủ 25 công.`;
    } else {
        document.getElementById('modal_attendance_warning').textContent = '';
    }

    // Điền phạt nghỉ không giải trình
    if (data.unexplained_absence_penalty > 0) {
        document.getElementById('modal_absence_penalty').textContent = `Có ${data.absent_days_without_explanation} ngày nghỉ không giải trình, bị trừ ${formatCurrency(data.unexplained_absence_penalty)}.`;
    } else {
        document.getElementById('modal_absence_penalty').textContent = '';
    }

    // Điền chi tiết làm thêm
    const overtimeTable = document.getElementById('modal_overtime_table');
    overtimeTable.innerHTML = '';
    if (data.overtime_details.length > 0) {
        document.getElementById('modal_overtime_details').style.display = 'block';
        data.overtime_details.forEach(detail => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${detail.date}</td>
                <td>${detail.hours}</td>
                <td>${detail.type}</td>
                <td class="bonus">${formatCurrency(detail.pay)}</td>
            `;
            overtimeTable.appendChild(row);
        });
        const totalRow = document.createElement('tr');
        totalRow.innerHTML = `
            <td colspan="3"><strong>Tổng</strong></td>
            <td class="bonus"><strong>${formatCurrency(data.overtime_pay)}</strong></td>
        `;
        overtimeTable.appendChild(totalRow);
    } else {
        document.getElementById('modal_overtime_details').style.display = 'none';
    }

    // Điền chi tiết vi phạm
    const violationTable = document.getElementById('modal_violation_table');
    violationTable.innerHTML = '';
    if (data.violation_details.length > 0) {
        document.getElementById('modal_violation_details').style.display = 'block';
        let totalDeduction = 0;
        data.violation_details.forEach(detail => {
            if (detail.deduction > 0) {
                totalDeduction += detail.deduction;
            }
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${detail.date}</td>
                <td>${detail.violation}</td>
                <td>${detail.explanation}</td>
                <td>${detail.status}</td>
                <td class="deduction">${formatCurrency(detail.deduction)}</td>
            `;
            violationTable.appendChild(row);
        });
        const totalRow = document.createElement('tr');
        totalRow.innerHTML = `
            <td colspan="4"><strong>Tổng</strong></td>
            <td class="deduction"><strong>${formatCurrency(totalDeduction)}</strong></td>
        `;
        violationTable.appendChild(totalRow);
    } else {
        document.getElementById('modal_violation_details').style.display = 'none';
    }

    // Điền chi tiết thưởng/phạt
    const rewardTable = document.getElementById('modal_reward_table');
    rewardTable.innerHTML = '';
    if (data.reward_details.length > 0) {
        document.getElementById('modal_reward_details').style.display = 'block';
        let totalBonuses = 0;
        let totalPenalties = 0;
        data.reward_details.forEach(detail => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${detail.date}</td>
                <td>${detail.type}</td>
                <td>${detail.reason}</td>
                <td class="${detail.type === 'Thưởng' ? 'bonus' : 'deduction'}">${formatCurrency(detail.amount)}</td>
            `;
            rewardTable.appendChild(row);
            if (detail.type === 'Thưởng') {
                totalBonuses += detail.amount;
            } else if (detail.type === 'Phạt') {
                totalPenalties += detail.amount;
            }
        });
        const bonusRow = document.createElement('tr');
        bonusRow.innerHTML = `
            <td colspan="3"><strong>Tổng Thưởng</strong></td>
            <td class="bonus"><strong>${formatCurrency(totalBonuses)}</strong></td>
        `;
        rewardTable.appendChild(bonusRow);
        const penaltyRow = document.createElement('tr');
        penaltyRow.innerHTML = `
            <td colspan="3"><strong>Tổng Phạt</strong></td>
            <td class="deduction"><strong>${formatCurrency(totalPenalties)}</strong></td>
        `;
        rewardTable.appendChild(penaltyRow);
    } else {
        document.getElementById('modal_reward_details').style.display = 'none';
    }

    // Điền chi tiết tạm ứng lương
    const salaryAdvanceTable = document.getElementById('modal_salary_advance_table');
    salaryAdvanceTable.innerHTML = '';
    if (data.salary_advance_details.length > 0) {
        document.getElementById('modal_salary_advance_details').style.display = 'block';
        let totalAdvance = 0;
        data.salary_advance_details.forEach(detail => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${detail.request_date}</td>
                <td>${detail.reason}</td>
                <td class="deduction">${formatCurrency(detail.amount)}</td>
                <td>${detail.approved_date}</td>
            `;
            salaryAdvanceTable.appendChild(row);
            totalAdvance += detail.amount;
        });
        const totalRow = document.createElement('tr');
        totalRow.innerHTML = `
            <td colspan="3"><strong>Tổng Tạm Ứng</strong></td>
            <td class="deduction"><strong>${formatCurrency(totalAdvance)}</strong></td>
        `;
        salaryAdvanceTable.appendChild(totalRow);
    } else {
        document.getElementById('modal_salary_advance_details').style.display = 'none';
    }

    // Điền chi tiết lương
    const salaryInfo = document.getElementById('modal_salary_info');
    salaryInfo.innerHTML = `
        <div class="info-item">
            <h4>Lương Cơ Bản</h4>
            <div class="amount">${formatCurrency(data.salary_details.basic_salary)}
                ${attendanceDays < 25 ? `<span class="text-muted">(Theo hợp đồng: ${formatCurrency(data.full_basic_salary)})</span>` : ''}
            </div>
        </div>
        <div class="info-item">
            <h4>Phụ Cấp</h4>
            <div class="amount">${formatCurrency(data.salary_details.allowance)}</div>
        </div>
        <div class="info-item">
            <h4>Tiền Thưởng</h4>
            <div class="amount bonus">+ ${formatCurrency(data.bonuses)}</div>
        </div>
        <div class="info-item">
            <h4>Tiền Làm Thêm</h4>
            <div class="amount bonus">+ ${formatCurrency(data.overtime_pay)}</div>
        </div>
        <div class="info-item">
            <h4>Tiền Phạt</h4>
            <div class="amount deduction">- ${formatCurrency(data.penalties + data.unexplained_absence_penalty + (data.violation_details.filter(v => v.deduction > 0).length * <?php echo $deductionPerViolation; ?>))}
                <span class="text-muted">(Vi phạm: ${data.violation_details.filter(v => v.deduction > 0).length}, Nghỉ không giải trình: ${data.absent_days_without_explanation} ngày, Phạt khác: ${formatCurrency(data.penalties)})</span>
            </div>
        </div>
        <div class="info-item">
            <h4>Lương Ứng</h4>
            <div class="amount deduction">- ${formatCurrency(data.salary_advance)}</div>
        </div>
        <div class="info-item">
            <h4>BHXH (${<?php echo json_encode($settings['bhxh_rate'] ?? 8); ?>}%)</h4>
            <div class="amount deduction">- ${formatCurrency(data.salary_details.bhxh)}</div>
        </div>
        <div class="info-item">
            <h4>BHYT (${<?php echo json_encode($settings['bhyt_rate'] ?? 1.5); ?>}%)</h4>
            <div class="amount deduction">- ${formatCurrency(data.salary_details.bhyt)}</div>
        </div>
        <div class="info-item">
            <h4>BHTN (${<?php echo json_encode($settings['bhtn_rate'] ?? 1); ?>}%)</h4>
            <div class="amount deduction">- ${formatCurrency(data.salary_details.bhtn)}</div>
        </div>
        <div class="info-item">
            <h4>Tổng Bảo Hiểm</h4>
            <div class="amount deduction">- ${formatCurrency(data.salary_details.total_insurance)}</div>
        </div>
        <div class="info-item">
            <h4>Giảm Trừ Gia Cảnh</h4>
            <div class="amount">${formatCurrency(data.salary_details.personal_deduction)}</div>
        </div>
        <div class="info-item">
            <h4>Thu Nhập Chịu Thuế</h4>
            <div class="amount">${formatCurrency(data.salary_details.taxable_income)}</div>
        </div>
        <div class="info-item">
            <h4>Thuế TNCN</h4>
            <div class="amount deduction">- ${formatCurrency(data.salary_details.income_tax)}</div>
        </div>
    `;

    // Điền lương thực nhận
    document.getElementById('modal_net_salary').textContent = formatCurrency(data.net_salary);

    // Hiển thị modal
    const modal = new bootstrap.Modal(document.getElementById('salaryDetailsModal'));
    modal.show();
}
</script>