<?php
ob_start();
require_once __DIR__ . "/../../core/Database.php";
$db = Database::getInstance()->getConnection();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 5) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

$employee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($employee_id !== (int)$_SESSION['user_id']) {
    header('Location: /HRMpv/views/employee/index_employee.php?error=unauthorized_access');
    exit();
}

$stmt = $db->prepare("SELECT e.*, p.name as position_name, d.name as department_name
                      FROM employees e
                      LEFT JOIN positions p ON e.position_id = p.id
                      LEFT JOIN departments d ON e.department_id = d.id
                      WHERE e.id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: /HRMpv/views/employee/index_employee.php?error=employee_not_found');
    exit();
}

$stmt = $db->prepare("SELECT * FROM contracts WHERE employee_id = ? ORDER BY start_date DESC LIMIT 1");
$stmt->execute([$employee_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    header('Location: /HRMpv/views/employee/index_employee.php?error=no_contract_found');
    exit();
}

// Tính toán lương và khấu trừ
$bhxh = $contract['basic_salary'] * 0.08;
$bhyt = $contract['basic_salary'] * 0.015;
$bhtn = $contract['basic_salary'] * 0.01;
$totalDeductions = $bhxh + $bhyt + $bhtn;
$taxableIncome = $contract['basic_salary'] - $totalDeductions - 11000000; // Miễn thuế 11 triệu
$personalTax = $taxableIncome > 0 ? $taxableIncome * 0.05 : 0;
$netSalary = $contract['basic_salary'] + ($contract['allowance'] ?? 0) - $totalDeductions - $personalTax;

// Thông tin công ty
$company_info = [
    'name' => 'CÔNG TY TNHH PHÁT TRIỂN CÔNG NGHỆ ITIT',
    'address' => 'Lục Ngạn',
    'phone' => '0562044109',
    'email' => '20211104@eaut.edu.vn',
    'tax_code' => '666666666666',
    'website' => 'hrmpv.online'
];
$leader_name = "Nguyễn Văn Phượng Vĩ";
$leader_position = "Giám đốc";
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hợp Đồng Lao Động - <?= htmlspecialchars($employee['full_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 14px;
            line-height: 1.8;
            margin: 0;
            padding: 0;
        }
        .contract-container {
            max-width: 900px;
            margin: 20px auto;
            padding: 40px;
            border: 1px solid #000;
            position: relative;
        }
        .header {
            text-align: center;
            margin-bottom: 40px;
        }
        .header .country {
            font-size: 16px;
            text-transform: uppercase;
            font-weight: bold;
            margin: 0;
        }
        .header .motto {
            font-size: 14px;
            font-style: italic;
            text-decoration: underline;
            margin: 5px 0;
        }
        .header .title {
            font-size: 18px;
            text-transform: uppercase;
            font-weight: bold;
            margin: 20px 0 10px;
        }
        .header .contract-number {
            font-size: 14px;
            font-style: italic;
        }
        .company-info {
            position: absolute;
            top: 40px;
            left: 40px;
            font-size: 12px;
            color: #555;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h5 {
            font-size: 14px;
            text-transform: uppercase;
            font-weight: bold;
            margin-bottom: 15px;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
        }
        .section p {
            margin: 8px 0;
            text-align: justify;
        }
        .section .indent {
            margin-left: 20px;
        }
        .money {
            font-weight: bold;
            font-style: italic;
        }
        .signature {
            margin-top: 80px;
            display: flex;
            justify-content: space-between;
        }
        .signature div {
            width: 45%;
            text-align: center;
        }
        .signature .title {
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 60px;
            color: rgba(0, 0, 0, 0.1);
            pointer-events: none;
        }
        @media print {
            .contract-container {
                border: none;
                margin: 0;
            }
            .no-print {
                display: none;
            }
            .company-info {
                position: static;
                margin-bottom: 20px;
            }
            @page {
                margin: 2cm;
            }
        }
    </style>
</head>
<body>
    <div class="contract-container">
        <div class="watermark"><?= htmlspecialchars($company_info['name']) ?></div>
        
        <div class="company-info">
            <p><strong><?= htmlspecialchars($company_info['name']) ?></strong></p>
            <p>Địa chỉ: <?= htmlspecialchars($company_info['address']) ?></p>
            <p>Điện thoại: <?= htmlspecialchars($company_info['phone']) ?></p>
            <p>Email: <?= htmlspecialchars($company_info['email']) ?></p>
            <p>Website: <?= htmlspecialchars($company_info['website']) ?></p>
        </div>

        <div class="header">
            <p class="country">Cộng Hòa Xã Hội Chủ Nghĩa Việt Nam</p>
            <p class="motto">Độc lập - Tự do - Hạnh phúc</p>
            <hr style="width: 200px; margin: 15px auto;">
            <p class="title">Hợp Đồng Lao Động</p>
            <p class="contract-number">Số: <?= htmlspecialchars($contract['contract_code'] ?? $employee['id'] . '/' . date('Y')) ?>/HĐLĐ</p>
        </div>

        <div class="section">
            <p>Hôm nay, ngày <?= date('d') ?> tháng <?= date('m') ?> năm <?= date('Y') ?>, tại trụ sở <?= htmlspecialchars($company_info['name']) ?>, chúng tôi gồm có:</p>
            
            <h5>I. Bên A - Người Sử Dụng Lao Động</h5>
            <p>- Tên đơn vị: <?= htmlspecialchars($company_info['name']) ?></p>
            <p>- Địa chỉ trụ sở chính: <?= htmlspecialchars($company_info['address']) ?></p>
            <p>- Mã số thuế: <?= htmlspecialchars($company_info['tax_code']) ?></p>
            <p>- Đại diện: Ông <?= htmlspecialchars($leader_name) ?></p>
            <p>- Chức vụ: <?= htmlspecialchars($leader_position) ?></p>
            <p>- Điện thoại: <?= htmlspecialchars($company_info['phone']) ?></p>
            <p>- Email: <?= htmlspecialchars($company_info['email']) ?></p>

            <h5>II. Bên B - Người Lao Động</h5>
            <p>- Họ và tên: <?= htmlspecialchars($employee['full_name']) ?></p>
            <p>- Ngày sinh: <?= $employee['birth_date'] ? date('d/m/Y', strtotime($employee['birth_date'])) : 'Chưa cập nhật' ?></p>
            <p>- Số CMND/CCCD: <?= htmlspecialchars($employee['id_number'] ?? 'Chưa cập nhật') ?></p>
            <p>- Địa chỉ thường trú: <?= htmlspecialchars($employee['address'] ?? 'Chưa cập nhật') ?></p>
            <p>- Chức danh: <?= htmlspecialchars($employee['position_name']) ?></p>
            <p>- Phòng ban: <?= htmlspecialchars($employee['department_name']) ?></p>
        </div>

        <div class="section">
            <p>Hai bên cùng thỏa thuận ký kết Hợp đồng lao động với các điều khoản sau:</p>
            
            <h5>Điều 1: Công Việc, Thời Hạn và Địa Điểm Làm Việc</h5>
            <p>1. Loại hợp đồng lao động: <?= htmlspecialchars($contract['contract_type']) ?></p>
            <p>2. Thời hạn hợp đồng: Từ ngày <?= date('d/m/Y', strtotime($contract['start_date'])) ?> 
                <?= $contract['end_date'] ? 'đến ngày ' . date('d/m/Y', strtotime($contract['end_date'])) : 'không xác định thời hạn' ?></p>
            <p>3. Công việc đảm nhiệm: <?= htmlspecialchars($employee['position_name']) ?> tại <?= htmlspecialchars($employee['department_name']) ?></p>
            <p>4. Địa điểm làm việc: <?= htmlspecialchars($company_info['address']) ?></p>
            <p>5. Nhiệm vụ cụ thể: <?= htmlspecialchars($contract['job_description'] ?? 'Thực hiện các công việc theo sự phân công của Ban lãnh đạo') ?></p>
        </div>

        <div class="section">
            <h5>Điều 2: Chế Độ Làm Việc</h5>
            <p>1. Thời gian làm việc: <?= htmlspecialchars($contract['work_time'] ?? '8 giờ/ngày, từ 08:00 đến 17:00, nghỉ trưa 1 giờ') ?></p>
            <p>2. Trang thiết bị làm việc: Được cấp phát theo quy định nội bộ công ty</p>
            <p>3. Mức lương chính: <span class="money"><?= number_format($contract['basic_salary'], 0, ',', '.') ?> VNĐ/tháng</span></p>
            <p>4. Phụ cấp: <span class="money"><?= number_format($contract['allowance'] ?? 0, 0, ',', '.') ?> VNĐ/tháng</span></p>
            <p>5. Các khoản khấu trừ bắt buộc:</p>
            <p class="indent">- Bảo hiểm xã hội (8%): <span class="money"><?= number_format($bhxh, 0, ',', '.') ?> VNĐ</span></p>
            <p class="indent">- Bảo hiểm y tế (1,5%): <span class="money"><?= number_format($bhyt, 0, ',', '.') ?> VNĐ</span></p>
            <p class="indent">- Bảo hiểm thất nghiệp (1%): <span class="money"><?= number_format($bhtn, 0, ',', '.') ?> VNĐ</span></p>
            <p class="indent">- Thuế thu nhập cá nhân: <span class="money"><?= number_format($personalTax, 0, ',', '.') ?> VNĐ</span></p>
            <p>6. Lương thực lãnh: <span class="money"><?= number_format($netSalary, 0, ',', '.') ?> VNĐ/tháng</span></p>
            <p>7. Phương thức thanh toán: Chuyển khoản qua ngân hàng, ngày 5 hàng tháng</p>
        </div>

        <div class="section">
            <h5>Điều 3: Quyền Lợi và Nghĩa Vụ của Người Lao Động</h5>
            <p>1. Quyền lợi:</p>
            <p class="indent">- Được hưởng đầy đủ các chế độ bảo hiểm xã hội, bảo hiểm y tế và bảo hiểm thất nghiệp theo quy định của pháp luật</p>
            <p class="indent">- Nghỉ phép năm: 12 ngày làm việc/năm, được hưởng nguyên lương</p>
            <p class="indent">- Được đào tạo, bồi dưỡng nâng cao trình độ chuyên môn nghiệp vụ</p>
            <p>2. Nghĩa vụ:</p>
            <p class="indent">- Chấp hành nghiêm chỉnh nội quy, quy định và kỷ luật lao động của công ty</p>
            <p class="indent">- Hoàn thành công việc được giao theo đúng thời hạn và chất lượng</p>
            <p class="indent">- Bảo vệ tài sản và bí mật kinh doanh của công ty</p>
        </div>

        <div class="section">
            <h5>Điều 4: Điều Kiện Chấm Dứt Hợp Đồng</h5>
            <p>- Hợp đồng chấm dứt khi hết thời hạn (nếu có thời hạn xác định)</p>
            <p>- Hai bên có quyền đơn phương chấm dứt hợp đồng theo quy định của Bộ luật Lao động</p>
            <p>- Các trường hợp khác theo thỏa thuận hoặc quy định pháp luật</p>
        </div>

        <div class="section">
            <p>Hợp đồng này được lập thành 02 bản có giá trị pháp lý như nhau, mỗi bên giữ 01 bản và có hiệu lực kể từ ngày ký.</p>
        </div>

        <div class="signature">
            <div>
                <p class="title">Đại Diện Bên A</p>
                <p>Đã ký và ghi rõ họ tên</p>
                <br><br><br><br>
                <p><?= htmlspecialchars($leader_name) ?></p>
            </div>
            <div>
                <p class="title">Bên B</p>
                <p>Đã ký và ghi rõ họ tên</p>
                <br><br><br><br>
                <p><?= htmlspecialchars($employee['full_name']) ?></p>
            </div>
        </div>

        <div class="text-center no-print">
            <button onclick="window.print()" class="btn btn-primary mt-4">
                <i class="fas fa-print mr-1"></i> In Hợp Đồng
            </button>
            <a href="/HRMpv/views/employee/index_employee.php" class="btn btn-secondary mt-4">
                <i class="fas fa-arrow-left mr-1"></i> Quay Lại
            </a>
        </div>
    </div>

    <script>
        window.onafterprint = function() {
            window.location.href = '/HRMpv/views/employee/index_employee.php';
        };
    </script>
</body>
</html>

<?php
ob_end_flush();
?>