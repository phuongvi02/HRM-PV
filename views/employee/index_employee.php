<?php
ob_start(); // Start output buffering
require_once __DIR__ . "/../../core/Database.php";
require_once '../layouts/header_employee.php';
require_once '../layouts/sidebar_employee.php';
require_once '../layouts/navbar_employee.php';
require_once __DIR__ . "/../../core/ChatBot.php";
require_once __DIR__ . '/../../vendor/autoload.php';

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

// Hàm upload ảnh lên Google Drive
function uploadToGoogleDrive($filePath, $fileName, $folderId = null) {
    $jsonPath = 'C:/xampp/htdocs/HRMpv/hrm2003-057461bf62af.json';
    error_log("Đường dẫn JSON: $jsonPath");

    if (!file_exists($jsonPath) || !is_readable($jsonPath)) {
        $errorMsg = "Lỗi: File JSON không tồn tại hoặc không đọc được tại $jsonPath";
        error_log($errorMsg);
        return $errorMsg;
    }

    try {
        $client = new Client();
        $client->setAuthConfig($jsonPath);
        $client->setScopes([Drive::DRIVE]);
        error_log("Khởi tạo Google Client thành công");

        $service = new Drive($client);
        error_log("Khởi tạo Google Drive Service thành công");

        $file = new DriveFile();
        $file->setName($fileName);

        if ($folderId) {
            $file->setParents([$folderId]);
            error_log("Đã đặt thư mục đích: $folderId");
        }

        if (!file_exists($filePath)) {
            $errorMsg = "Lỗi: File tạm không tồn tại tại $filePath";
            error_log($errorMsg);
            return $errorMsg;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            $errorMsg = "Lỗi: Không thể đọc nội dung file tại $filePath";
            error_log($errorMsg);
            return $errorMsg;
        }
        error_log("Đọc file thành công, kích thước: " . strlen($content) . " bytes");

        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        error_log("MIME type: $mimeType");

        $uploadedFile = $service->files->create($file, [
            'data' => $content,
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id'
        ]);
        error_log("Tải file lên thành công, File ID: " . $uploadedFile->id);

        $permission = new Drive\Permission();
        $permission->setType('anyone');
        $permission->setRole('reader');
        $service->permissions->create($uploadedFile->id, $permission);
        error_log("Đã thiết lập quyền công khai cho file ID: " . $uploadedFile->id);

        return $uploadedFile->id;
    } catch (Exception $e) {
        $errorMsg = "Lỗi khi tải lên Google Drive: " . $e->getMessage() . " (Code: " . $e->getCode() . ")";
        error_log($errorMsg);
        return $errorMsg;
    }
}
$db = Database::getInstance()->getConnection();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 5) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

// Lấy ID nhân viên từ session thay vì GET
$employee_id = $_SESSION['user_id'];

// Truy vấn thông tin nhân viên
$stmt = $db->prepare("SELECT e.*, p.name as position_name, d.name as department_name
                      FROM employees e
                      LEFT JOIN positions p ON e.position_id = p.id
                      LEFT JOIN departments d ON e.department_id = d.id
                      WHERE e.id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    // Nếu không tìm thấy nhân viên, có thể là lỗi dữ liệu, chuyển về đăng xuất
    session_destroy();
    header("Location: /HRMpv/views/auth/login.php?error=employee_not_found");
    exit();
}

// Xác định màu sắc dựa trên giới tính
$gender = strtolower($employee['gender'] ?? 'khác'); // Chuyển về chữ thường để dễ xử lý
$genderColor = '#f0f0f0'; // Màu mặc định (trung tính)
$genderTextColor = '#333'; // Màu chữ mặc định

if ($gender === 'nữ') {
    $genderColor = '#ffe6f0'; // Màu hồng nhạt cho nữ
    $genderTextColor = '#d63384'; // Màu chữ hồng đậm
} elseif ($gender === 'nam') {
    $genderColor = '#e6f0ff'; // Màu xanh lam nhạt cho nam
    $genderTextColor = '#0a58ca'; // Màu chữ xanh lam đậm
}

// Xử lý URL avatar
$defaultAvatar = '/HRMpv/public/images/default-avatar.png';
$avatar = trim($employee['avatar'] ?? '');
$avatarUrls = [];

if (!empty($avatar)) {
    $fileId = $avatar;
    $avatarUrls = [
        'thumbnail' => "https://drive.google.com/thumbnail?id=$fileId",
        'uc' => "https://drive.google.com/uc?export=view&id=$fileId",
        'open' => "https://drive.google.com/file/d/$fileId/view",
        'original' => $avatar
    ];
} else {
    $avatarUrls = [
        'thumbnail' => $defaultAvatar,
        'uc' => $defaultAvatar,
        'open' => $defaultAvatar,
        'original' => ''
    ];
}
$employee['avatarUrls'] = $avatarUrls;

