<?php
require_once __DIR__ . "/../../core/Database.php";

// Kết nối database
$db = Database::getInstance()->getConnection();

// Lấy thông tin công ty
$companyQuery = "SELECT * FROM company_info LIMIT 1";
$companyStmt = $db->prepare($companyQuery);
$companyStmt->execute();
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);

// Nếu không có thông tin công ty, sử dụng thông tin mặc định
if (!$company) {
    $company = [
        'name' => 'CÔNG TY TNHH PHÁT TRIỂN CÔNG NGHỆ ITIT',
        'address' => 'Lục Ngan',
        'phone' => '0562044109',
        'email' => '20211104@eaut.edu.vn',
        'tax_code' => '666666666666',
        'website' => 'hrmpv.online'
    ];
}

// Xử lý tham số báo cáo
$report_type = isset($_GET['type']) ? $_GET['type'] : 'employees';
$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$department_id = isset($_GET['department_id']) ? $_GET['department_id'] : '';
$position_id = isset($_GET['position_id']) ? $_GET['position_id'] : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-t');
$status = isset($_GET['status']) ? $_GET['status'] : '';
$explanation = isset($_GET['explanation']) ? $_GET['explanation'] : '';

// Lấy danh sách phòng ban và chức vụ cho filter
$departments = $db->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$positions = $db->query("SELECT id, name FROM positions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách trạng thái
$statuses = [
    'all' => 'Tất cả trạng thái',
    'chưa xử lý' => 'Chưa xử lý',
    'đang xử lý' => 'Đang xử lý',
    'đã duyệt' => 'Đã duyệt',
    'từ chối' => 'Từ chối',
    'vắng mặt' => 'Vắng mặt',
    'nghỉ phép' => 'Nghỉ phép'
];

// Hàm lấy dữ liệu báo cáo theo loại
function getReportData($db, $report_type, $params) {
    $data = [];
    
    switch ($report_type) {
        case 'employees':
            $query = "
                SELECT e.*, d.name as department_name, p.name as position_name
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN positions p ON e.position_id = p.id
                WHERE 1=1
            ";
            
            if (!empty($params['department_id'])) {
                $query .= " AND e.department_id = :department_id";
            }
            
            if (!empty($params['position_id'])) {
                $query .= " AND e.position_id = :position_id";
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $query .= " AND e.status = :status";
            }
            
            $query .= " ORDER BY e.full_name ASC";
            
            $stmt = $db->prepare($query);
            
            if (!empty($params['department_id'])) {
                $stmt->bindParam(':department_id', $params['department_id']);
            }
            
            if (!empty($params['position_id'])) {
                $stmt->bindParam(':position_id', $params['position_id']);
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $stmt->bindParam(':status', $params['status']);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            case 'recruitment':
                $query = "
                    SELECT ja.id, ja.fullname, ja.email, ja.phone, ja.position, 
                           ja.cv_path, ja.status, ja.application_date,
                           ja.experience, ja.education, ja.expected_salary, ja.start_date,
                           d.name AS department_name
                    FROM job_applications ja
                    LEFT JOIN departments d ON ja.department_id = d.id
                    WHERE ja.application_date BETWEEN :from_date AND :to_date
                ";
                
                if (!empty($params['status']) && $params['status'] !== 'all') {
                    $query .= " AND ja.status = :status";
                }
                
                if (!empty($params['department_id'])) {
                    $query .= " AND ja.department_id = :department_id";
                }
                
                $query .= " ORDER BY ja.application_date DESC, ja.fullname ASC";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':from_date', $params['from_date']);
                $stmt->bindParam(':to_date', $params['to_date']);
                
                if (!empty($params['status']) && $params['status'] !== 'all') {
                    $stmt->bindParam(':status', $params['status']);
                }
                
                if (!empty($params['department_id'])) {
                    $stmt->bindParam(':department_id', $params['department_id']);
                }
                
                $stmt->execute();
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                case 'salary':
                    $query = "
                        SELECT e.id, e.full_name, e.employee_code, d.name as department_name, p.name as position_name,
                               c.basic_salary, c.allowance, c.insurance_rate, c.tax_rate,
                               (c.basic_salary + c.allowance) as total_salary,
                               e.status as employee_status
                        FROM employees e
                        LEFT JOIN departments d ON e.department_id = d.id
                        LEFT JOIN positions p ON e.position_id = p.id
                        LEFT JOIN (
                            SELECT c1.*
                            FROM contracts c1
                            WHERE c1.status = 'active'
                            AND c1.id = (
                                SELECT c2.id
                                FROM contracts c2
                                WHERE c2.employee_id = c1.employee_id
                                AND c2.status = 'active'
                                ORDER BY c2.start_date DESC
                                LIMIT 1
                            )
                        ) c ON e.id = c.employee_id
                        WHERE 1=1
                    ";
                    
                    if (!empty($params['department_id'])) {
                        $query .= " AND e.department_id = :department_id";
                    }
                    
                    if (!empty($params['position_id'])) {
                        $query .= " AND e.position_id = :position_id";
                    }
                    
                    if (!empty($params['status']) && $params['status'] !== 'all') {
                        $query .= " AND e.status = :status";
                    }
                    
                    $query .= " ORDER BY e.full_name ASC";
                    
                    $stmt = $db->prepare($query);
                    
                    if (!empty($params['department_id'])) {
                        $stmt->bindParam(':department_id', $params['department_id']);
                    }
                    
                    if (!empty($params['position_id'])) {
                        $stmt->bindParam(':position_id', $params['position_id']);
                    }
                    
                    if (!empty($params['status']) && $params['status'] !== 'all') {
                        $stmt->bindParam(':status', $params['status']);
                    }
                    
                    $stmt->execute();
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
            
        case 'attendance':
            $query = "
                SELECT e.id, e.full_name, e.employee_code, d.name as department_name,
                       COUNT(a.id) as total_days,
                       SUM(CASE WHEN TIME(a.check_in) > '08:00:00' THEN 1 ELSE 0 END) as late_days,
                       SUM(CASE WHEN TIME(a.check_out) < '17:00:00' THEN 1 ELSE 0 END) as early_leave_days,
                       SUM(CASE WHEN TIME(a.check_out) > '17:00:00' THEN 1 ELSE 0 END) as overtime_days,
                       CASE 
                           WHEN COUNT(a.id) = 0 THEN 'Chưa xử lý'
                           WHEN COUNT(CASE WHEN a.status = 'approved' THEN 1 END) = COUNT(a.id) THEN 'Đã duyệt'
                           WHEN COUNT(CASE WHEN a.status IN ('pending', 'approved_checkin') THEN 1 END) > 0 THEN 'Đang xử lý'
                           WHEN COUNT(CASE WHEN a.status = 'rejected' THEN 1 END) > 0 THEN 'Từ chối'
                           WHEN COUNT(CASE WHEN a.status = 'absent' THEN 1 END) = COUNT(a.id) THEN 'Vắng mặt'
                           WHEN COUNT(CASE WHEN a.status = 'leave' THEN 1 END) = COUNT(a.id) THEN 'Nghỉ phép'
                           ELSE 'Không xác định'
                       END as attendance_status,
                       MAX(a.explanation) as explanation_text
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN attendance a ON e.id = a.employee_id
                WHERE a.check_in BETWEEN :from_date AND :to_date
            ";
            
            if (!empty($params['department_id'])) {
                $query .= " AND e.department_id = :department_id";
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $query .= " AND (
                    CASE 
                        WHEN COUNT(a.id) = 0 THEN 'Chưa xử lý'
                        WHEN COUNT(CASE WHEN a.status = 'approved' THEN 1 END) = COUNT(a.id) THEN 'Đã duyệt'
                        WHEN COUNT(CASE WHEN a.status IN ('pending', 'approved_checkin') THEN 1 END) > 0 THEN 'Đang xử lý'
                        WHEN COUNT(CASE WHEN a.status = 'rejected' THEN 1 END) > 0 THEN 'Từ chối'
                        WHEN COUNT(CASE WHEN a.status = 'absent' THEN 1 END) = COUNT(a.id) THEN 'Vắng mặt'
                        WHEN COUNT(CASE WHEN a.status = 'leave' THEN 1 END) = COUNT(a.id) THEN 'Nghỉ phép'
                        ELSE 'Không xác định'
                    END
                ) = :status";
            }
            
            if (!empty($params['explanation'])) {
                if ($params['explanation'] === 'yes') {
                    $query .= " AND a.explanation IS NOT NULL AND a.explanation != ''";
                } elseif ($params['explanation'] === 'no') {
                    $query .= " AND (a.explanation IS NULL OR a.explanation = '')";
                }
            }
            
            $query .= " GROUP BY e.id, e.full_name, e.employee_code, d.name ORDER BY e.full_name ASC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':from_date', $params['from_date']);
            $stmt->bindParam(':to_date', $params['to_date']);
            
            if (!empty($params['department_id'])) {
                $stmt->bindParam(':department_id', $params['department_id']);
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $stmt->bindParam(':status', $params['status']);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'late_employees':
            $query = "
                SELECT e.id, e.full_name, e.employee_code, d.name as department_name,
                       DATE(a.check_in) as attendance_date, TIME(a.check_in) as check_in_time,
                       TIMEDIFF(TIME(a.check_in), '08:00:00') as late_duration,
                       a.status, a.explanation as explanation_text
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                JOIN attendance a ON e.id = a.employee_id
                WHERE TIME(a.check_in) > '08:00:00'
                AND a.check_in BETWEEN :from_date AND :to_date
            ";
            
            if (!empty($params['department_id'])) {
                $query .= " AND e.department_id = :department_id";
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $query .= " AND a.status = :status";
            }
            
            if (!empty($params['explanation'])) {
                if ($params['explanation'] === 'yes') {
                    $query .= " AND a.explanation IS NOT NULL AND a.explanation != ''";
                } elseif ($params['explanation'] === 'no') {
                    $query .= " AND (a.explanation IS NULL OR a.explanation = '')";
                }
            }
            
            $query .= " ORDER BY a.check_in DESC, e.full_name ASC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':from_date', $params['from_date']);
            $stmt->bindParam(':to_date', $params['to_date']);
            
            if (!empty($params['department_id'])) {
                $stmt->bindParam(':department_id', $params['department_id']);
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $stmt->bindParam(':status', $params['status']);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        // Các case khác giữ nguyên như cũ...
        case 'new_employees':
            $query = "
                SELECT e.*, d.name as department_name, p.name as position_name
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN positions p ON e.position_id = p.id
                WHERE e.hire_date BETWEEN :from_date AND :to_date
            ";
            
            if (!empty($params['department_id'])) {
                $query .= " AND e.department_id = :department_id";
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $query .= " AND e.status = :status";
            }
            
            $query .= " ORDER BY e.hire_date DESC, e.full_name ASC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':from_date', $params['from_date']);
            $stmt->bindParam(':to_date', $params['to_date']);
            
            if (!empty($params['department_id'])) {
                $stmt->bindParam(':department_id', $params['department_id']);
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $stmt->bindParam(':status', $params['status']);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'departments':
            $query = "
                SELECT d.*, 
                       COUNT(e.id) as employee_count,
                       AVG(c.basic_salary) as avg_salary
                FROM departments d
                LEFT JOIN employees e ON d.id = e.department_id
                LEFT JOIN contracts c ON e.id = c.employee_id AND c.status = 'active'
                GROUP BY d.id
                ORDER BY d.name ASC
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'positions':
            $query = "
                SELECT p.*, 
                       COUNT(e.id) as employee_count,
                       AVG(c.basic_salary) as avg_salary
                FROM positions p
                LEFT JOIN employees e ON p.id = e.position_id
                LEFT JOIN contracts c ON e.id = c.employee_id AND c.status = 'active'
                GROUP BY p.id
                ORDER BY p.name ASC
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'leave':
            $query = "
                SELECT e.id, e.full_name, e.employee_code, d.name as department_name,
                       lr.start_date, lr.end_date, lr.reason, lr.status,
                       DATEDIFF(lr.end_date, lr.start_date) + 1 as leave_days
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                JOIN leave_requests lr ON e.id = lr.employee_id
                WHERE lr.start_date BETWEEN :from_date AND :to_date
            ";
            
            if (!empty($params['department_id'])) {
                $query .= " AND e.department_id = :department_id";
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $query .= " AND lr.status = :status";
            }
            
            $query .= " ORDER BY lr.start_date DESC, e.full_name ASC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':from_date', $params['from_date']);
            $stmt->bindParam(':to_date', $params['to_date']);
            
            if (!empty($params['department_id'])) {
                $stmt->bindParam(':department_id', $params['department_id']);
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $stmt->bindParam(':status', $params['status']);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'rewards':
            $query = "
                SELECT e.id, e.full_name, e.employee_code, d.name as department_name,
                       r.type, r.amount, r.reason, r.date
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                JOIN rewards r ON e.id = r.employee_id
                WHERE r.date BETWEEN :from_date AND :to_date
            ";
            
            if (!empty($params['department_id'])) {
                $query .= " AND e.department_id = :department_id";
            }
            
            $query .= " ORDER BY r.date DESC, e.full_name ASC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':from_date', $params['from_date']);
            $stmt->bindParam(':to_date', $params['to_date']);
            
            if (!empty($params['department_id'])) {
                $stmt->bindParam(':department_id', $params['department_id']);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'salary_advances':
            $query = "
                SELECT e.id, e.full_name, e.employee_code, d.name as department_name,
                       sa.amount, sa.reason, sa.request_date, sa.status
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                JOIN salary_advances sa ON e.id = sa.employee_id
                WHERE sa.request_date BETWEEN :from_date AND :to_date
            ";
            
            if (!empty($params['department_id'])) {
                $query .= " AND e.department_id = :department_id";
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $query .= " AND sa.status = :status";
            }
            
            $query .= " ORDER BY sa.request_date DESC, e.full_name ASC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':from_date', $params['from_date']);
            $stmt->bindParam(':to_date', $params['to_date']);
            
            if (!empty($params['department_id'])) {
                $stmt->bindParam(':department_id', $params['department_id']);
            }
            
            if (!empty($params['status']) && $params['status'] !== 'all') {
                $stmt->bindParam(':status', $params['status']);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    return $data;
    
}

// Lấy tiêu đề báo cáo
function getReportTitle($report_type) {
    $titles = [
        'employees' => 'DANH SÁCH NHÂN VIÊN',
        'salary' => 'BÁO CÁO LƯƠNG NHÂN VIÊN',
        'attendance' => 'BÁO CÁO CHẤM CÔNG',
        'late_employees' => 'BÁO CÁO NHÂN VIÊN ĐI MUỘN',
        'new_employees' => 'DANH SÁCH NHÂN VIÊN MỚI',
        'departments' => 'BÁO CÁO PHÒNG BAN',
        'positions' => 'BÁO CÁO CHỨC VỤ',
        'leave' => 'BÁO CÁO NGHỈ PHÉP',
        'rewards' => 'BÁO CÁO THƯỞNG PHẠT',
        'salary_advances' => 'BÁO CÁO TẠM ỨNG LƯƠNG',
        'recruitment' => 'BÁO CÁO ỨNG TUYỂN', 
    ];
    
    return $titles[$report_type] ?? 'BÁO CÁO';
}

// Lấy dữ liệu báo cáo
$report_params = [
    'month' => $month,
    'year' => $year,
    'department_id' => $department_id,
    'position_id' => $position_id,
    'from_date' => $from_date,
    'to_date' => $to_date,
    'status' => $status,
    'explanation' => $explanation
];

$report_data = getReportData($db, $report_type, $report_params);
$report_title = getReportTitle($report_type);

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $report_title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/HRMpv/public/css/lanh.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Các Danh sách </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Loại Báo Cáo:</label>
                                <select name="type" class="form-select" onchange="updateFormFields()">
                                    <option value="employees" <?= $report_type == 'employees' ? 'selected' : '' ?>>Danh sách nhân viên</option>
                                    <option value="salary" <?= $report_type == 'salary' ? 'selected' : '' ?>>Báo cáo lương</option>
                                    <option value="attendance" <?= $report_type == 'attendance' ? 'selected' : '' ?>>Báo cáo chấm công</option>
                                    <option value="late_employees" <?= $report_type == 'late_employees' ? 'selected' : '' ?>>Nhân viên đi muộn</option>
                                    <option value="new_employees" <?= $report_type == 'new_employees' ? 'selected' : '' ?>>Nhân viên mới</option>
                                    <option value="departments" <?= $report_type == 'departments' ? 'selected' : '' ?>>Báo cáo phòng ban</option>
                                    <option value="positions" <?= $report_type == 'positions' ? 'selected' : '' ?>>Báo cáo chức vụ</option>
                                    <option value="leave" <?= $report_type == 'leave' ? 'selected' : '' ?>>Báo cáo nghỉ phép</option>
                                    <option value="rewards" <?= $report_type == 'rewards' ? 'selected' : '' ?>>Báo cáo thưởng phạt</option>
                                    <option value="salary_advances" <?= $report_type == 'salary_advances' ? 'selected' : '' ?>>Báo cáo tạm ứng lương</option>
                                    <option value="recruitment" <?= $report_type == 'recruitment' ? 'selected' : '' ?>>Báo cáo ứng tuyển</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3 date-field" id="from-date-field">
                                <label class="form-label">Từ Ngày:</label>
                                <input type="date" name="from_date" class="form-control" value="<?= $from_date ?>">
                            </div>
                            
                            <div class="col-md-3 date-field" id="to-date-field">
                                <label class="form-label">Đến Ngày:</label>
                                <input type="date" name="to_date" class="form-control" value="<?= $to_date ?>">
                            </div>
                            
                            <div class="col-md-3">
                            <label>Phòng ban:</label>
    <select name="department_id" class="form-select">
        <option value="">Tất cả phòng ban</option>
        <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>" <?= $department_id == $dept['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($dept['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
                            </div>
                            
                            <div class="col-md-3" id="position-field">
                                <label class="form-label">Chức Vụ:</label>
                                <select name="position_id" class="form-select">
                                    <option value="">Tất cả chức vụ</option>
                                    <?php foreach ($positions as $pos): ?>
                                        <option value="<?= $pos['id'] ?>" <?= $position_id == $pos['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($pos['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Trạng Thái:</label>
                                <select name="status" class="form-select">
                                    <?php foreach ($statuses as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3" id="explanation-field">
                                <label class="form-label">Giải trình:</label>
                                <select name="explanation" class="form-select">
                                    <option value="" <?= $explanation === '' ? 'selected' : '' ?>>Tất cả</option>
                                    <option value="yes" <?= $explanation === 'yes' ? 'selected' : '' ?>>Có giải trình</option>
                                    <option value="no" <?= $explanation === 'no' ? 'selected' : '' ?>>Không có giải trình</option>
                                </select>
                            </div>
                            
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Lọc Báo Cáo
                                </button>
                                <button type="button" class="btn btn-success" onclick="printReport()">
                                    <i class="fas fa-print"></i> In Báo Cáo
                                </button>
                                <button type="button" class="btn btn-info" onclick="exportExcel()">
                                    <i class="fas fa-file-excel"></i> Xuất Excel
                                </button>
                                <button type="button" class="btn btn-danger" onclick="exportPDF()">
                                    <i class="fas fa-file-pdf"></i> Xuất PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body" id="report-content">
                        <!-- Phần header báo cáo -->
                        <div class="report-header">
                            <div class="row">
                                <div class="col-6 text-left">
                                    <p class="mb-1">CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</p>
                                    <p class="mb-3"><strong>Độc lập - Tự do - Hạnh phúc</strong></p>
                                    <p class="mb-1"><?= htmlspecialchars($company['name']) ?></p>
                                    <p class="mb-1">Địa chỉ: <?= htmlspecialchars($company['address']) ?></p>
                                    <p class="mb-1">Điện thoại: <?= htmlspecialchars($company['phone']) ?></p>
                                    <p class="mb-1">Mã số thuế: <?= htmlspecialchars($company['tax_code']) ?></p>
                                </div>
                                <div class="col-6 text-right">
                                    <p class="mb-3">Ngày <?= date('d') ?> tháng <?= date('m') ?> năm <?= date('Y') ?></p>
                                </div>
                            </div>
                            
                            <div class="text-center my-4">
                                <h2 class="report-title"><?= $report_title ?></h2>
                                <p class="report-subtitle">Từ ngày <?= date('d/m/Y', strtotime($from_date)) ?> đến ngày <?= date('d/m/Y', strtotime($to_date)) ?></p>
                            </div>
                        </div>
                        
                        <!-- Nội dung báo cáo -->
                        <div class="report-content">
                            <?php if ($report_type == 'employees'): ?>
                                <!-- Báo cáo danh sách nhân viên -->
                                <table class="table table-bordered table-striped">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã NV</th>
                                            <th>Họ và tên</th>
                                            <th>Ngày sinh</th>
                                            <th>Giới tính</th>
                                            <th>Số điện thoại</th>
                                            <th>Email</th>
                                            <th>Phòng ban</th>
                                            <th>Chức vụ</th>
                                            <th>Ngày vào làm</th>
                                            <th>Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($report_data)): ?>
                                            <tr>
                                                <td colspan="11" class="text-center">Không có dữ liệu</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($report_data as $index => $employee): ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td><?= htmlspecialchars($employee['employee_code'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($employee['full_name']) ?></td>
                                                    <td><?= !empty($employee['birth_date']) ? date('d/m/Y', strtotime($employee['birth_date'])) : 'N/A' ?></td>
                                                    <td><?= htmlspecialchars($employee['gender'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($employee['phone'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($employee['email'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($employee['department_name'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($employee['position_name'] ?? 'N/A') ?></td>
                                                    <td><?= $employee['hire_date'] ? date('d/m/Y', strtotime($employee['hire_date'])) : 'Chưa xác định' ?></td>
                                                    <td>
                                                        <?php
                                                        $statusClass = '';
                                                        $statusText = '';
                                                        switch($employee['status']) {
                                                            case 'active':
                                                                $statusClass = 'bg-success';
                                                                $statusText = 'Đang làm việc';
                                                                break;
                                                            case 'pending':
                                                                $statusClass = 'bg-warning';
                                                                $statusText = 'Chờ duyệt';
                                                                break;
                                                            case 'expired':
                                                                $statusClass = 'bg-danger';
                                                                $statusText = 'Hết hạn';
                                                                break;
                                                            default:
                                                                $statusClass = 'bg-secondary';
                                                                $statusText = 'Không xác định';
                                                        }
                                                        ?>
                                                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                
                            <?php elseif ($report_type == 'salary'): ?>
                                <!-- Báo cáo lương nhân viên -->
                                <table class="table table-bordered table-striped">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã NV</th>
                                            <th>Họ và tên</th>
                                            <th>Phòng ban</th>
                                            <th>Chức vụ</th>
                                            <th>Lương cơ bản</th>
                                            <th>Phụ cấp</th>
                                            <th>Tỷ lệ BH</th>
                                            <th>Tỷ lệ thuế</th>
                                            <th>Tổng lương</th>
                                            <th>Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($report_data)): ?>
                                            <tr>
                                                <td colspan="11" class="text-center">Không có dữ liệu</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php 
                                            $total_basic_salary = 0;
                                            $total_allowance = 0;
                                            $total_salary = 0;
                                            ?>
                                            <?php foreach ($report_data as $index => $salary): ?>
                                                <?php 
                                                $total_basic_salary += $salary['basic_salary'] ?? 0;
                                                $total_allowance += $salary['allowance'] ?? 0;
                                                $total_salary += $salary['total_salary'] ?? 0;
                                                ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td><?= htmlspecialchars($salary['employee_code'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($salary['full_name']) ?></td>
                                                    <td><?= htmlspecialchars($salary['department_name'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($salary['position_name'] ?? 'N/A') ?></td>
                                                    <td class="text-right"><?= number_format($salary['basic_salary'] ?? 0, 0, ',', '.') ?> VNĐ</td>
                                                    <td class="text-right"><?= number_format($salary['allowance'] ?? 0, 0, ',', '.') ?> VNĐ</td>
                                                    <td class="text-center"><?= ($salary['insurance_rate'] ?? 0) * 100 ?>%</td>
                                                    <td class="text-center"><?= ($salary['tax_rate'] ?? 0) * 100 ?>%</td>
                                                    <td class="text-right"><?= number_format($salary['total_salary'] ?? 0, 0, ',', '.') ?> VNĐ</td>
                                                    <td>
                                                        <?php
                                                        $statusClass = '';
                                                        $statusText = '';
                                                        $statusValue = $salary['employee_status'] ?? '';
                                                        switch($statusValue) {
                                                            case 'active':
                                                                $statusClass = 'bg-success';
                                                                $statusText = 'Đang làm việc';
                                                                break;
                                                            case 'pending':
                                                                $statusClass = 'bg-warning';
                                                                $statusText = 'Chờ duyệt';
                                                                break;
                                                            case 'expired':
                                                                $statusClass = 'bg-danger';
                                                                $statusText = 'Hết hạn';
                                                                break;
                                                            default:
                                                                $statusClass = 'bg-secondary';
                                                                $statusText = 'Không xác định';
                                                        }
                                                        ?>
                                                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-info font-weight-bold">
                                                <td colspan="5" class="text-right">Tổng cộng:</td>
                                                <td class="text-right"><?= number_format($total_basic_salary, 0, ',', '.') ?> VNĐ</td>
                                                <td class="text-right"><?= number_format($total_allowance, 0, ',', '.') ?> VNĐ</td>
                                                <td></td>
                                                <td></td>
                                                <td class="text-right"><?= number_format($total_salary, 0, ',', '.') ?> VNĐ</td>
                                                <td></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                
                            <?php elseif ($report_type == 'attendance'): ?>
                                
                                <!-- Báo cáo chấm công -->
                                <table class="table table-bordered table-striped">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã NV</th>
                                            <th>Họ và tên</th>
                                            <th>Phòng ban</th>
                                            <th>Tổng ngày công</th>
                                            <th>Số ngày đi muộn</th>
                                            <th>Số ngày về sớm</th>
                                            <th>Số ngày tăng ca</th>
                                            <th>Trạng thái</th>
                                            <th>Giải trình</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($report_data)): ?>
                                            <tr>
                                                <td colspan="10" class="text-center">Không có dữ liệu</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php 
                                            $total_days = 0;
                                            $total_late_days = 0;
                                            $total_early_leave_days = 0;
                                            $total_overtime_days = 0;
                                            ?>
                                            <?php foreach ($report_data as $index => $attendance): ?>
                                                <?php 
                                                $total_days += $attendance['total_days'] ?? 0;
                                                $total_late_days += $attendance['late_days'] ?? 0;
                                                $total_early_leave_days += $attendance['early_leave_days'] ?? 0;
                                                $total_overtime_days += $attendance['overtime_days'] ?? 0;
                                                ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td><?= htmlspecialchars($attendance['employee_code'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($attendance['full_name']) ?></td>
                                                    <td><?= htmlspecialchars($attendance['department_name'] ?? 'N/A') ?></td>
                                                    <td class="text-center"><?= $attendance['total_days'] ?? 0 ?></td>
                                                    <td class="text-center"><?= $attendance['late_days'] ?? 0 ?></td>
                                                    <td class="text-center"><?= $attendance['early_leave_days'] ?? 0 ?></td>
                                                    <td class="text-center"><?= $attendance['overtime_days'] ?? 0 ?></td>
                                                    <td class="status-column">
                                                        <?php
                                                        $status_text = '';
                                                        $status_class = '';
                                                        $raw_status = $attendance['attendance_status'] ?? '';
                                                        $status_value = mb_strtolower(mb_convert_encoding(trim($raw_status), 'UTF-8', 'auto'), 'UTF-8');
                                                        switch (true) {
                                                            case mb_stripos($status_value, 'đang xử lý') !== false:
                                                                $status_text = 'Đang xử lý';
                                                                $status_class = 'bg-warning text-dark';
                                                                break;
                                                            case mb_stripos($status_value, 'đã duyệt') !== false:
                                                                $status_text = 'Đã duyệt';
                                                                $status_class = 'bg-success';
                                                                break;
                                                            case mb_stripos($status_value, 'từ chối') !== false:
                                                                $status_text = 'Từ chối';
                                                                $status_class = 'bg-danger';
                                                                break;
                                                            case mb_stripos($status_value, 'vắng mặt') !== false:
                                                                $status_text = 'Vắng mặt';
                                                                $status_class = 'bg-secondary';
                                                                break;
                                                            case mb_stripos($status_value, 'nghỉ phép') !== false:
                                                                $status_text = 'Nghỉ phép';
                                                                $status_class = 'bg-primary';
                                                                break;
                                                            case mb_stripos($status_value, 'chưa xử lý') !== false:
                                                                $status_text = 'Chưa xử lý';
                                                                $status_class = 'bg-secondary';
                                                                break;
                                                            default:
                                                                $status_text = $raw_status;
                                                                $status_class = 'bg-secondary';
                                                                break;
                                                        }
                                                        ?>
                                                        <span class="badge <?= $status_class ?>"><?= htmlspecialchars($status_text) ?></span>
                                                    </td>
                                                    <td><?= htmlspecialchars($attendance['explanation_text'] ?? 'Không có') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-info font-weight-bold">
                                                <td colspan="4" class="text-right">Tổng cộng:</td>
                                                <td class="text-center"><?= $total_days ?></td>
                                                <td class="text-center"><?= $total_late_days ?></td>
                                                <td class="text-center"><?= $total_early_leave_days ?></td>
                                                <td class="text-center"><?= $total_overtime_days ?></td>
                                                <td colspan="2"></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                
                            <?php elseif ($report_type == 'late_employees'): ?>
                                <!-- Báo cáo nhân viên đi muộn -->
                                <table class="table table-bordered table-striped">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã NV</th>
                                            <th>Họ và tên</th>
                                            <th>Phòng ban</th>
                                            <th>Ngày</th>
                                            <th>Giờ check-in</th>
                                            <th>Thời gian đi muộn</th>
                                            <th>Trạng thái</th>
                                            <th>Giải trình</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($report_data)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center">Không có dữ liệu</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($report_data as $index => $late): ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td><?= htmlspecialchars($late['employee_code'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($late['full_name']) ?></td>
                                                    <td><?= htmlspecialchars($late['department_name'] ?? 'N/A') ?></td>
                                                    <td><?= date('d/m/Y', strtotime($late['attendance_date'])) ?></td>
                                                    <td><?= $late['check_in_time'] ?></td>
                                                    <td><?= $late['late_duration'] ?></td>
                                                    <td>
                                                        <?php
                                                        $statusClass = '';
                                                        $statusText = '';
                                                        switch($late['status']) {
                                                            case 'approved':
                                                                $statusClass = 'bg-success';
                                                                $statusText = 'Đã duyệt';
                                                                break;
                                                            case 'pending':
                                                                $statusClass = 'bg-warning';
                                                                $statusText = 'Chờ duyệt';
                                                                break;
                                                            case 'rejected':
                                                                $statusClass = 'bg-danger';
                                                                $statusText = 'Từ chối';
                                                                break;
                                                            default:
                                                                $statusClass = 'bg-secondary';
                                                                $statusText = 'Không xác định';
                                                        }
                                                        ?>
                                                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                                    </td>
                                                    <td><?= htmlspecialchars($late['explanation_text'] ?? 'Không có') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                
                            <?php elseif ($report_type == 'new_employees'): ?>
                                <!-- Báo cáo nhân viên mới -->
                                <table class="table table-bordered table-striped">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã NV</th>
                                            <th>Họ và tên</th>
                                            <th>Ngày sinh</th>
                                            <th>Giới tính</th>
                                            <th>Số điện thoại</th>
                                            <th>Email</th>
                                            <th>Phòng ban</th>
                                            <th>Chức vụ</th>
                                            <th>Ngày vào làm</th>
                                            <th>Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($report_data)): ?>
                                            <tr>
                                                <td colspan="11" class="text-center">Không có dữ liệu</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($report_data as $index => $employee): ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td><?= htmlspecialchars($employee['employee_code'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($employee['full_name']) ?></td>
                                                    <td><?= !empty($employee['birth_date']) ? date('d/m/Y', strtotime($employee['birth_date'])) : 'N/A' ?></td>
                                                    <td><?= htmlspecialchars($employee['gender'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($employee['phone'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($employee['email'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($employee['department_name'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($employee['position_name'] ?? 'N/A') ?></td>
                                                    <td><?= !empty($employee['hire_date']) ? date('d/m/Y', strtotime($employee['hire_date'])) : 'N/A' ?></td>
                                                    <td>
                                                        <?php
                                                        $statusClass = '';
                                                        $statusText = '';
                                                        switch($employee['status']) {
                                                            case 'active':
                                                                $statusClass = 'bg-success';
                                                                $statusText = 'Đang làm việc';
                                                                break;
                                                            case 'pending':
                                                                $statusClass = 'bg-warning';
                                                                $statusText = 'Chờ duyệt';
                                                                break;
                                                            case 'expired':
                                                                $statusClass = 'bg-danger';
                                                                $statusText = 'Hết hạn';
                                                                break;
                                                            default:
                                                                $statusClass = 'bg-secondary';
                                                                $statusText = 'Không xác định';
                                                        }
                                                        ?>
                                                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                
                            <?php elseif ($report_type == 'departments'): ?>
                                <!-- Báo cáo phòng ban -->
                                <table class="table table-bordered table-striped">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã phòng ban</th>
                                            <th>Tên phòng ban</th>
                                            <th>Mô tả</th>
                                            <th>Số lượng nhân viên</th>
                                            <th>Lương trung bình</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($report_data)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center">Không có dữ liệu</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php 
                                            $total_employees = 0;
                                            $total_avg_salary = 0;
                                            $count_departments = count($report_data);
                                            ?>
                                            <?php foreach ($report_data as $index => $department): ?>
                                                <?php 
                                                $total_employees += $department['employee_count'] ?? 0;
                                                $total_avg_salary += $department['avg_salary'] ?? 0;
                                                ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td><?= htmlspecialchars($department['code'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($department['name']) ?></td>
                                                    <td><?= htmlspecialchars($department['description'] ?? 'N/A') ?></td>
                                                    <td class="text-center"><?= $department['employee_count'] ?? 0 ?></td>
                                                    <td class="text-right"><?= number_format($department['avg_salary'] ?? 0, 0, ',', '.') ?> VNĐ</td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-info font-weight-bold">
                                                <td colspan="4" class="text-right">Tổng cộng:</td>
                                                <td class="text-center"><?= $total_employees ?></td>
                                                <td class="text-right"><?= number_format($total_avg_salary / ($count_departments ?: 1), 0, ',', '.') ?> VNĐ</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                
                            <?php elseif ($report_type == 'positions'): ?>
                                <!-- Báo cáo chức vụ -->
                                <table class="table table-bordered table-striped">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã chức vụ</th>
                                            <th>Tên chức vụ</th>
                                            <th>Mô tả</th>
                                            <th>Số lượng nhân viên</th>
                                            <th>Lương trung bình</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($report_data)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center">Không có dữ liệu</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php 
                                            $total_employees = 0;
                                            $total_avg_salary = 0;
                                            $count_positions = count($report_data);
                                            ?>
                                            <?php foreach ($report_data as $index => $position): ?>
                                                <?php 
                                                $total_employees += $position['employee_count'] ?? 0;
                                                $total_avg_salary += $position['avg_salary'] ?? 0;
                                                ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td><?= htmlspecialchars($position['code'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($position['name']) ?></td>
                                                    <td><?= htmlspecialchars($position['description'] ?? 'N/A') ?></td>
                                                    <td class="text-center"><?= $position['employee_count'] ?? 0 ?></td>
                                                    <td class="text-right"><?= number_format($position['avg_salary'] ?? 0, 0, ',', '.') ?> VNĐ</td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-info font-weight-bold">
                                                <td colspan="4" class="text-right">Tổng cộng:</td>
                                                <td class="text-center"><?= $total_employees ?></td>
                                                <td class="text-right"><?= number_format($total_avg_salary / ($count_positions ?: 1), 0, ',', '.') ?> VNĐ</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                
                            <?php elseif ($report_type == 'leave'): ?>
                                <!-- Báo cáo nghỉ phép -->
                                <table class="table table-bordered table-striped">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã NV</th>
                                            <th>Họ và tên</th>
                                            <th>Phòng ban</th>
                                            <th>Từ ngày</th>
                                            <th>Đến ngày</th>
                                            <th>Số ngày</th>
                                            <th>Lý do</th>
                                            <th>Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($report_data)): ?>
                                            <tr>
                                                <td colspan="9" class="text-center">Không có dữ liệu</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php 
                                            $total_leave_days = 0;
                                            ?>
                                            <?php foreach ($report_data as $index => $leave): ?>
                                                <?php 
                                                $total_leave_days += $leave['leave_days'] ?? 0;
                                                ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td><?= htmlspecialchars($leave['employee_code'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($leave['full_name']) ?></td>
                                                    <td><?= htmlspecialchars($leave['department_name'] ?? 'N/A') ?></td>
                                                    <td><?= date('d/m/Y', strtotime($leave['start_date'])) ?></td>
                                                    <td><?= date('d/m/Y', strtotime($leave['end_date'])) ?></td>
                                                    <td class="text-center"><?= $leave['leave_days'] ?? 0 ?></td>
                                                    <td><?= htmlspecialchars($leave['reason']) ?></td>
                                                    <td>
                                                        <?php
                                                        $statusClass = '';
                                                        $statusText = '';
                                                        switch($leave['status']) {
                                                            case 'Đã duyệt':
                                                                $statusClass = 'bg-success';
                                                                $statusText = 'Đã duyệt';
                                                                break;
                                                            case 'Chờ duyệt':
                                                                $statusClass = 'bg-warning';
                                                                $statusText = 'Chờ duyệt';
                                                                break;
                                                            case 'Từ chối':
                                                                $statusClass = 'bg-danger';
                                                                $statusText = 'Từ chối';
                                                                break;
                                                            default:
                                                                $statusClass = 'bg-secondary';
                                                                $statusText = 'Không xác định';
                                                        }
                                                        ?>
                                                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-info font-weight-bold">
                                                <td colspan="6" class="text-right">Tổng cộng:</td>
                                                <td class="text-center"><?= $total_leave_days ?></td>
                                                <td colspan="2"></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                
                            <?php elseif ($report_type == 'rewards'): ?>
                                <!-- Báo cáo thưởng phạt -->
                                <table class="table table-bordered table-striped">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>STT</th>
                                            <th>Mã NV</th>
                                            <th>Họ và tên</th>
                                            <th>Phòng ban</th>
                                            <th>Loại</th>
                                            <th>Số tiền</th>
                                            <th>Lý do</th>
                                            <th>Ngày</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($report_data)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center">Không có dữ liệu</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php 
                                            $total_reward = 0;
                                            $total_penalty = 0;
                                            ?>
                                            <?php foreach ($report_data as $index => $reward): ?>
                                                <?php 
                                                if ($reward['type'] == 'Thưởng') {
                                                    $total_reward += $reward['amount'] ?? 0;
                                                } else {
                                                    $total_penalty += $reward['amount'] ?? 0;
                                                }
                                                ?>
                                                <tr>
                                                    <td><?= $index + 1 ?></td>
                                                    <td><?= htmlspecialchars($reward['employee_code'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($reward['full_name']) ?></td>
                                                    <td><?= htmlspecialchars($reward['department_name'] ?? 'N/A') ?></td>
                                                    <td>
                                                        <?php if ($reward['type'] == 'Thưởng'): ?>
                                                            <span class="badge bg-success">Thưởng</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Phạt</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-right"><?= number_format($reward['amount'] ?? 0, 0, ',', '.') ?> VNĐ</td>
                                                    <td><?= htmlspecialchars($reward['reason']) ?></td>
                                                    <td><?= date('d/m/Y', strtotime($reward['date'])) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="table-info font-weight-bold">
                                                <td colspan="5" class="text-right">Tổng thưởng:</td>
                                                <td class="text-right"><?= number_format($total_reward, 0, ',', '.') ?> VNĐ</td>
                                                <td colspan="2"></td>
                                            </tr>
                                            <tr class="table-info font-weight-bold">
                                                <td colspan="5" class="text-right">Tổng phạt:</td>
                                                <td class="text-right"><?= number_format($total_penalty, 0, ',', '.') ?> VNĐ</td>
                                                <td colspan="2"></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                                <?php elseif ($report_type == 'recruitment'): ?>
    <!-- Báo cáo ứng tuyển -->
    <table class="table table-bordered table-striped">
        <thead class="table-primary">
            <tr>
                <th>STT</th>
                <th>Họ và tên</th>
                <th>Email</th>
                <th>Số điện thoại</th>
                <th>Vị trí ứng tuyển</th>
                <th>Phòng ban</th>
                <th>Kinh nghiệm</th>
                <th>Học vấn</th>
                <th>Lương mong muốn</th>
                <th>Ngày bắt đầu</th>
                <th>Ngày ứng tuyển</th>
                <th>Trạng thái</th>
                <th>CV</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($report_data)): ?>
                <tr>
                    <td colspan="13" class="text-center">Không có dữ liệu</td>
                </tr>
            <?php else: ?>
                <?php foreach ($report_data as $index => $application): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($application['fullname']) ?></td>
                        <td><?= htmlspecialchars($application['email']) ?></td>
                        <td><?= htmlspecialchars($application['phone']) ?></td>
                        <td><?= htmlspecialchars($application['position']) ?></td>
                        <td><?= htmlspecialchars($application['department_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($application['experience']) ?></td>
                        <td><?= htmlspecialchars($application['education']) ?></td>
                        <td><?= number_format($application['expected_salary'], 0, ',', '.') ?> VNĐ</td>
                        <td><?= date('d/m/Y', strtotime($application['start_date'])) ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($application['application_date'])) ?></td>
                        <td>
                            <?php
                            $statusClass = '';
                            $statusText = '';
                            switch ($application['status']) {
                                case 'pending':
                                    $statusClass = 'bg-warning';
                                    $statusText = 'Chờ xử lý';
                                    break;
                                case 'approved':
                                    $statusClass = 'bg-success';
                                    $statusText = 'Đã duyệt';
                                    break;
                                case 'rejected':
                                    $statusClass = 'bg-danger';
                                    $statusText = 'Từ chối';
                                    break;
                                default:
                                    $statusClass = 'bg-secondary';
                                    $statusText = $application['status'] ?? 'Không xác định';
                            }
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                        </td>
                        <td>
                            <?php if (!empty($application['cv_path'])): ?>
                                <a href="https://drive.google.com/file/d/<?= htmlspecialchars($application['cv_path']) ?>/view" target="_blank" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> Xem CV
                                </a>
                                <a href="https://drive.google.com/uc?export=download&id=<?= htmlspecialchars($application['cv_path']) ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-download"></i> Tải CV
                                </a>
                            <?php else: ?>
                                Không có
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
          
        </tbody>
    </table>
<?php endif; ?> <!-- Đóng khối recruitment -->

<?php elseif ($report_type == 'salary_advances'): ?>
    <!-- Báo cáo tạm ứng lương -->
    <table class="table table-bordered table-striped">
        <thead class="table-primary">
            <tr>
                <th>STT</th>
                <th>Mã NV</th>
                <th>Họ và tên</th>
                <th>Phòng ban</th>
                <th>Số tiền</th>
                <th>Lý do</th>
                <th>Ngày yêu cầu</th>
                <th>Trạng thái</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($report_data)): ?>
                <tr>
                    <td colspan="8" class="text-center">Không có dữ liệu</td>
                </tr>
            <?php else: ?>
                <?php 
                $total_advance = 0;
                $total_approved = 0;
                ?>
                <?php foreach ($report_data as $index => $advance): ?>
                    <?php 
                    $total_advance += $advance['amount'] ?? 0;
                    if ($advance['status'] == 'Đã duyệt') {
                        $total_approved += $advance['amount'] ?? 0;
                    }
                    ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($advance['employee_code'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($advance['full_name']) ?></td>
                        <td><?= htmlspecialchars($advance['department_name'] ?? 'N/A') ?></td>
                        <td class="text-right"><?= number_format($advance['amount'] ?? 0, 0, ',', '.') ?> VNĐ</td>
                        <td><?= htmlspecialchars($advance['reason']) ?></td>
                        <td><?= date('d/m/Y', strtotime($advance['request_date'])) ?></td>
                        <td>
                            <?php
                            $statusClass = '';
                            $statusText = '';
                            switch($advance['status']) {
                                case 'Đã duyệt':
                                    $statusClass = 'bg-success';
                                    $statusText = 'Đã duyệt';
                                    break;
                                case 'Chờ duyệt':
                                    $statusClass = 'bg-warning';
                                    $statusText = 'Chờ duyệt';
                                    break;
                                case 'Từ chối':
                                    $statusClass = 'bg-danger';
                                    $statusText = 'Từ chối';
                                    break;
                                default:
                                    $statusClass = 'bg-secondary';
                                    $statusText = 'Không xác định';
                            }
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="table-info font-weight-bold">
                    <td colspan="4" class="text-right">Tổng tạm ứng:</td>
                    <td class="text-right"><?= number_format($total_advance, 0, ',', '.') ?> VNĐ</td>
                    <td colspan="3"></td>
                </tr>
                <tr class="table-info font-weight-bold">
                    <td colspan="4" class="text-right">Tổng đã duyệt:</td>
                    <td class="text-right"><?= number_format($total_approved, 0, ',', '.') ?> VNĐ</td>
                    <td colspan="3"></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
<?php endif; ?>
                        </div>
                        
                        <!-- Phần footer báo cáo -->
                        <div class="report-footer mt-5">
                            <div class="row">
                                <div class="col-6 text-center">
                                    <p class="mb-1">Người lập báo cáo</p>
                                    <p class="mb-5"><em>(Ký, ghi rõ họ tên)</em></p>
                                </div>
                                <div class="col-6 text-center">
                                    <p class="mb-1">Giám đốc</p>
                                    <p class="mb-5"><em>(Ký, đóng dấu)</em></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Hàm cập nhật các trường hiển thị dựa trên loại báo cáo
    function updateFormFields() {
        const reportType = document.querySelector('select[name="type"]').value;
        const fromDateField = document.getElementById('from-date-field');
        const toDateField = document.getElementById('to-date-field');
        const positionField = document.getElementById('position-field');
        const statusField = document.querySelector('select[name="status"]').closest('.col-md-3');
        const explanationField = document.getElementById('explanation-field');
        const departmentField = document.querySelector('select[name="department_id"]').closest('.col-md-3');

        // Mặc định ẩn tất cả các trường
        fromDateField.style.display = 'none';
        toDateField.style.display = 'none';
        positionField.style.display = 'none';
        statusField.style.display = 'none';
        explanationField.style.display = 'none';
        departmentField.style.display = 'block'; // Phòng ban luôn hiển thị mặc định

        // Logic hiển thị các trường theo loại báo cáo
        switch (reportType) {
            case 'employees':
            case 'salary':
                positionField.style.display = 'block';
                statusField.style.display = 'block';
                break;

            case 'attendance':
            case 'late_employees':
                fromDateField.style.display = 'block';
                toDateField.style.display = 'block';
                statusField.style.display = 'block';
                explanationField.style.display = 'block';
                break;

            case 'new_employees':
            case 'leave':
            case 'rewards':
            case 'salary_advances':
            case 'recruitment':
                fromDateField.style.display = 'block';
                toDateField.style.display = 'block';
                statusField.style.display = 'block';
                break;

            case 'departments':
            case 'positions':
                // Không cần thêm trường nào ngoài phòng ban (đã hiển thị mặc định)
                break;

            default:
                console.warn(`Loại báo cáo '${reportType}' không được hỗ trợ trong updateFormFields`);
                break;
        }
    }

    // Hàm in báo cáo
    function printReport() {
        const printContent = document.getElementById('report-content').innerHTML;
        const originalContent = document.body.innerHTML;

        // Tạo nội dung in với CSS cần thiết
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
            <head>
                <title>In Báo Cáo</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <link rel="stylesheet" href="/HRMpv/public/css/lanh.css">
                <style>
                    @media print {
                        .no-print { display: none; }
                        body { margin: 20mm; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { border: 1px solid black; padding: 8px; }
                    }
                </style>
            </head>
            <body>
                <div class="container-fluid">
                    ${printContent}
                </div>
            </body>
            </html>
        `);
        
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
        printWindow.close();

        // Không cần khôi phục body vì sử dụng cửa sổ mới
    }

    // Hàm xuất Excel
    function exportExcel() {
        try {
            const reportType = document.querySelector('select[name="type"]').value;
            const departmentId = document.querySelector('select[name="department_id"]').value || '';
            const positionId = document.querySelector('select[name="position_id"]').value || '';
            const fromDate = document.querySelector('input[name="from_date"]').value || '';
            const toDate = document.querySelector('input[name="to_date"]').value || '';
            const status = document.querySelector('select[name="status"]').value || '';
            const explanation = document.querySelector('select[name="explanation"]').value || '';

            const params = new URLSearchParams({
                type: reportType,
                department_id: departmentId,
                position_id: positionId,
                from_date: fromDate,
                to_date: toDate,
                status: status,
                explanation: explanation
            });

            const url = `export_excel.php?${params.toString()}`;
            window.location.href = url;
        } catch (error) {
            console.error('Lỗi khi xuất Excel:', error);
            alert('Đã xảy ra lỗi khi xuất Excel. Vui lòng thử lại!');
        }
    }

    // Hàm xuất PDF
    function exportPDF() {
        try {
            const reportType = document.querySelector('select[name="type"]').value;
            const departmentId = document.querySelector('select[name="department_id"]').value || '';
            const positionId = document.querySelector('select[name="position_id"]').value || '';
            const fromDate = document.querySelector('input[name="from_date"]').value || '';
            const toDate = document.querySelector('input[name="to_date"]').value || '';
            const status = document.querySelector('select[name="status"]').value || '';
            const explanation = document.querySelector('select[name="explanation"]').value || '';

            const params = new URLSearchParams({
                type: reportType,
                department_id: departmentId,
                position_id: positionId,
                from_date: fromDate,
                to_date: toDate,
                status: status,
                explanation: explanation
            });

            const url = `export_pdf.php?${params.toString()}`;
            window.location.href = url;
        } catch (error) {
            console.error('Lỗi khi xuất PDF:', error);
            alert('Đã xảy ra lỗi khi xuất PDF. Vui lòng thử lại!');
        }
    }

    // Khởi tạo khi trang được tải
    document.addEventListener('DOMContentLoaded', function() {
        try {
            const typeSelect = document.querySelector('select[name="type"]');
            if (!typeSelect) {
                throw new Error('Không tìm thấy trường chọn loại báo cáo');
            }
            
            updateFormFields();
            typeSelect.addEventListener('change', updateFormFields);
        } catch (error) {
            console.error('Lỗi khi khởi tạo form:', error);
        }
    });
</script>
</body>
</html>