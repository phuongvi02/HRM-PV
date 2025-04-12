<?php
require_once __DIR__ . "/../../core/Database.php";

require_once(__DIR__ . '/../../vendor/autoload.php');


use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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
        'address' => 'Lục Ngan ',
        'phone' => '0562044109 ',
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

// Hàm lấy dữ liệu báo cáo theo loại
function getReportData($db, $report_type, $params) {
    $data = [];
    
    switch ($report_type) {
        case 'employees':
            // Báo cáo danh sách nhân viên
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
            
            $query .= " ORDER BY e.full_name ASC";
            
            $stmt = $db->prepare($query);
            
            if (!empty($params['department_id'])) {
                $stmt->bindParam(':department_id', $params['department_id']);
            }
            
            if (!empty($params['position_id'])) {
                $stmt->bindParam(':position_id', $params['position_id']);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'salary':
            // Báo cáo lương nhân viên
            $query = "
                SELECT e.id, e.full_name, e.employee_code, d.name as department_name, p.name as position_name,
                       c.basic_salary, c.allowance, c.insurance_rate, c.tax_rate,
                       (c.basic_salary + c.allowance) as total_salary
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN positions p ON e.position_id = p.id
                LEFT JOIN contracts c ON e.id = c.employee_id
                WHERE c.status = 'active'
            ";
            
            if (!empty($params['department_id'])) {
                $query .= " AND e.department_id = :department_id";
            }
            
            if (!empty($params['position_id'])) {
                $query .= " AND e.position_id = :position_id";
            }
            
            $query .= " ORDER BY e.full_name ASC";
            
            $stmt = $db->prepare($query);
            
            if (!empty($params['department_id'])) {
                $stmt->bindParam(':department_id', $params['department_id']);
            }
            
            if (!empty($params['position_id'])) {
                $stmt->bindParam(':position_id', $params['position_id']);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'attendance':
            // Báo cáo chấm công
            $query = "
                SELECT e.id, e.full_name, e.employee_code, d.name as department_name,
                       COUNT(a.id) as total_days,
                       SUM(CASE WHEN TIME(a.check_in) > '08:00:00' THEN 1 ELSE 0 END) as late_days,
                       SUM(CASE WHEN TIME(a.check_out) < '17:00:00' THEN 1 ELSE 0 END) as early_leave_days,
                       SUM(CASE WHEN TIME(a.check_out) > '17:00:00' THEN 1 ELSE 0 END) as overtime_days
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN attendance a ON e.id = a.employee_id
                WHERE MONTH(a.date) = :month AND YEAR(a.date) = :year
            ";
            
            if (!empty($params['department_id'])) {
                $query .= " AND e.department_id = :department_id";
            }
            
            $query .= " GROUP BY e.id, e.full_name, e.employee_code, d.name ORDER BY e.full_name ASC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':month', $params['month']);
            $stmt->bindParam(':year', $params['year']);
            
            if (!empty($params['department_id'])) {
                $stmt->bindParam(':department_id', $params['department_id']);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'late_employees':
            // Báo cáo nhân viên đi muộn
            $query = "
                SELECT e.id, e.full_name, e.employee_code, d.name as department_name,
                       DATE(a.date) as attendance_date, TIME(a.check_in) as check_in_time,
                       TIMEDIFF(TIME(a.check_in), '08:00:00') as late_duration
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                JOIN attendance a ON e.id = a.employee_id
                WHERE TIME(a.check_in) > '08:00:00'
                AND a.date BETWEEN :from_date AND :to_date
            ";
            
            if (!empty($params['department_id'])) {
                $query .= " AND e.department_id = :department_id";
            }
            
            $query .= " ORDER BY a.date DESC, e.full_name ASC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':from_date', $params['from_date']);
            $stmt->bindParam(':to_date', $params['to_date']);
            
            if (!empty($params['department_id'])) {
                $stmt->bindParam(':department_id', $params['department_id']);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        // Các case khác tương tự như trong file reports.php
        // ...
    }
    
    return $data;
}

// Lấy tiêu đề báo cáo
function getReportTitle($report_type) {
    $titles = [
        'employees' => 'DANH SÁCH NHÂN VIÊN',
        'salary' => 'BÁO CÁO LƯƠNG NHÂN VIÊN',
        'attendance' => 'BÁO CÁO CHẤM CÔNG THÁNG',
        'late_employees' => 'BÁO CÁO NHÂN VIÊN ĐI MUỘN',
        'new_employees' => 'DANH SÁCH NHÂN VIÊN MỚI',
        'departments' => 'BÁO CÁO PHÒNG BAN',
        'positions' => 'BÁO CÁO CHỨC VỤ',
        'leave' => 'BÁO CÁO NGHỈ PHÉP',
        'rewards' => 'BÁO CÁO THƯỞNG PHẠT',
        'salary_advances' => 'BÁO CÁO TẠM ỨNG LƯƠNG'
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
    'to_date' => $to_date
];

$report_data = getReportData($db, $report_type, $report_params);
$report_title = getReportTitle($report_type);

// Tạo file Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Thiết lập thông tin công ty
$sheet->setCellValue('A1', 'CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM');
$sheet->setCellValue('A2', 'Độc lập - Tự do - Hạnh phúc');
$sheet->setCellValue('A4', $company['name']);
$sheet->setCellValue('A5', 'Địa chỉ: ' . $company['address']);
$sheet->setCellValue('A6', 'Điện thoại: ' . $company['phone']);
$sheet->setCellValue('A7', 'Mã số thuế: ' . $company['tax_code']);

$sheet->setCellValue('F4', 'Ngày ' . date('d') . ' tháng ' . date('m') . ' năm ' . date('Y'));

// Thiết lập tiêu đề báo cáo
$sheet->setCellValue('A9', $report_title);
if ($report_type == 'attendance') {
    $sheet->setCellValue('A10', 'Tháng ' . $month . ' năm ' . $year);
} elseif (in_array($report_type, ['late_employees', 'new_employees', 'leave', 'rewards', 'salary_advances'])) {
    $sheet->setCellValue('A10', 'Từ ngày ' . date('d/m/Y', strtotime($from_date)) . ' đến ngày ' . date('d/m/Y', strtotime($to_date)));
}

// Định dạng tiêu đề
$sheet->mergeCells('A9:H9');
$sheet->mergeCells('A10:H10');
$sheet->getStyle('A9:H10')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A9')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A10')->getFont()->setSize(12);

// Thiết lập header cho từng loại báo cáo
$row = 12;
if ($report_type == 'employees') {
    $headers = ['STT', 'Mã NV', 'Họ và tên', 'Ngày sinh', 'Giới tính', 'Số điện thoại', 'Email', 'Phòng ban', 'Chức vụ', 'Ngày vào làm'];
    $sheet->fromArray($headers, NULL, 'A' . $row);
    $row++;
    
    foreach ($report_data as $index => $employee) {
        $sheet->setCellValue('A' . $row, $index + 1);
        $sheet->setCellValue('B' . $row, $employee['employee_code'] ?? 'N/A');
        $sheet->setCellValue('C' . $row, $employee['full_name']);
        $sheet->setCellValue('D' . $row, !empty($employee['birth_date']) ? date('d/m/Y', strtotime($employee['birth_date'])) : 'N/A');
        $sheet->setCellValue('E' . $row, $employee['gender'] ?? 'N/A');
        $sheet->setCellValue('F' . $row, $employee['phone'] ?? 'N/A');
        $sheet->setCellValue('G' . $row, $employee['email'] ?? 'N/A');
        $sheet->setCellValue('H' . $row, $employee['department_name'] ?? 'N/A');
        $sheet->setCellValue('I' . $row, $employee['position_name'] ?? 'N/A');
        $sheet->setCellValue('J' . $row, !empty($employee['hire_date']) ? date('d/m/Y', strtotime($employee['hire_date'])) : 'N/A');
        $row++;
    }
    
    // Định dạng cột
    $sheet->getColumnDimension('A')->setWidth(5);
    $sheet->getColumnDimension('B')->setWidth(10);
    $sheet->getColumnDimension('C')->setWidth(25);
    $sheet->getColumnDimension('D')->setWidth(15);
    $sheet->getColumnDimension('E')->setWidth(10);
    $sheet->getColumnDimension('F')->setWidth(15);
    $sheet->getColumnDimension('G')->setWidth(25);
    $sheet->getColumnDimension('H')->setWidth(20);
    $sheet->getColumnDimension('I')->setWidth(20);
    $sheet->getColumnDimension('J')->setWidth(15);
} elseif ($report_type == 'salary') {
    $headers = ['STT', 'Mã NV', 'Họ và tên', 'Phòng ban', 'Chức vụ', 'Lương cơ bản', 'Phụ cấp', 'Tỷ lệ BH', 'Tỷ lệ thuế', 'Tổng lương'];
    $sheet->fromArray($headers, NULL, 'A' . $row);
    $row++;
    
    $total_basic_salary = 0;
    $total_allowance = 0;
    $total_salary = 0;
    
    foreach ($report_data as $index => $salary) {
        $total_basic_salary += $salary['basic_salary'] ?? 0;
        $total_allowance += $salary['allowance'] ?? 0;
        $total_salary += $salary['total_salary'] ?? 0;
        
        $sheet->setCellValue('A' . $row, $index + 1);
        $sheet->setCellValue('B' . $row, $salary['employee_code'] ?? 'N/A');
        $sheet->setCellValue('C' . $row, $salary['full_name']);
        $sheet->setCellValue('D' . $row, $salary['department_name'] ?? 'N/A');
        $sheet->setCellValue('E' . $row, $salary['position_name'] ?? 'N/A');
        $sheet->setCellValue('F' . $row, $salary['basic_salary'] ?? 0);
        $sheet->setCellValue('G' . $row, $salary['allowance'] ?? 0);
        $sheet->setCellValue('H' . $row, ($salary['insurance_rate'] ?? 0) * 100 . '%');
        $sheet->setCellValue('I' . $row, ($salary['tax_rate'] ?? 0) * 100 . '%');
        $sheet->setCellValue('J' . $row, $salary['total_salary'] ?? 0);
        $row++;
    }
    
    // Thêm dòng tổng
    $sheet->setCellValue('A' . $row, 'Tổng cộng:');
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $sheet->setCellValue('F' . $row, $total_basic_salary);
    $sheet->setCellValue('G' . $row, $total_allowance);
    $sheet->setCellValue('J' . $row, $total_salary);
    $sheet->getStyle('A' . $row . ':J' . $row)->getFont()->setBold(true);
    
    // Định dạng cột
    $sheet->getColumnDimension('A')->setWidth(5);
    $sheet->getColumnDimension('B')->setWidth(10);
    $sheet->getColumnDimension('C')->setWidth(25);
    $sheet->getColumnDimension('D')->setWidth(20);
    $sheet->getColumnDimension('E')->setWidth(20);
    $sheet->getColumnDimension('F')->setWidth(15);
    $sheet->getColumnDimension('G')->setWidth(15);
    $sheet->getColumnDimension('H')->setWidth(10);
    $sheet->getColumnDimension('I')->setWidth(10);
    $sheet->getColumnDimension('J')->setWidth(15);
    
    // Định dạng số tiền
    $sheet->getStyle('F13:G' . $row)->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyle('J13:J' . $row)->getNumberFormat()->setFormatCode('#,##0');
}
// Thêm các loại báo cáo khác tương tự

// Định dạng header
$headerStyle = [
    'font' => [
        'bold' => true,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => [
            'rgb' => 'D9E1F2',
        ],
    ],
];

$sheet->getStyle('A12:J12')->applyFromArray($headerStyle);

// Định dạng dữ liệu
$dataStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
];

$sheet->getStyle('A13:J' . ($row - 1))->applyFromArray($dataStyle);

// Thiết lập footer
$row += 2;
$sheet->setCellValue('C' . $row, 'Người lập báo cáo');
$sheet->setCellValue('H' . $row, 'Giám đốc');
$sheet->getStyle('C' . $row . ':H' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row++;
$sheet->setCellValue('C' . $row, '(Ký, ghi rõ họ tên)');
$sheet->setCellValue('H' . $row, '(Ký, đóng dấu)');
$sheet->getStyle('C' . $row . ':H' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Thiết lập tên file
$filename = 'Bao_cao_' . $report_type . '_' . date('Ymd_His') . '.xlsx';

// Xuất file Excel
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>