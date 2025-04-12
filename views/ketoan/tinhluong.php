<?php
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . "/../../core/salary-calculate.php";

require_once __DIR__ . '/../../views/layouts/sidebar_kt.php';
require_once __DIR__ . '/../../views/layouts/header_kt.php';

$db = Database::getInstance()->getConnection();

// Lấy thông số từ bảng settings
$settingsStmt = $db->query("SELECT name, value FROM settings");
$settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Lấy danh sách nhân viên
$stmt = $db->query("SELECT e.*, p.name as position_name, d.name as department_name
                    FROM employees e
                    LEFT JOIN positions p ON e.position_id = p.id
                    LEFT JOIN departments d ON e.department_id = d.id
                    ORDER BY e.full_name");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedEmployee = null;
$salaryDetails = null;
$attendanceDays = 0;
$overtimeDetails = []; // Chi tiết làm thêm theo ngày
$violationDetails = []; // Chi tiết vi phạm từ accounting_attendance
$deductions = 0;       // Tổng tiền phạt
$bonuses = 0;          // Tổng tiền thưởng
$unexplainedAbsencePenalty = 0; // Phạt nghỉ không giải trình
$salaryAdvance = 0;    // Tổng lương ứng
$warningMessage = '';  // Cảnh báo nếu có vấn đề
$rewardDetails = [];   // Chi tiết thưởng/phạt từ bảng rewards

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = $_POST['employee_id'];
    $month = $_POST['month'] ?? date('Y-m');
    $deductionPerViolation = $_POST['deduction_per_violation'] ?? 50000; // Mức phạt vi phạm
    $penaltyPerUnexplainedDay = $_POST['penalty_per_unexplained_day'] ?? 100000; // Mức phạt nghỉ không giải trình

    // Lấy thông tin nhân viên được chọn
    $stmt = $db->prepare("
        SELECT e.*, p.name AS position_name, d.name AS department_name 
        FROM employees e 
        LEFT JOIN positions p ON e.position_id = p.id 
        LEFT JOIN departments d ON e.department_id = d.id 
        WHERE e.id = ?
    ");
    $stmt->execute([$employee_id]);
    $selectedEmployee = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedEmployee) {
        // Lấy lương cơ bản từ hợp đồng mới nhất còn hiệu lực
        $month_start = "$month-01";
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
            $warningMessage = "Không tìm thấy hợp đồng hoặc lương cơ bản trong hợp đồng cho nhân viên này!";
        } else {
            $fullBasicSalary = $contract['basic_salary']; // Lương cơ bản từ hợp đồng
        }

        // Tính số ngày đi làm
        $stmt = $db->prepare("
            SELECT COUNT(*) as attendance_days 
            FROM attendance 
            WHERE employee_id = ? 
            AND DATE_FORMAT(check_in, '%Y-%m') = ? 
            AND check_in IS NOT NULL
        ");
        $stmt->execute([$employee_id, $month]);
        $attendanceDays = $stmt->fetch(PDO::FETCH_ASSOC)['attendance_days'];

        // Tính số ngày nghỉ không giải trình
        $stmt = $db->prepare("
            SELECT COUNT(*) as absent_days
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
            )
        ");
        $stmt->execute([$employee_id, $month, $employee_id, $month]);
        $absentDaysWithoutExplanation = $stmt->fetch(PDO::FETCH_ASSOC)['absent_days'];

        // Tính giờ làm thêm
        $stmt = $db->prepare("
            SELECT overtime_date, TIMESTAMPDIFF(HOUR, start_time, end_time) as hours 
            FROM overtime 
            WHERE employee_id = ? 
            AND DATE_FORMAT(overtime_date, '%Y-%m') = ? 
            AND status = 'approved'
        ");
        $stmt->execute([$employee_id, $month]);
        $overtimeRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalOvertimePay = 0;
        $hourlyRate = $fullBasicSalary / 160; // Giả định 160 giờ/tháng
        foreach ($overtimeRecords as $record) {
            $date = $record['overtime_date'];
            $hours = $record['hours'];
            $isWeekend = (date('N', strtotime($date)) >= 6); // Thứ 7, CN
            $isHoliday = false; // Có thể thêm bảng holidays để kiểm tra

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
            WHERE employee_id = ? 
            AND DATE_FORMAT(filter_date, '%Y-%m') = ?
        ");
        $stmt->execute([$selectedEmployee['employee_code'], $month]);
        $violationRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $violationCount = 0;
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
            WHERE employee_id = ? 
            AND DATE_FORMAT(date, '%Y-%m') = ?
            GROUP BY type
        ");
        $stmt->execute([$employee_id, $month]);
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
        $stmt = $db->prepare("
            SELECT type, amount, reason, date 
            FROM rewards 
            WHERE employee_id = ? 
            AND DATE_FORMAT(date, '%Y-%m') = ?
        ");
        $stmt->execute([$employee_id, $month]);
        $rewardDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cập nhật tổng deductions
        $deductions += $penalties;

        // Tính lương ứng
        $stmt = $db->prepare("
            SELECT SUM(amount) as total_advance 
            FROM salary_advances 
            WHERE employee_id = ? 
            AND DATE_FORMAT(request_date, '%Y-%m') = ? 
            AND status = 'Đã duyệt'
        ");
        $stmt->execute([$employee_id, $month]);
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

        // Kiểm tra cảnh báo
        if ($salaryDetails['net_salary'] < 0) {
            $warningMessage = "Cảnh báo: Lương thực nhận âm do tổng tiền phạt và lương ứng vượt quá lương!";
        }

        // Lưu vào bảng payroll nếu chưa tồn tại
        $stmt = $db->prepare("SELECT COUNT(*) FROM payroll WHERE employee_id = ? AND month = ?");
        $stmt->execute([$employee_id, $month]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $db->prepare("
                INSERT INTO payroll (employee_id, month, basic_salary, allowance, bonus, penalty, net_salary) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $employee_id,
                $month,
                $basicSalary,
                $salaryDetails['allowance'],
                $salaryDetails['bonuses'],
                $deductions,
                $salaryDetails['net_salary']
            ]);
        }
    }
}
?>

<link rel="stylesheet" href="/HRMPV/public/css/luong.css">

<div class="container">
    <div class="salary-card">
        <h2 class="mb-4">Tính Lương Nhân Viên</h2>
        <!-- Chatbot Container -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <!-- Chatbot Icon -->
        <div class="chatbot-icon" id="chatbot-icon">
            <i class="fas fa-robot fa-2x"></i>
        </div>

        <!-- Chatbot Container -->
        <div class="chatbot-container chatbot-hidden" id="chatbot-container">
            <div class="chatbot-header">
                <h3>Chatbot Kế Toán</h3>
                <button class="chatbot-toggle">Đóng</button>
            </div>
            <div class="chatbot-messages" id="chatbot-messages">
                <div class="message bot-message">Xin chào! Tôi là Chatbot Kế Toán. Bạn muốn hỏi gì về lương hoặc chấm công?</div>
            </div>
            <div class="chatbot-input">
                <input type="text" id="chatbot-input" placeholder="Nhập câu hỏi của bạn..." />
                <button id="chatbot-send">Gửi</button>
            </div>
        </div>

        <form method="POST" class="employee-select">
            <div class="row">
                <div class="col-md-3">
                    <select name="employee_id" class="form-control" required>
                        <option value="">Chọn nhân viên</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= $employee['id'] ?>" <?= isset($_POST['employee_id']) && $_POST['employee_id'] == $employee['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($employee['full_name']) ?> - <?= htmlspecialchars($employee['department_name']) ?> - <?= htmlspecialchars($employee['position_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="month" name="month" class="form-control" value="<?= date('Y-m') ?>" required>
                </div>
                <div class="col-md-2">
                    <input type="number" name="deduction_per_violation" class="form-control" value="50000" placeholder="Phạt vi phạm (VND)" min="0">
                </div>
                <div class="col-md-2">
                    <input type="number" name="penalty_per_unexplained_day" class="form-control" value="100000" placeholder="Phạt nghỉ không giải trình (VND)" min="0">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-block btn-calculate">Tính Lương</button>
                </div>
            </div>
        </form>

        <?php if ($selectedEmployee && $salaryDetails): ?>
            <div class="employee-info mb-4">
                <h3>Thông tin nhân viên: <?= htmlspecialchars($selectedEmployee['full_name']) ?></h3>
            </div>

            <div class="attendance-info">
                <h4>Số công trong tháng: <?= $attendanceDays ?>/25</h4>
                <?php if ($attendanceDays < 25): ?>
                    <p class="text-warning">Chỉ nhận <?= round($attendanceDays / 25 * 100, 2) ?>% lương cơ bản do chưa đủ 25 công.</p>
                <?php endif; ?>
                <?php if ($absentDaysWithoutExplanation > 0): ?>
                    <p class="text-danger">Có <?= $absentDaysWithoutExplanation ?> ngày nghỉ không giải trình, bị trừ <?= SalaryCalculator::formatCurrency($unexplainedAbsencePenalty) ?>.</p>
                <?php endif; ?>
                <?php if ($warningMessage): ?>
                    <p class="warning"><?= $warningMessage ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($overtimeDetails)): ?>
                <div class="overtime-details">
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
                        <tbody>
                            <?php foreach ($overtimeDetails as $detail): ?>
                                <tr>
                                    <td><?= $detail['date'] ?></td>
                                    <td><?= $detail['hours'] ?></td>
                                    <td><?= $detail['type'] ?></td>
                                    <td class="bonus"><?= SalaryCalculator::formatCurrency($detail['pay']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="3"><strong>Tổng</strong></td>
                                <td class="bonus"><strong><?= SalaryCalculator::formatCurrency($salaryDetails['overtime_pay']) ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($violationDetails)): ?>
                <div class="violation-details">
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
                        <tbody>
                            <?php foreach ($violationDetails as $detail): ?>
                                <tr>
                                    <td><?= $detail['date'] ?></td>
                                    <td><?= htmlspecialchars($detail['violation']) ?></td>
                                    <td><?= htmlspecialchars($detail['explanation']) ?></td>
                                    <td><?= $detail['status'] ?></td>
                                    <td class="deduction"><?= SalaryCalculator::formatCurrency($detail['deduction']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="4"><strong>Tổng</strong></td>
                                <td class="deduction"><strong><?= SalaryCalculator::formatCurrency($violationCount * $deductionPerViolation) ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="salary-info">
                <div class="info-item">
                    <h4>Lương Cơ Bản</h4>
                    <div class="amount"><?= SalaryCalculator::formatCurrency($salaryDetails['basic_salary']) ?>
                        <?php if ($attendanceDays < 25): ?>
                            <span class="text-muted">(Theo hợp đồng: <?= SalaryCalculator::formatCurrency($fullBasicSalary) ?>)</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-item">
                    <h4>Phụ Cấp</h4>
                    <div class="amount"><?= SalaryCalculator::formatCurrency($salaryDetails['allowance']) ?></div>
                </div>
                <div class="info-item">
                    <h4>Tiền Thưởng</h4>
                    <div class="amount bonus">+ <?= SalaryCalculator::formatCurrency($salaryDetails['bonuses']) ?></div>
                </div>
                <div class="info-item">
                    <h4>Tiền Làm Thêm</h4>
                    <div class="amount bonus">+ <?= SalaryCalculator::formatCurrency($salaryDetails['overtime_pay']) ?></div>
                </div>
                <div class="info-item">
                    <h4>Tiền Phạt</h4>
                    <div class="amount deduction">- <?= SalaryCalculator::formatCurrency($salaryDetails['penalties'] + $violationCount * $deductionPerViolation + $unexplainedAbsencePenalty) ?>
                        <?php if ($salaryDetails['penalties'] + $violationCount * $deductionPerViolation + $unexplainedAbsencePenalty > 0): ?>
                            <span class="text-muted">(Vi phạm: <?= $violationCount ?>, Nghỉ không giải trình: <?= $absentDaysWithoutExplanation ?> ngày, Phạt khác: <?= SalaryCalculator::formatCurrency($salaryDetails['penalties']) ?>)</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-item">
                    <h4>Lương Ứng</h4>
                    <div class="amount deduction">- <?= SalaryCalculator::formatCurrency($salaryDetails['salary_advance']) ?></div>
                </div>
                <div class="info-item">
                    <h4>BHXH (<?= $settings['bhxh_rate'] ?? 8 ?>%)</h4>
                    <div class="amount deduction">- <?= SalaryCalculator::formatCurrency($salaryDetails['bhxh']) ?></div>
                </div>
                <div class="info-item">
                    <h4>BHYT (<?= $settings['bhyt_rate'] ?? 1.5 ?>%)</h4>
                    <div class="amount deduction">- <?= SalaryCalculator::formatCurrency($salaryDetails['bhyt']) ?></div>
                </div>
                <div class="info-item">
                    <h4>BHTN (<?= $settings['bhtn_rate'] ?? 1 ?>%)</h4>
                    <div class="amount deduction">- <?= SalaryCalculator::formatCurrency($salaryDetails['bhtn']) ?></div>
                </div>
                <div class="info-item">
                    <h4>Tổng Bảo Hiểm</h4>
                    <div class="amount deduction">- <?= SalaryCalculator::formatCurrency($salaryDetails['total_insurance']) ?></div>
                </div>
                <div class="info-item">
                    <h4>Giảm Trừ Gia Cảnh</h4>
                    <div class="amount"><?= SalaryCalculator::formatCurrency($salaryDetails['personal_deduction']) ?></div>
                </div>
                <div class="info-item">
                    <h4>Thu Nhập Chịu Thuế</h4>
                    <div class="amount"><?= SalaryCalculator::formatCurrency($salaryDetails['taxable_income']) ?></div>
                </div>
                <div class="info-item">
                    <h4>Thuế TNCN</h4>
                    <div class="amount deduction">- <?= SalaryCalculator::formatCurrency($salaryDetails['income_tax']) ?></div>
                </div>
            </div>

            <div class="net-salary">
                <h3>Lương Thực Nhận</h3>
                <div class="amount"><?= SalaryCalculator::formatCurrency($salaryDetails['net_salary']) ?></div>
            </div>

            <div class="tax-brackets">
                <h4>Biểu Thuế Thu Nhập Cá Nhân</h4>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Mức chịu thuế/tháng</th>
                            <th>Thuế suất</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Đến 5 triệu đồng</td>
                            <td><?= $settings['tax_rate_1'] ?? 5 ?>%</td>
                        </tr>
                        <tr>
                            <td>Trên 5 đến 10 triệu đồng</td>
                            <td><?= $settings['tax_rate_2'] ?? 10 ?>%</td>
                        </tr>
                        <tr>
                            <td>Trên 10 đến 18 triệu đồng</td>
                            <td><?= $settings['tax_rate_3'] ?? 15 ?>%</td>
                        </tr>
                        <tr>
                            <td>Trên 18 đến 32 triệu đồng</td>
                            <td><?= $settings['tax_rate_4'] ?? 20 ?>%</td>
                        </tr>
                        <tr>
                            <td>Trên 32 đến 52 triệu đồng</td>
                            <td><?= $settings['tax_rate_5'] ?? 25 ?>%</td>
                        </tr>
                        <tr>
                            <td>Trên 52 đến 80 triệu đồng</td>
                            <td><?= $settings['tax_rate_6'] ?? 30 ?>%</td>
                        </tr>
                        <tr>
                            <td>Trên 80 triệu đồng</td>
                            <td><?= $settings['tax_rate_7'] ?? 35 ?>%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Dữ liệu lương từ PHP để chatbot sử dụng
const salaryDetails = <?php echo $salaryDetails ? json_encode($salaryDetails) : 'null'; ?>;
const selectedEmployee = <?php echo $selectedEmployee ? json_encode($selectedEmployee) : 'null'; ?>;
const attendanceDays = <?php echo json_encode($attendanceDays); ?>;
const overtimeDetails = <?php echo json_encode($overtimeDetails); ?>;
const violationDetails = <?php echo json_encode($violationDetails); ?>;
const deductions = <?php echo json_encode($deductions); ?>;
const bonuses = <?php echo json_encode($bonuses); ?>;
const unexplainedAbsencePenalty = <?php echo json_encode($unexplainedAbsencePenalty); ?>;
const salaryAdvance = <?php echo json_encode($salaryAdvance); ?>;
const settings = <?php echo json_encode($settings); ?>;

// DOM Elements
const chatbotIcon = document.getElementById('chatbot-icon');
const chatbotContainer = document.getElementById('chatbot-container');
const chatbotMessages = document.getElementById('chatbot-messages');
const chatbotInput = document.getElementById('chatbot-input');
const chatbotSend = document.getElementById('chatbot-send');
const chatbotToggle = document.querySelector('.chatbot-toggle');

// Hàm hiển thị tin nhắn với thời gian
function addMessage(message, isUser = false) {
    const messageDiv = document.createElement('div');
    messageDiv.classList.add('message');
    messageDiv.classList.add(isUser ? 'user-message' : 'bot-message');

    const timeSpan = document.createElement('span');
    timeSpan.classList.add('message-time');
    const now = new Date();
    timeSpan.textContent = `${now.getHours()}:${now.getMinutes().toString().padStart(2, '0')}`;

    const messageContent = document.createElement('span');
    messageContent.classList.add('message-content');
    messageContent.textContent = message;

    messageDiv.appendChild(messageContent);
    messageDiv.appendChild(timeSpan);
    chatbotMessages.appendChild(messageDiv);
    chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
}

// Hàm hiển thị gợi ý câu hỏi
function showSuggestions() {
    const suggestions = [
        "Lương thực nhận của tôi là bao nhiêu?",
        "Tôi đã làm bao nhiêu ngày trong tháng?",
        "Tôi đã làm thêm bao nhiêu giờ?",
        "Tôi bị phạt bao nhiêu tiền?",
        "Tiền thưởng của tôi là bao nhiêu?",
        "Lương ứng của tôi là bao nhiêu?",
        "Thuế TNCN của tôi là bao nhiêu?",
        "Tôi thuộc phòng ban nào?"
    ];

    const suggestionDiv = document.createElement('div');
    suggestionDiv.classList.add('suggestions');
    suggestionDiv.innerHTML = '<strong>Gợi ý:</strong> ';
    suggestions.forEach(suggestion => {
        const button = document.createElement('button');
        button.classList.add('suggestion-btn');
        button.textContent = suggestion;
        button.addEventListener('click', () => {
            addMessage(suggestion, true);
            const response = processQuestion(suggestion);
            setTimeout(() => {
                addMessage(response);
                showSuggestions(); // Hiển thị lại gợi ý sau khi trả lời
            }, 500);
        });
        suggestionDiv.appendChild(button);
    });
    chatbotMessages.appendChild(suggestionDiv);
    chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
}

// Xử lý câu hỏi của người dùng
function processQuestion(question) {
    const lowerQuestion = question.toLowerCase();

    if (!selectedEmployee || !salaryDetails) {
        return "Vui lòng chọn nhân viên và tính lương trước khi hỏi! 😊";
    }

    // Lương thực nhận
    if (lowerQuestion.includes('lương thực nhận') || lowerQuestion.includes('net salary')) {
        return `Lương thực nhận của **${selectedEmployee.full_name}** là **${formatCurrency(salaryDetails.net_salary)}**. 💰`;
    }
    // Lương cơ bản
    else if (lowerQuestion.includes('lương cơ bản') || lowerQuestion.includes('basic salary')) {
        return `Lương cơ bản của **${selectedEmployee.full_name}** là **${formatCurrency(salaryDetails.basic_salary)}**.`;
    }
    // Số ngày làm
    else if (lowerQuestion.includes('số ngày làm') || lowerQuestion.includes('attendance')) {
        return `**${selectedEmployee.full_name}** đã làm **${attendanceDays} ngày** trong tháng. 📅`;
    }
    // Làm thêm
    else if (lowerQuestion.includes('làm thêm') || lowerQuestion.includes('overtime')) {
        if (overtimeDetails.length > 0) {
            let response = `Chi tiết làm thêm của **${selectedEmployee.full_name}**:\n`;
            overtimeDetails.forEach(detail => {
                response += `- Ngày ${detail.date}: ${detail.hours} giờ (${detail.type}), tiền: **${formatCurrency(detail.pay)}**\n`;
            });
            response += `Tổng tiền làm thêm: **${formatCurrency(salaryDetails.overtime_pay)}**. ⏰`;
            return response;
        } else {
            return `**${selectedEmployee.full_name}** không có giờ làm thêm trong tháng này.`;
        }
    }
    // Vi phạm
    else if (lowerQuestion.includes('vi phạm') || lowerQuestion.includes('deduction')) {
        if (violationDetails.length > 0) {
            let response = `Chi tiết vi phạm của **${selectedEmployee.full_name}**:\n`;
            violationDetails.forEach(detail => {
                if (detail.deduction > 0) {
                    response += `- Ngày ${detail.date}: ${detail.violation}, phạt **${formatCurrency(detail.deduction)}**\n`;
                }
            });
            response += `Tổng tiền phạt: **${formatCurrency(deductions)}**. ⚠️`;
            return response;
        } else {
            return `**${selectedEmployee.full_name}** không có vi phạm trong tháng này. 🎉`;
        }
    }
    // Thưởng
    else if (lowerQuestion.includes('thưởng') || lowerQuestion.includes('bonus')) {
        return `Tổng tiền thưởng của **${selectedEmployee.full_name}** là **${formatCurrency(bonuses)}**. 🎁`;
    }
    // Nghỉ không giải trình
    else if (lowerQuestion.includes('nghỉ không giải trình') || lowerQuestion.includes('unexplained absence')) {
        return `Tiền phạt nghỉ không giải trình của **${selectedEmployee.full_name}** là **${formatCurrency(unexplainedAbsencePenalty)}**.`;
    }
    // Lương ứng
    else if (lowerQuestion.includes('lương ứng') || lowerQuestion.includes('salary advance')) {
        return `Lương ứng của **${selectedEmployee.full_name}** là **${formatCurrency(salaryAdvance)}**. 💸`;
    }
    // Bảo hiểm
    else if (lowerQuestion.includes('bảo hiểm') || lowerQuestion.includes('bhxh') || lowerQuestion.includes('bhyt') || lowerQuestion.includes('bhtn')) {
        return `Chi tiết bảo hiểm của **${selectedEmployee.full_name}**:\n` +
               `- BHXH (${settings['bhxh_rate']}%): **${formatCurrency(salaryDetails.bhxh)}**\n` +
               `- BHYT (${settings['bhyt_rate']}%): **${formatCurrency(salaryDetails.bhyt)}**\n` +
               `- BHTN (${settings['bhtn_rate']}%): **${formatCurrency(salaryDetails.bhtn)}**\n` +
               `Tổng bảo hiểm: **${formatCurrency(salaryDetails.total_insurance)}**. 🛡️`;
    }
    // Thuế TNCN
    else if (lowerQuestion.includes('thuế') || lowerQuestion.includes('tncn')) {
        return `Thuế TNCN của **${selectedEmployee.full_name}** là **${formatCurrency(salaryDetails.income_tax)}**. Thu nhập chịu thuế: **${formatCurrency(salaryDetails.taxable_income)}**. 📊`;
    }
    // Phụ cấp
    else if (lowerQuestion.includes('phụ cấp') || lowerQuestion.includes('allowance')) {
        return `Phụ cấp của **${selectedEmployee.full_name}** là **${formatCurrency(salaryDetails.allowance)}**.`;
    }
    // Thông tin nhân viên
    else if (lowerQuestion.includes('phòng ban') || lowerQuestion.includes('chức vụ')) {
        return `**${selectedEmployee.full_name}** thuộc phòng ban **${selectedEmployee.department_name}**, chức vụ: **${selectedEmployee.position_name}**. 👤`;
    }
    // Hướng dẫn tính lương
    else if (lowerQuestion.includes('làm thế nào') || lowerQuestion.includes('cách tính lương')) {
        return "Để tính lương, bạn cần:\n" +
               "1. Chọn nhân viên từ danh sách.\n" +
               "2. Chọn tháng cần tính lương.\n" +
               "3. Nhập mức phạt vi phạm và phạt nghỉ không giải trình (nếu có).\n" +
               "4. Nhấn nút 'Tính Lương' để xem chi tiết. 📝";
    }
    // Câu hỏi không hiểu
    else {
        return "Tôi không hiểu câu hỏi của bạn. Bạn có thể hỏi về lương, chấm công, làm thêm, vi phạm, thưởng, bảo hiểm, thuế, hoặc thông tin nhân viên! 😊";
    }
}

// Định dạng tiền tệ
function formatCurrency(amount) {
    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(amount);
}

// Mở/Đóng chatbot khi nhấp vào icon
chatbotIcon.addEventListener('click', () => {
    chatbotContainer.classList.remove('chatbot-hidden');
    chatbotIcon.style.display = 'none'; // Ẩn icon khi mở chatbot
    showSuggestions(); // Hiển thị gợi ý khi mở chatbot
});

// Đóng chatbot khi nhấp vào nút "Đóng"
chatbotToggle.addEventListener('click', () => {
    chatbotContainer.classList.add('chatbot-hidden');
    chatbotIcon.style.display = 'flex'; // Hiện lại icon khi đóng chatbot
});

// Gửi câu hỏi
chatbotSend.addEventListener('click', () => {
    const question = chatbotInput.value.trim();
    if (question) {
        addMessage(question, true);
        const response = processQuestion(question);
        setTimeout(() => {
            addMessage(response);
            showSuggestions(); // Hiển thị lại gợi ý sau khi trả lời
        }, 500);
        chatbotInput.value = '';
    }
});

chatbotInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        chatbotSend.click();
    }
});
</script>
<?php include __DIR__ . '/../layouts/footer.php'; ?>