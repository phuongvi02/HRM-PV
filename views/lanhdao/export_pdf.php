<?php
require_once __DIR__ . "/../../core/Database.php";

require_once(__DIR__ . '/../../vendor/autoload.php');


use Dompdf\Dompdf;
use Dompdf\Options;

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

// Tạo nội dung HTML cho PDF
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>' . $report_title . '</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.4;
        }
        .container {
            width: 100%;
            margin: 0 auto;
        }
        .header {
            margin-bottom: 20px;
        }
        .header-left {
            float: left;
            width: 60%;
        }
        .header-right {
            float: right;
            width: 40%;
            text-align: right;
        }
        .clearfix:after {
            content: "";
            display: table;
            clear: both;
        }
        .title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            margin: 20px 0 10px;
            text-transform: uppercase;
        }
        .subtitle {
            text-align: center;
            font-size: 14px;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #000;
            padding: 5px;
            text-align: left;
            font-size: 11px;
        }
        th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .footer {
            margin-top: 30px;
        }
        . signature-section {
            float: left;
            width: 50%;
            text-align: center;
        }
        .total-row {
            font-weight: bold;
            background-color: #f0f0f0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header clearfix">
            <div class="header-left">
                <p>CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM</p>
                <p><strong>Độc lập - Tự do - Hạnh phúc</strong></p>
                <p>' . htmlspecialchars($company['name']) . '</p>
                <p>Địa chỉ: ' . htmlspecialchars($company['address']) . '</p>
                <p>Điện thoại: ' . htmlspecialchars($company['phone']) . '</p>
                <p>Mã số thuế: ' . htmlspecialchars($company['tax_code']) . '</p>
            </div>
            <div class="header-right">
                <p>Ngày ' . date('d') . ' tháng ' . date('m') . ' năm ' . date('Y') . '</p>
            </div>
        </div>
        
        <div class="title">' . $report_title . '</div>';

// Thêm phụ đề nếu cần
if ($report_type == 'attendance') {
    $html .= '<div class="subtitle">Tháng ' . $month . ' năm ' . $year . '</div>';
} elseif (in_array($report_type, ['late_employees', 'new_employees', 'leave', 'rewards', 'salary_advances'])) {
    $html .= '<div class="subtitle">Từ ngày ' . date('d/m/Y', strtotime($from_date)) . ' đến ngày ' . date('d/m/Y', strtotime($to_date)) . '</div>';
}

// Tạo nội dung báo cáo dựa trên loại
if ($report_type == 'employees') {
    $html .= '
        <table>
            <thead>
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
                </tr>
            </thead>
            <tbody>';
    
    if (empty($report_data)) {
        $html .= '
                <tr>
                    <td colspan="10" class="text-center">Không có dữ liệu</td>
                </tr>';
    } else {
        foreach ($report_data as $index => $employee) {
            $html .= '
                <tr>
                    <td class="text-center">' . ($index + 1) . '</td>
                    <td>' . htmlspecialchars($employee['employee_code'] ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($employee['full_name']) . '</td>
                    <td>' . (!empty($employee['birth_date']) ? date('d/m/Y', strtotime($employee['birth_date'])) : 'N/A') . '</td>
                    <td>' . htmlspecialchars($employee['gender'] ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($employee['phone'] ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($employee['email'] ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($employee['department_name'] ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($employee['position_name'] ?? 'N/A') . '</td>
                    <td>' . (!empty($employee['hire_date']) ? date('d/m/Y', strtotime($employee['hire_date'])) : 'N/A') . '</td>
                </tr>';
        }
    }
    
    $html .= '
            </tbody>
        </table>';
} elseif ($report_type == 'salary') {
    $html .= '
        <table>
            <thead>
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
                </tr>
            </thead>
            <tbody>';
    
    if (empty($report_data)) {
        $html .= '
                <tr>
                    <td colspan="10" class="text-center">Không có dữ liệu</td>
                </tr>';
    } else {
        $total_basic_salary = 0;
        $total_allowance = 0;
        $total_salary = 0;
        
        foreach ($report_data as $index => $salary) {
            $total_basic_salary += $salary['basic_salary'] ?? 0;
            $total_allowance += $salary['allowance'] ?? 0;
            $total_salary += $salary['total_salary'] ?? 0;
            
            $html .= '
                <tr>
                    <td class="text-center">' . ($index + 1) . '</td>
                    <td>' . htmlspecialchars($salary['employee_code'] ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($salary['full_name']) . '</td>
                    <td>' . htmlspecialchars($salary['department_name'] ?? 'N/A') . '</td>
                    <td>' . htmlspecialchars($salary['position_name'] ?? 'N/A') . '</td>
                    <td class="text-right">' . number_format($salary['basic_salary'] ?? 0, 0, ',', '.') . ' VNĐ</td>
                    <td class="text-right">' . number_format($salary['allowance'] ?? 0, 0, ',', '.') . ' VNĐ</td>
                    <td class="text-center">' . (($salary['insurance_rate'] ?? 0) * 100) . '%</td>
                    <td class="text-center">' . (($salary['tax_rate'] ?? 0) * 100) . '%</td>
                    <td class="text-right">' . number_format($salary['total_salary'] ?? 0, 0, ',', '.') . ' VNĐ</td>
                </tr>';
        }
        
        $html .= '
                <tr class="total-row">
                    <td colspan="5" class="text-right">Tổng cộng:</td>
                    <td class="text-right">' . number_format($total_basic_salary, 0, ',', '.') . ' VNĐ</td>
                    <td class="text-right">' . number_format($total_allowance, 0, ',', '.') . ' VNĐ</td>
                    <td></td>
                    <td></td>
                    <td class="text-right">' . number_format($total_salary, 0, ',', '.') . ' VNĐ</td>
                </tr>';
    }
    
    $html .= '
            </tbody>
        </table>';
}
// Thêm các loại báo cáo khác tương tự

// Thêm phần chân trang
$html .= '
        <div class="footer clearfix">
            <div class="signature-section">
                <p>Người lập báo cáo</p>
                <p><em>(Ký, ghi rõ họ tên)</em></p>
            </div>
            <div class="signature-section">
                <p>Giám đốc</p>
                <p><em>(Ký, đóng dấu)</em></p>
            </div>
        </div>
    </div>
</body>
</html>';

// Tạo PDF
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Thiết lập tên file
$filename = 'Bao_cao_' . $report_type . '_' . date('Ymd_His') . '.pdf';

// Xuất file PDF
$dompdf->stream($filename, array('Attachment' => true));
exit;
?>