// Lấy thông tin hợp đồng nếu có
$stmt = $db->prepare("SELECT * FROM contracts WHERE employee_id = ? ORDER BY start_date DESC LIMIT 1");
$stmt->execute([$employee_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

// Lấy thông tin chấm công trong tháng hiện tại
// Lấy thông tin chấm công trong tháng hiện tại
$currentMonth = date('Y-m');
$stmt = $db->prepare("SELECT COUNT(*) as total_days, 
                             SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                             SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                             SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
                      FROM attendance 
                      WHERE employee_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?");
$stmt->execute([$employee_id, $currentMonth]);
$attendance = $stmt->fetch(PDO::FETCH_ASSOC);

// Lấy thông tin nghỉ phép
// Lấy thông tin chấm công trong tháng hiện tại
$currentMonth = date('Y-m');
$stmt = $db->prepare("SELECT COUNT(*) as total_days, 
                             SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                             SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                             SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
                      FROM attendance 
                      WHERE employee_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?");
$stmt->execute([$employee_id, $currentMonth]);
$attendance = $stmt->fetch(PDO::FETCH_ASSOC);

// Lấy lương cơ bản từ hợp đồng mới nhất còn hiệu lực
$month_start = "$currentMonth-01";
$month_end = date('Y-m-t', strtotime($month_start)); // Ngày cuối tháng
$stmt = $db->prepare("
    SELECT basic_salary, allowance 
    FROM contracts 
    WHERE employee_id = :employee_id 
    AND start_date <= :month_end 
    AND (end_date IS NULL OR end_date >= :month_start) 
    ORDER BY start_date DESC 
    LIMIT 1
");
$stmt->execute([':employee_id' => $employee_id, ':month_start' => $month_start, ':month_end' => $month_end]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

// Lấy thông số từ bảng settings
$settingsStmt = $db->query("SELECT name, value FROM settings");
$settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Thiết lập các thông số phạt
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
$stmt->execute([':employee_id' => $employee_id, ':month' => $currentMonth]);
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
$stmt->execute([':employee_id' => $employee_id, ':month' => $currentMonth]);
$absentDaysWithoutExplanation = $stmt->fetch(PDO::FETCH_ASSOC)['absent_days'];

// Tính giờ làm thêm
$stmt = $db->prepare("
    SELECT overtime_date, TIMESTAMPDIFF(HOUR, start_time, end_time) as hours 
    FROM overtime 
    WHERE employee_id = :employee_id 
    AND DATE_FORMAT(overtime_date, '%Y-%m') = :month 
    AND status = 'approved'
");
$stmt->execute([':employee_id' => $employee_id, ':month' => $currentMonth]);
$overtimeRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalOvertimePay = 0;
$fullBasicSalary = $contract['basic_salary'] ?? 0; // Lương cơ bản từ hợp đồng
$hourlyRate = $fullBasicSalary / 160; // Giả định 160 giờ/tháng
foreach ($overtimeRecords as $record) {
    $date = $record['overtime_date'];
    $hours = $record['hours'];
    $isWeekend = (date('N', strtotime($date)) >= 6);
    $isHoliday = false; // Có thể thêm bảng holidays
    $rateMultiplier = $isHoliday ? 3.0 : ($isWeekend ? 2.0 : 1.5);
    $overtimePay = $hours * $hourlyRate * $rateMultiplier;
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
$stmt->execute([':employee_code' => $employee['id'], ':month' => $currentMonth]);
$violationRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

$violationCount = 0;
$deductions = 0;
foreach ($violationRecords as $record) {
    $isViolation = ($record['deduction_status'] === 'deducted');
    if ($isViolation) {
        $violationCount++;
    }
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
$stmt->execute([':employee_id' => $employee_id, ':month' => $currentMonth]);
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
$stmt->execute([':employee_id' => $employee_id, ':month' => $currentMonth]);
$salaryAdvance = $stmt->fetch(PDO::FETCH_ASSOC)['total_advance'] ?? 0;
$deductions += $salaryAdvance;

// Tính lương với SalaryCalculator
require_once __DIR__ . "/../../core/salary-calculate.php";
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

$error_message = '';
$success_message = '';

// Xử lý cập nhật thông tin cá nhân
// Xử lý cập nhật thông tin cá nhân
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        $phone = $_POST['phone'];
        $email = $_POST['email'];
        $address = $_POST['address'];
        $emergency_contact = $_POST['emergency_contact'] ?? null;
        $avatar_id = $employee['avatar']; // Giữ nguyên avatar hiện tại nếu không upload ảnh mới

        // Xử lý upload ảnh nếu có
        if (!empty($_FILES['avatar']['name'])) {
            if ($_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $tempPath = $_FILES['avatar']['tmp_name'];
                $fileName = basename($_FILES['avatar']['name']);
                $folderId = '1xPsdMtyABbpFKjnBnaUx7BgHBvg2YXpa'; // Thư mục trên Google Drive

                if (file_exists($tempPath)) {
                    $fileId = uploadToGoogleDrive($tempPath, $fileName, $folderId);
                    if (!str_contains($fileId, 'Lỗi')) {
                        $avatar_id = $fileId; // Cập nhật avatar_id mới
                    } else {
                        throw new Exception("Lỗi tải ảnh lên Google Drive: $fileId");
                    }
                } else {
                    throw new Exception("Lỗi: File tạm không tồn tại tại $tempPath");
                }
            } else {
                $errorCode = $_FILES['avatar']['error'];
                $errorMsg = match ($errorCode) {
                    UPLOAD_ERR_INI_SIZE => 'Kích thước file vượt quá upload_max_filesize.',
                    UPLOAD_ERR_FORM_SIZE => 'Kích thước file vượt quá MAX_FILE_SIZE.',
                    UPLOAD_ERR_PARTIAL => 'File chỉ được tải lên một phần.',
                    UPLOAD_ERR_NO_FILE => 'Không có file được chọn.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Không tìm thấy thư mục tạm.',
                    UPLOAD_ERR_CANT_WRITE => 'Không thể ghi file vào đĩa.',
                    UPLOAD_ERR_EXTENSION => 'Tải lên bị chặn bởi extension.',
                    default => 'Lỗi không xác định: ' . $errorCode,
                };
                throw new Exception("Lỗi upload file: $errorMsg");
            }
        }

        // Cập nhật thông tin nhân viên, bao gồm avatar nếu có thay đổi
        $sql = "UPDATE employees SET 
                phone = :phone, 
                email = :email, 
                address = :address, 
                emergency_contact = :emergency_contact,
                avatar = :avatar,
                updated_at = NOW()
                WHERE id = :id";

        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            ':phone' => $phone,
            ':email' => $email,
            ':address' => $address,
            ':emergency_contact' => $emergency_contact,
            ':avatar' => $avatar_id,
            ':id' => $employee_id
        ]);

        if ($result) {
            $success_message = "Cập nhật thông tin thành công!";
            // Cập nhật lại thông tin nhân viên
            $stmt = $db->prepare("SELECT e.*, p.name as position_name, d.name as department_name
                                FROM employees e
                                LEFT JOIN positions p ON e.position_id = p.id
                                LEFT JOIN departments d ON e.department_id = d.id
                                WHERE e.id = ?");
            $stmt->execute([$employee_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            // Cập nhật lại avatarUrls nếu avatar thay đổi
            $avatar = trim($employee['avatar'] ?? '');
            $avatarUrls = [];
            if (!empty($avatar)) {
                $fileId = $avatar;
                $avatarUrls = [
                    'thumbnail' => "https://drive.google.com/thumbnail?id=$fileId",
                    'uc' => "https://drive.google.com/uc?export=view&id=$fileId",
                    'open' => "https://drive.google.com/file/d/$fileId/view",
                    'original' => $avatar
                ];
            } else {
                $avatarUrls = [
                    'thumbnail' => $defaultAvatar,
                    'uc' => $defaultAvatar,
                    'open' => $defaultAvatar,
                    'original' => ''
                ];
            }
            $employee['avatarUrls'] = $avatarUrls;
        } else {
            $error_message = "Không thể cập nhật thông tin!";
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Xử lý đổi mật khẩu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Kiểm tra mật khẩu hiện tại
        $stmt = $db->prepare("SELECT password FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || !password_verify($current_password, $result['password'])) {
            throw new Exception("Mật khẩu hiện tại không đúng!");
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception("Mật khẩu mới không khớp!");
        }
        
        // Cập nhật mật khẩu mới
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $sql = "UPDATE employees SET password = :password WHERE id = :id";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute([
            ':password' => $hashed_password,
            ':id' => $employee_id
        ]);
        
        if ($result) {
            $success_message = "Đổi mật khẩu thành công!";
        } else {
            $error_message = "Không thể đổi mật khẩu!";
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HRM Nhân Viên</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/HRMpv/public/css/index_nv.css">
    <style> /* CSS động dựa trên giới tính */
.profile-container {
    background-color: <?= $genderColor ?>;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-left: 250px; /* Đảm bảo không bị che bởi sidebar */
    transition: margin-left 0.3s ease;
}

/* Profile Header */
.profile-header {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
    padding: 15px;
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 20px;
    border: 3px solid <?= $genderColor ?>;
}

.profile-info h2 {
    color: <?= $genderTextColor ?>;
    margin-bottom: 10px;
}

.profile-info p {
    margin: 5px 0;
    color: #555;
}

/* Profile Tabs */
.profile-tabs .nav-link {
    color: #555;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 10px 20px;
}

.profile-tabs .nav-link:hover {
    color: <?= $genderTextColor ?>;
    border-bottom: 2px solid <?= $genderTextColor ?>;
}

.profile-tabs .nav-link.active {
    color: <?= $genderTextColor ?>;
    border-bottom: 2px solid <?= $genderTextColor ?>;
    background-color: transparent;
}

/* Info Card */
.info-card {
    background: #fff;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.info-card h5 {
    color: <?= $genderTextColor ?>;
    margin-bottom: 15px;
}

.info-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}

.info-item:last-child {
    border-bottom: none;
}

.info-item .label {
    font-weight: 500;
    color: #555;
}

.info-item .value {
    color: #333;
}

/* Stats Card */
.stats-card {
    background: #fff;
    padding: 15px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.stats-item {
    text-align: center;
}

.stats-item .number {
    font-size: 1.5rem;
    font-weight: bold;
    color: <?= $genderTextColor ?>;
}

.stats-item .label {
    color: #666;
}
</style>
</head>
<body class="bg-light">
    <div class="profile-container">
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
        <?php endif; ?>

        <div class="profile-header">
            <img src="<?= htmlspecialchars($employee['avatarUrls']['thumbnail']) ?>" 
                 alt="Avatar của <?= htmlspecialchars($employee['full_name']) ?>" 
                 class="profile-avatar"
                 onerror="this.src='<?= $defaultAvatar ?>';">
            
            <div class="profile-info">
                <h2><?= htmlspecialchars($employee['full_name']) ?></h2>
                <p><i class="fas fa-briefcase mr-2"></i> <?= htmlspecialchars($employee['position_name']) ?></p>
                <p><i class="fas fa-building mr-2"></i> <?= htmlspecialchars($employee['department_name']) ?></p>
                <p><i class="fas fa-envelope mr-2"></i> <?= htmlspecialchars($employee['email']) ?></p>
                <p><i class="fas fa-phone mr-2"></i> <?= htmlspecialchars($employee['phone']) ?></p>
                
                <div class="mt-3">
                    <?php if ($contract): ?>
                        <span class="badge bg-success">Đang làm việc</span>
                    <?php else: ?>
                        <span class="badge bg-warning">Chưa có hợp đồng</span>
                    <?php endif; ?>
                    
                    <span class="badge bg-info">ID: <?= htmlspecialchars($employee['id']) ?></span>
                    
                    <?php if ($employee['hire_date']): ?>
                        <span class="badge bg-secondary">
                            Ngày vào làm: <?= date('d/m/Y', strtotime($employee['hire_date'])) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs profile-tabs" id="profileTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" 
                        type="button" role="tab" aria-controls="info" aria-selected="true">
                    <i class="fas fa-user mr-2"></i> Thông tin cá nhân
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="contract-tab" data-bs-toggle="tab" data-bs-target="#contract" 
                        type="button" role="tab" aria-controls="contract" aria-selected="false">
                    <i class="fas fa-file-contract mr-2"></i> Hợp đồng
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="salary-tab" data-bs-toggle="tab" data-bs-target="#salary" 
                        type="button" role="tab" aria-controls="salary" aria-selected="false">
                    <i class="fas fa-money-bill-wave mr-2"></i> Lương & Phúc lợi
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance" 
                        type="button" role="tab" aria-controls="attendance" aria-selected="false">
                    <i class="fas fa-calendar-check mr-2"></i> Chấm công & Nghỉ phép
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" 
                        type="button" role="tab" aria-controls="settings" aria-selected="false">
                    <i class="fas fa-cog mr-2"></i> Cài đặt
                </button>
            </li>
        </ul>

        <div class="tab-content profile-content" id="profileTabsContent">
            <!-- Tab Thông tin cá nhân -->
            <div class="tab-pane fade show active" id="info" role="tabpanel" aria-labelledby="info-tab">
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5><i class="fas fa-info-circle mr-2"></i> Thông tin cơ bản</h5>
                            
                            <div class="info-item">
                                <div class="label">Họ và tên</div>
                                <div class="value"><?= htmlspecialchars($employee['full_name']) ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="label">Ngày sinh</div>
                                <div class="value">
                                    <?= $employee['birth_date'] ? date('d/m/Y', strtotime($employee['birth_date'])) : 'Chưa cập nhật' ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="label">Giới tính</div>
                                <div class="value"><?= htmlspecialchars($employee['gender'] ?? 'Chưa cập nhật') ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="label">Địa chỉ</div>
                                <div class="value"><?= htmlspecialchars($employee['address'] ?? 'Chưa cập nhật') ?></div>
                            </div>
                        </div>
                        
                        <div class="info-card">
                            <h5><i class="fas fa-id-card mr-2"></i> Thông tin liên hệ</h5>
                            
                            <div class="info-item">
                                <div class="label">Email</div>
                                <div class="value"><?= htmlspecialchars($employee['email']) ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="label">Số điện thoại</div>
                                <div class="value"><?= htmlspecialchars($employee['phone'] ?? 'Chưa cập nhật') ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="label">Liên hệ khẩn cấp</div>
                                <div class="value">
                                    <?= htmlspecialchars($employee['emergency_contact'] ?? 'Chưa cập nhật') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5><i class="fas fa-briefcase mr-2"></i> Thông tin công việc</h5>
                            
                            <div class="info-item">
                                <div class="label">Mã nhân viên</div>
                                <div class="value"><?= htmlspecialchars($employee['id']) ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="label">Phòng ban</div>
                                <div class="value"><?= htmlspecialchars($employee['department_name']) ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="label">Chức vụ</div>
                                <div class="value"><?= htmlspecialchars($employee['position_name']) ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="label">Ngày vào làm</div>
                                <div class="value">
                                    <?= $employee['hire_date'] ? date('d/m/Y', strtotime($employee['hire_date'])) : 'Chưa cập nhật' ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="label">Loại hợp đồng</div>
                                <div class="value">
                                    <?= htmlspecialchars($employee['contract_type'] ?? 'Chưa có hợp đồng') ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="label">Ngày kết thúc hợp đồng</div>
                                <div class="value">
                                    <?php if (!empty($employee['contract_end_date'])): ?>
                                        <?= date('d/m/Y', strtotime($employee['contract_end_date'])) ?>
                                    <?php elseif (!empty($employee['contract_type']) && $employee['contract_type'] == 'Hợp đồng không xác định thời hạn'): ?>
                                        Không xác định
                                    <?php else: ?>
                                        Chưa cập nhật
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="stats-card">
                            <h5><i class="fas fa-chart-line mr-2"></i> Thống kê tháng <?= date('m/Y') ?></h5>
                            
                            <div class="row">
                                <div class="col-6 stats-item">
                                    <div class="number"><?= $attendance['present_days'] ?? 0 ?></div>
                                    <div class="label">Ngày làm việc</div>
                                </div>
                                
                                <div class="col-6 stats-item">
                                    <div class="number"><?= $attendance['late_days'] ?? 0 ?></div>
                                    <div class="label">Ngày đi muộn</div>
                                </div>
                                
                                <div class="col-6 stats-item">
                                    <div class="number"><?= $attendance['absent_days'] ?? 0 ?></div>
                                    <div class="label">Ngày vắng mặt</div>
                                </div>
                                
                                <div class="col-6 stats-item">
                                    <div class="number"><?= $leaves['approved_leaves'] ?? 0 ?></div>
                                    <div class="label">Ngày nghỉ phép</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tab Hợp đồng -->
          <!-- Tab Hợp đồng -->
<div class="tab-pane fade" id="contract" role="tabpanel" aria-labelledby="contract-tab">
    <?php if ($contract): ?>
        <div class="info-card">
            <h5><i class="fas fa-file-contract mr-2"></i> Thông tin hợp đồng hiện tại</h5>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="info-item">
                        <div class="label">Mã hợp đồng</div>
                        <div class="value"><?= htmlspecialchars($contract['contract_code'] ?? 'N/A') ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="label">Loại hợp đồng</div>
                        <div class="value"><?= htmlspecialchars($contract['contract_type']) ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="label">Ngày bắt đầu</div>
                        <div class="value">
                            <?= date('d/m/Y', strtotime($contract['start_date'])) ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="label">Ngày kết thúc</div>
                        <div class="value">
                            <?= $contract['end_date'] ? date('d/m/Y', strtotime($contract['end_date'])) : 'Không xác định' ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="info-item">
                        <div class="label">Lương cơ bản</div>
                        <div class="value">
                            <?= number_format($contract['basic_salary'], 0, ',', '.') ?> VNĐ
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="label">Phụ cấp</div>
                        <div class="value">
                            <?= number_format($contract['allowance'] ?? 0, 0, ',', '.') ?> VNĐ
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="label">Thời gian làm việc</div>
                        <div class="value">
                            <?= htmlspecialchars($contract['work_time'] ?? 'Không có thông tin') ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="label">Trạng thái</div>
                        <div class="value">
                            <?php
                            $status = '';
                            $statusClass = '';
                            $today = new DateTime();
                            $startDate = new DateTime($contract['start_date']);
                            $endDate = $contract['end_date'] ? new DateTime($contract['end_date']) : null;
                            
                            if ($startDate > $today) {
                                $status = 'Chưa hiệu lực';
                                $statusClass = 'text-warning';
                            } elseif (!$endDate) {
                                $status = 'Đang hiệu lực';
                                $statusClass = 'text-success';
                            } elseif ($endDate >= $today) {
                                $status = 'Đang hiệu lực';
                                $statusClass = 'text-success';
                            } else {
                                $status = 'Hết hiệu lực';
                                $statusClass = 'text-danger';
                            }
                            ?>
                            <span class="<?= $statusClass ?>"><?= $status ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($contract['job_description'])): ?>
                <div class="mt-3">
                    <div class="label">Mô tả công việc</div>
                    <div class="value">
                        <?= nl2br(htmlspecialchars($contract['job_description'])) ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($contract['notes'])): ?>
                <div class="mt-3">
                    <div class="label">Ghi chú</div>
                    <div class="value">
                        <?= nl2br(htmlspecialchars($contract['notes'])) ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="mt-4">
                <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#contractDetailModal">
                    <i class="fas fa-eye mr-1"></i> Xem chi tiết
                </button>
                <a href="../employee/print_contract.php?id=<?= $employee['id'] ?>" class="btn btn-primary">
    <i class="fas fa-print mr-1"></i> In hợp đồng
</a>
            </div>
        </div>

        <!-- Modal Chi tiết hợp đồng -->
        <div class="modal fade" id="contractDetailModal" tabindex="-1" aria-labelledby="contractDetailModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="contractDetailModalLabel">
                            Chi Tiết Hợp Đồng - <?= htmlspecialchars($employee['full_name']) ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <table class="table table-hover">
                            <tbody>
                                <tr>
                                    <td class="fw-bold">Mã Hợp Đồng</td>
                                    <td><?= htmlspecialchars($contract['contract_code'] ?? 'N/A') ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Loại Hợp Đồng</td>
                                    <td><?= htmlspecialchars($contract['contract_type']) ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Ngày Bắt Đầu</td>
                                    <td><?= date('Y-m-d', strtotime($contract['start_date'])) ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Ngày Kết Thúc</td>
                                    <td><?= $contract['end_date'] ? date('Y-m-d', strtotime($contract['end_date'])) : 'Không xác định' ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Thời Gian Làm Việc</td>
                                    <td><?= htmlspecialchars($contract['work_time'] ?? 'Không có thông tin') ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Mô Tả Công Việc</td>
                                    <td><?= htmlspecialchars($contract['job_description'] ?? 'Không có thông tin') ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Các Khoản Thu Nhập</td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold pl-4">Lương Cơ Bản</td>
                                    <td><?= number_format($contract['basic_salary'], 0, ',', '.') ?> VNĐ</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold pl-4">Phụ Cấp</td>
                                    <td><?= number_format($contract['allowance'] ?? 0, 0, ',', '.') ?> VNĐ</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Các Khoản Trừ</td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold pl-4">BHXH (8.00%)</td>
                                    <td><?= number_format($contract['basic_salary'] * 0.08, 0, ',', '.') ?> VNĐ</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold pl-4">BHYT (1.50%)</td>
                                    <td><?= number_format($contract['basic_salary'] * 0.015, 0, ',', '.') ?> VNĐ</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold pl-4">BHTN (1.00%)</td>
                                    <td><?= number_format($contract['basic_salary'] * 0.01, 0, ',', '.') ?> VNĐ</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold pl-4">Tổng Thuế Niềm</td>
                                    <td>
                                        <?php
                                        $totalDeductions = ($contract['basic_salary'] * 0.08) + ($contract['basic_salary'] * 0.015) + ($contract['basic_salary'] * 0.01);
                                        echo number_format($totalDeductions, 0, ',', '.');
                                        ?> VNĐ
                                    </td>
                                </tr>
                                <tr>
                                    <td class="fw-bold pl-4">Thuế TNCN</td>
                                    <td>
                                        <?php
                                        $taxableIncome = $contract['basic_salary'] - $totalDeductions;
                                        $personalTax = $taxableIncome * 0.05; // Giả sử thuế TNCN 5%
                                        echo number_format($personalTax, 0, ',', '.');
                                        ?> VNĐ
                                    </td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Lương Thực Nhận</td>
                                    <td class="text-success">
                                        <?php
                                        $netSalary = $contract['basic_salary'] + ($contract['allowance'] ?? 0) - $totalDeductions - $personalTax;
                                        echo number_format($netSalary, 0, ',', '.');
                                        ?> VNĐ
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="modal-footer">
                    <div class="modal-footer">
    <button type="button" class="btn btn-success" onclick="alert('Vui lòng liên hệ với bộ phận HR để tạo hợp đồng mới! 😊')">
        Tạo Hợp Đồng Mới
    </button>
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
        Đóng
    </button>
</div>

                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            Nhân viên chưa có hợp đồng. 
            <a href="../employees/create_contract.php?employee_id=<?= $employee['id'] ?>" class="alert-link">
                Tạo hợp đồng mới
            </a>
        </div>
    <?php endif; ?>
</div>
            
            <!-- Tab Lương & Phúc lợi -->
          <!-- Tab Lương & Phúc lợi -->
<div class="tab-pane fade" id="salary" role="tabpanel" aria-labelledby="salary-tab">
    <div class="row">
        <div class="col-md-6">
            <div class="info-card">
                <h5><i class="fas fa-money-bill-wave mr-2"></i> Thông tin lương</h5>
                
                <div class="info-item">
                    <div class="label">Lương cơ bản (Tháng)</div>
                    <div class="value">
                        <?= $fullBasicSalary > 0 ? number_format($fullBasicSalary, 0, ',', '.') . ' VNĐ' : 'Chưa cập nhật' ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">Phụ cấp</div>
                    <div class="value">
                        <?= isset($contract['allowance']) ? number_format($contract['allowance'], 0, ',', '.') . ' VNĐ' : '0 VNĐ' ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">Số ngày làm việc</div>
                    <div class="value">
                        <?= $attendanceDays ?>/25
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">Lương thực tế (Chấm công)</div>
                    <div class="value">
                        <?= $basicSalary > 0 ? number_format($basicSalary, 0, ',', '.') . ' VNĐ' : 'Chưa cập nhật' ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">BHXH (<?= $settings['bhxh_rate'] ?? 8 ?>%)</div>
                    <div class="value">
                        <?= $salaryDetails['bhxh'] > 0 ? number_format($salaryDetails['bhxh'], 0, ',', '.') . ' VNĐ' : 'Chưa cập nhật' ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">BHYT (<?= $settings['bhyt_rate'] ?? 1.5 ?>%)</div>
                    <div class="value">
                        <?= $salaryDetails['bhyt'] > 0 ? number_format($salaryDetails['bhyt'], 0, ',', '.') . ' VNĐ' : 'Chưa cập nhật' ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">BHTN (<?= $settings['bhtn_rate'] ?? 1 ?>%)</div>
                    <div class="value">
                        <?= $salaryDetails['bhtn'] > 0 ? number_format($salaryDetails['bhtn'], 0, ',', '.') . ' VNĐ' : 'Chưa cập nhật' ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">Thuế TNCN</div>
                    <div class="value">
                        <?= $salaryDetails['income_tax'] > 0 ? number_format($salaryDetails['income_tax'], 0, ',', '.') . ' VNĐ' : 'Chưa cập nhật' ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">Thưởng</div>
                    <div class="value">
                        <?= $bonuses > 0 ? number_format($bonuses, 0, ',', '.') . ' VNĐ' : '0 VNĐ' ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">Phạt (Vi phạm + Nghỉ không giải trình)</div>
                    <div class="value">
                        <?= ($deductions - $salaryAdvance) > 0 ? number_format($deductions - $salaryAdvance, 0, ',', '.') . ' VNĐ' : '0 VNĐ' ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">Lương ứng</div>
                    <div class="value">
                        <?= $salaryAdvance > 0 ? number_format($salaryAdvance, 0, ',', '.') . ' VNĐ' : '0 VNĐ' ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">Lương làm thêm</div>
                    <div class="value">
                        <?= $totalOvertimePay > 0 ? number_format($totalOvertimePay, 0, ',', '.') . ' VNĐ' : '0 VNĐ' ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="label">Lương thực nhận</div>
                    <div class="value fw-bold text-success">
                        <?= $salaryDetails['net_salary'] > 0 ? number_format($salaryDetails['net_salary'], 0, ',', '.') . ' VNĐ' : 'Chưa cập nhật' ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="info-card">
                <h5><i class="fas fa-chart-line mr-2"></i> Biểu đồ lương</h5>
                <div class="text-center">
                    <p class="text-muted">Biểu đồ lương sẽ được hiển thị ở đây</p>
                    <!-- Placeholder cho biểu đồ lương -->
                    <div style="height: 200px; background-color: #f8f9fa; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-chart-bar fa-3x text-muted"></i>
                    </div>
                </div>
            </div>
            
            <div class="info-card mt-4">
                <h5><i class="fas fa-gift mr-2"></i> Phúc lợi</h5>
                
                <ul class="list-group">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Bảo hiểm xã hội
                        <span class="badge bg-primary rounded-pill">Có</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Bảo hiểm y tế
                        <span class="badge bg-primary rounded-pill">Có</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Bảo hiểm thất nghiệp
                        <span class="badge bg-primary rounded-pill">Có</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Nghỉ phép năm
                        <span class="badge bg-primary rounded-pill">12 ngày</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Thưởng lễ, Tết
                        <span class="badge bg-primary rounded-pill">Có</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    
    <div class="mt-4">
        <a href="../employee/salary-history.php?id=<?= $employee['id'] ?>" class="btn btn-primary">
            <i class="fas fa-calculator mr-1"></i> Tính lương chi tiết
        </a>
    </div>
</div>
            
            <!-- Tab Chấm công & Nghỉ phép -->
            <div class="tab-pane fade" id="attendance" role="tabpanel" aria-labelledby="attendance-tab">
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5><i class="fas fa-calendar-check mr-2"></i> Thống kê chấm công tháng <?= date('m/Y') ?></h5>
                            
                            <div class="row text-center">
                                <div class="col-6 mb-3">
                                    <div class="p-3 bg-success text-white rounded">
                                        <h3><?= $attendance['present_days'] ?? 0 ?></h3>
                                        <div>Ngày làm việc</div>
                                    </div>
                                </div>
                                
                                <div class="col-6 mb-3">
                                    <div class="p-3 bg-warning text-white rounded">
                                        <h3><?= $attendance['late_days'] ?? 0 ?></h3>
                                        <div>Ngày đi muộn</div>
                                    </div>
                                </div>
                                
                                <div class="col-6 mb-3">
                                    <div class="p-3 bg-danger text-white rounded">
                                        <h3><?= $attendance['absent_days'] ?? 0 ?></h3>
                                        <div>Ngày vắng mặt</div>
                                    </div>
                                </div>
                                
                                <div class="col-6 mb-3">
                                    <div class="p-3 bg-info text-white rounded">
                                        <h3><?= $attendance['total_days'] ?? 0 ?></h3>
                                        <div>Tổng số ngày</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <a href="../employee/checkout.php?id=<?= $employee['id'] ?>" class="btn btn-primary">
                                    <i class="fas fa-history mr-1"></i> Xem lịch sử chấm công
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5><i class="fas fa-umbrella-beach mr-2"></i> Thống kê nghỉ phép năm <?= date('Y') ?></h5>
                            
                            <div class="row text-center">
                                <div class="col-6 mb-3">
                                    <div class="p-3 bg-success text-white rounded">
                                        <h3><?= $leaves['approved_leaves'] ?? 0 ?></h3>
                                        <div>Đã duyệt</div>
                                    </div>
                                </div>
                                
                                <div class="col-6 mb-3">
                                    <div class="p-3 bg-warning text-white rounded">
                                        <h3><?= $leaves['pending_leaves'] ?? 0 ?></h3>
                                        <div>Đang chờ duyệt</div>
                                    </div>
                                </div>
                                
                                <div class="col-6 mb-3">
                                    <div class="p-3 bg-danger text-white rounded">
                                        <h3><?= $leaves['rejected_leaves'] ?? 0 ?></h3>
                                        <div>Đã từ chối</div>
                                    </div>
                                </div>
                                
                                <div class="col-6 mb-3">
                                    <div class="p-3 bg-info text-white rounded">
                                        <h3><?= $leaves['total_leaves'] ?? 0 ?></h3>
                                        <div>Tổng số đơn</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <a href="../employee/leave-request.php?id=<?= $employee['id'] ?>" class="btn btn-primary">
                                    <i class="fas fa-history mr-1"></i> Xem lịch sử nghỉ phép
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Lịch sử chấm công gần đây -->
                <?php
                $stmt = $db->prepare("SELECT * FROM attendance 
                                     WHERE employee_id = ? 
                                     ORDER BY date DESC LIMIT 5");
                $stmt->execute([$employee_id]);
                $recentAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($recentAttendance)):
                ?>
                    <div class="info-card mt-4">
                        <h5><i class="fas fa-history mr-2"></i> Lịch sử chấm công gần đây</h5>
                        
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Ngày</th>
                                    <th>Giờ vào</th>
                                    <th>Giờ ra</th>
                                    <th>Trạng thái</th>
                                    <th>Ghi chú</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentAttendance as $record): ?>
                                    <tr>
                                        <td><?= date('d/m/Y', strtotime($record['date'])) ?></td>
                                        <td><?= $record['check_in'] ?? 'N/A' ?></td>
                                        <td><?= $record['check_out'] ?? 'N/A' ?></td>
                                        <td>
                                            <?php
                                            $statusClass = '';
                                            $statusText = '';
                                            
                                            switch ($record['status']) {
                                                case 'present':
                                                    $statusClass = 'text-success';
                                                    $statusText = 'Có mặt';
                                                    break;
                                                case 'absent':
                                                    $statusClass = 'text-danger';
                                                    $statusText = 'Vắng mặt';
                                                    break;
                                                case 'late':
                                                    $statusClass = 'text-warning';
                                                    $statusText = 'Đi muộn';
                                                    break;
                                                default:
                                                    $statusText = $record['status'];
                                            }
                                            ?>
                                            <span class="<?= $statusClass ?>"><?= $statusText ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($record['notes'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Tab Cài đặt -->
            <div class="tab-pane fade" id="settings" role="tabpanel" aria-labelledby="settings-tab">
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5><i class="fas fa-user-edit mr-2"></i> Cập nhật thông tin cá nhân</h5>
                            
                            <form method="POST" class="form-section" action="index_employee.php" enctype="multipart/form-data">
    <input type="hidden" name="update_profile" value="1">
    
    <div class="mb-3">
        <label for="avatar" class="form-label">Ảnh đại diện</label>
        <input type="file" class="form-control" id="avatar" name="avatar" accept="image/*">
        <small class="form-text text-muted">Để trống nếu không muốn thay đổi ảnh.</small>
    </div>
    
    <div class="mb-3">
        <label for="phone" class="form-label">Số điện thoại</label>
        <input type="text" class="form-control" id="phone" name="phone" 
               value="<?= htmlspecialchars($employee['phone'] ?? '') ?>">
    </div>
    
    <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <input type="email" class="form-control" id="email" name="email" 
               value="<?= htmlspecialchars($employee['email'] ?? '') ?>">
    </div>
    
    <div class="mb-3">
        <label for="address" class="form-label">Địa chỉ</label>
        <textarea class="form-control" id="address" name="address" rows="2"><?= htmlspecialchars($employee['address'] ?? '') ?></textarea>
    </div>
    
    <div class="mb-3">
        <label for="emergency_contact" class="form-label">Liên hệ khẩn cấp</label>
        <input type="text" class="form-control" id="emergency_contact" name="emergency_contact" 
               value="<?= htmlspecialchars($employee['emergency_contact'] ?? '') ?>"
               placeholder="Tên người liên hệ - Số điện thoại">
    </div>
    
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-save mr-1"></i> Lưu thay đổi
    </button>
</form>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5><i class="fas fa-lock mr-2"></i> Đổi mật khẩu</h5>
                            
                            <form method="POST" class="form-section" action="index_employee.php">
                                <input type="hidden" name="change_password" value="1">
                                
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Mật khẩu hiện tại</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">Mật khẩu mới</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Xác nhận mật khẩu mới</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-key mr-1"></i> Đổi mật khẩu
                                </button>
                            </form>
                        </div>
                        
                        <div class="info-card mt-4">
                            <h5><i class="fas fa-bell mr-2"></i> Cài đặt thông báo</h5>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailNotifications" checked>
                                <label class="form-check-label" for="emailNotifications">Nhận thông báo qua email</label>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="smsNotifications">
                                <label class="form-check-label" for="smsNotifications">Nhận thông báo qua SMS</label>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="attendanceReminders" checked>
                                <label class="form-check-label" for="attendanceReminders">Nhắc nhở chấm công</label>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="leaveApprovals" checked>
                                <label class="form-check-label" for="leaveApprovals">Thông báo phê duyệt nghỉ phép</label>
                            </div>
                            
                            <button type="button" class="btn btn-primary">
                                <i class="fas fa-save mr-1"></i> Lưu cài đặt
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Chatbot Button -->
    <button class="chatbot-btn" title="Chat với trợ lý AI">
        <i class="fas fa-robot"></i>
    </button>

    <!-- Chatbot Container -->
    <div class="chatbot-container" id="chatbotContainer">
        <div class="chatbot-header">
            <h5><i class="fas fa-robot mr-2"></i> Trợ lý AI</h5>
            <button class="close-btn" id="closeChatbot">×</button>
        </div>
        <div class="chatbot-body" id="chatbotBody">
            <div class="chatbot-message bot">
                <span>Xin chào! Tôi là trợ lý AI, sẵn sàng giúp bạn. Bạn khỏe không?</span>
            </div>
        </div>
        <div class="chatbot-footer">
            <input type="text" id="chatbotInput" placeholder="Nhập câu hỏi của bạn...">
            <button id="chatbotSend">Gửi</button>
        </div>
    </div>

    <!-- Bootstrap JS and Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.10.2/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Kích hoạt các tab
        var triggerTabList = [].slice.call(document.querySelectorAll('#profileTabs button'));
        triggerTabList.forEach(function(triggerEl) {
            var tabTrigger = new bootstrap.Tab(triggerEl);
            
            triggerEl.addEventListener('click', function(event) {
                event.preventDefault();
                tabTrigger.show();
            });
        });
        
        // Xử lý form đổi mật khẩu
        const passwordForm = document.querySelector('form[action="index_employee.php"][method="POST"]');
        if (passwordForm) {
            passwordForm.addEventListener('submit', function(event) {
                const newPassword = document.getElementById('new_password')?.value;
                const confirmPassword = document.getElementById('confirm_password')?.value;
                
                if (newPassword && confirmPassword && newPassword !== confirmPassword) {
                    event.preventDefault();
                    alert('Mật khẩu mới và xác nhận mật khẩu không khớp!');
                }
            });
        }
        
        // Lưu cài đặt thông báo
        // const saveSettingsBtn = document.querySelector('.info-card:last-child .btn-primary');
        // if (saveSettingsBtn) {
        //     saveSettingsBtn.addEventListener('click', function() {
        //         alert('Đã lưu cài đặt thông báo!');
        //     });
        // }

        // Chatbot Logic
        const chatbotBtn = document.querySelector('.chatbot-btn');
        const chatbotContainer = document.getElementById('chatbotContainer');
        const closeChatbot = document.getElementById('closeChatbot');
        const chatbotBody = document.getElementById('chatbotBody');
        const chatbotInput = document.getElementById('chatbotInput');
        const chatbotSend = document.getElementById('chatbotSend');

        // Mở/đóng chatbot
        chatbotBtn.addEventListener('click', function() {
            chatbotContainer.style.display = 'block';
        });

        closeChatbot.addEventListener('click', function() {
            chatbotContainer.style.display = 'none';
        });

        // Gửi tin nhắn
        function sendMessage() {
            const question = chatbotInput.value.trim();
            if (!question) return;

            // Hiển thị tin nhắn người dùng
            const userMessage = document.createElement('div');
            userMessage.className = 'chatbot-message user';
            userMessage.innerHTML = `<span>${question}</span>`;
            chatbotBody.appendChild(userMessage);
            chatbotInput.value = '';

            // Cuộn xuống dưới cùng
            chatbotBody.scrollTop = chatbotBody.scrollHeight;

            // Gửi yêu cầu AJAX
            console.log('Sending question:', question);
            fetch('/HRMpv/views/employee/chatbot_handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ question: question })
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                const botMessage = document.createElement('div');
                botMessage.className = 'chatbot-message bot';
                if (data.status === 'error') {
                    botMessage.innerHTML = `<span>Lỗi: ${data.response.error}</span>`;
                } else {
                    botMessage.innerHTML = `<span>${data.response.answer}</span>`;
                }
                chatbotBody.appendChild(botMessage);
                chatbotBody.scrollTop = chatbotBody.scrollHeight;
            })
            .catch(error => {
                console.error('Fetch error:', error);
                const botMessage = document.createElement('div');
                botMessage.className = 'chatbot-message bot';
                botMessage.innerHTML = `<span>Lỗi kết nối: ${error.message}</span>`;
                chatbotBody.appendChild(botMessage);
                chatbotBody.scrollTop = chatbotBody.scrollHeight;
            });
        }

        chatbotSend.addEventListener('click', sendMessage);
        chatbotInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') sendMessage();
        });
    });
    document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('.table-hover tbody tr');
    rows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f5f5f5';
        });
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });
});
    </script>

<?php 
ob_end_flush(); // End output buffering and send content
require_once '../layouts/footer.php'; 
?>
</body>
</html>