<?php
session_start();
session_regenerate_id(true); // Bảo mật session
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../vendor/autoload.php';

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

// Google OAuth (từ login.php)
$google_client_id = 'YOUR_GOOGLE_CLIENT_ID'; 
$google_redirect_uri = 'http://yourdomain.com/google_callback.php';
$google_auth_url = "https://accounts.google.com/o/oauth2/auth?response_type=code&client_id={$google_client_id}&redirect_uri={$google_redirect_uri}&scope=email%20profile";

// Facebook OAuth (từ login.php)
$facebook_app_id = 'YOUR_FACEBOOK_APP_ID'; 
$facebook_redirect_uri = 'http://yourdomain.com/facebook_callback.php';
$facebook_auth_url = "https://www.facebook.com/v12.0/dialog/oauth?client_id={$facebook_app_id}&redirect_uri={$facebook_redirect_uri}&scope=email";

// Hàm upload file lên Google Drive (từ job_application.php)
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

// Khởi tạo kết nối cơ sở dữ liệu
$db = Database::getInstance()->getConnection();

// Tạo bảng job_applications nếu chưa tồn tại (từ job_application.php)
$sql_create_table = "
CREATE TABLE IF NOT EXISTS `job_applications` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `fullname` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
    `email` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
    `phone` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
    `experience` text COLLATE utf8mb4_general_ci NOT NULL,
    `education` text COLLATE utf8mb4_general_ci NOT NULL,
    `position` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
    `expected_salary` decimal(10,2) NOT NULL,
    `start_date` date NOT NULL,
    `cv_path` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
    `application_date` datetime NOT NULL,
    `status` varchar(20) COLLATE utf8mb4_general_ci DEFAULT 'pending',
    PRIMARY KEY (`id`),
    UNIQUE KEY `email` (`email`) -- Thêm UNIQUE constraint để đảm bảo email không trùng
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

try {
    $db->exec($sql_create_table);
} catch (PDOException $e) {
    error_log("Lỗi khi tạo bảng job_applications: " . $e->getMessage());
}

// Lấy danh sách vị trí từ bảng positions (từ job_application.php)
try {
    $stmt = $db->query("SELECT name FROM positions");
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Lỗi khi lấy danh sách vị trí: " . $e->getMessage());
    $positions = [];
}

// Xử lý đăng nhập (từ login.php)
$error = "";
$success = "";
$warning = "";

function updateUserRole($db, $user_id) {
    $stmt = $db->prepare("SELECT role_id FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['role_id'] != $_SESSION['role_id']) {
        $_SESSION['role_id'] = $user['role_id'];
        return true;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($email) && !empty($password)) {
        try {
            $stmt_emp = $db->prepare("
                SELECT id, email, password, status, contract_end_date 
                FROM employees 
                WHERE email = :email
            ");
            $stmt_emp->execute([':email' => $email]);
            $employee = $stmt_emp->fetch(PDO::FETCH_ASSOC);

            if ($employee) {
                switch ($employee['status']) {
                    case 'active':
                        break;
                    case 'paused':
                        $error = "Tài khoản nhân viên của bạn đang bị tạm ngừng. Vui lòng liên hệ quản trị viên qua số 0562044109.";
                        break;
                    case 'expired':
                        $error = "Tài khoản nhân viên của bạn đã hết hạn. Vui lòng liên hệ quản trị viên qua số 0562044109 để gia hạn.";
                        break;
                    default:
                        $error = "Trạng thái tài khoản không hợp lệ. Vui lòng liên hệ quản trị viên.";
                        break;
                }

                if (empty($error)) {
                    $stmt_leave = $db->prepare("
                        SELECT id, start_date, end_date 
                        FROM leave_requests 
                        WHERE employee_id = :employee_id 
                        AND status = 'Đã duyệt'
                        AND CURDATE() BETWEEN start_date AND end_date
                    ");
                    $stmt_leave->execute([':employee_id' => $employee['id']]);
                    $leave = $stmt_leave->fetch(PDO::FETCH_ASSOC);

                    if ($leave) {
                        $start_date = (new DateTime($leave['start_date']))->format('d/m/Y');
                        $end_date = (new DateTime($leave['end_date']))->format('d/m/Y');
                        $error = "Bạn đang trong thời gian nghỉ phép từ $start_date đến $end_date. Không thể đăng nhập cho đến khi kỳ nghỉ kết thúc.";
                    }
                }

                if (empty($error)) {
                    $contract_end_date = $employee['contract_end_date'] ? new DateTime($employee['contract_end_date']) : null;
                    $today = new DateTime();
                    $days_left = $contract_end_date ? $today->diff($contract_end_date)->days : null;

                    if ($contract_end_date && $contract_end_date < $today) {
                        $error = "Hợp đồng của bạn đã hết hạn. Vui lòng liên hệ quản trị viên.";
                    } elseif ($contract_end_date && $days_left <= 30) {
                        $warning = "Hợp đồng của bạn sẽ hết hạn sau $days_left ngày (" . $contract_end_date->format('d/m/Y') . ").";
                    }

                    if (empty($error)) {
                        $isPasswordMatch = password_verify($password, $employee['password']) || ($password === $employee['password']);
                        
                        if ($isPasswordMatch) {
                            $stmt_user = $db->prepare("SELECT id, role_id, status FROM users WHERE email = :email");
                            $stmt_user->execute([':email' => $email]);
                            $user = $stmt_user->fetch(PDO::FETCH_ASSOC);

                            if ($user) {
                                switch ($user['status']) {
                                    case 'active':
                                        $_SESSION['user_id'] = $user['id'];
                                        $_SESSION['role_id'] = $user['role_id'];
                                        break;
                                    case 'paused':
                                        $error = "Tài khoản của bạn đang bị tạm ngừng.";
                                        break;
                                    case 'expired':
                                        $error = "Tài khoản của bạn đã hết hạn.";
                                        break;
                                    default:
                                        $error = "Trạng thái tài khoản không hợp lệ.";
                                        break;
                                }
                            } else {
                                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                $stmt_insert = $db->prepare("
                                    INSERT INTO users (id, email, password, role_id, status) 
                                    VALUES (:id, :email, :password, 5, 'active')
                                    ON DUPLICATE KEY UPDATE email = :email, password = :password, role_id = 5, status = 'active'
                                ");
                                $stmt_insert->execute([
                                    ':id' => $employee['id'],
                                    ':email' => $email,
                                    ':password' => $hashed_password
                                ]);
                                $_SESSION['user_id'] = $employee['id'];
                                $_SESSION['role_id'] = 5;
                            }
                            
                            if (empty($error)) {
                                $_SESSION['user_email'] = $email;

                                $stmt_log = $db->prepare("
                                    INSERT INTO login_history (user_id, ip_address, login_time)
                                    VALUES (:user_id, :ip_address, NOW())
                                ");
                                $stmt_log->execute([
                                    ':user_id' => $_SESSION['user_id'],
                                    ':ip_address' => $_SERVER['REMOTE_ADDR']
                                ]);

                                if (updateUserRole($db, $_SESSION['user_id'])) {
                                    $success = "Quyền của bạn đã được nâng cấp!";
                                }

                                switch ($_SESSION['role_id']) {
                                    case 1:
                                        header('Location: /HRMpv/views/admin/index_admin.php');
                                        exit();
                                    case 2:
                                        header('Location: /HRMpv/views/lanhdao/reports.php');
                                        exit();
                                    case 3:
                                        header('Location: /HRMpv/views/HR/list_employee.php');
                                        exit();
                                    case 4:
                                        header('Location: /HRMpv/views/ketoan/tinhluong.php');
                                        exit();
                                    case 5:
                                        header('Location: /HRMpv/views/employee/index_employee.php?id=' . $_SESSION['user_id']);
                                        exit();
                                    default:
                                        $error = "Vai trò không hợp lệ!";
                                        session_destroy();
                                }
                            }
                        } else {
                            $error = "Mật khẩu không đúng!";
                        }
                    }
                }
            } else {
                $stmt_users = $db->prepare("SELECT id, email, password, role_id, status FROM users WHERE email = :email");
                $stmt_users->execute([':email' => $email]);
                $user = $stmt_users->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    switch ($user['status']) {
                        case 'active':
                            break;
                        case 'paused':
                            $error = "Tài khoản của bạn đang bị tạm ngừng. Vui lòng liên hệ quản trị viên.";
                            break;
                        case 'expired':
                            $error = "Tài khoản của bạn đã hết hạn. Vui lòng liên hệ quản trị viên.";
                            break;
                        default:
                            $error = "Trạng thái tài khoản không hợp lệ.";
                            break;
                    }

                    if (empty($error)) {
                        $isPasswordMatch = password_verify($password, $user['password']) || ($password === $user['password']);
                        
                        if ($isPasswordMatch) {
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['user_email'] = $user['email'];
                            $_SESSION['role_id'] = (int)$user['role_id'];

                            $stmt_log = $db->prepare("
                                INSERT INTO login_history (user_id, ip_address, login_time)
                                VALUES (:user_id, :ip_address, NOW())
                            ");
                            $stmt_log->execute([
                                ':user_id' => $user['id'],
                                ':ip_address' => $_SERVER['REMOTE_ADDR']
                            ]);

                            if (updateUserRole($db, $user['id'])) {
                                $success = "Quyền của bạn đã được nâng cấp!";
                            }

                            switch ($_SESSION['role_id']) {
                                case 1:
                                    header('Location: /HRMpv/views/admin/index_admin.php');
                                    exit();
                                case 2:
                                    header('Location: /HRMpv/views/lanhdao/reports.php');
                                    exit();
                                case 3:
                                    header('Location: /HRMpv/views/HR/list_employee.php');
                                    exit();
                                case 4:
                                    header('Location: /HRMpv/views/ketoan/tinhluong.php');
                                    exit();
                                case 5:
                                    header('Location: /HRMpv/views/employee/index_employee.php?id=' . $user['id']);
                                    exit();
                                default:
                                    $error = "Vai trò không hợp lệ!";
                                    session_destroy();
                            }
                        } else {
                            $error = "Mật khẩu không đúng!";
                        }
                    }
                } else {
                    $error = "Email không tồn tại trong hệ thống!";
                }
            }
        } catch (PDOException $e) {
            $error = "Lỗi kết nối: " . $e->getMessage();
        }
    } else {
        $error = "Vui lòng nhập đầy đủ thông tin!";
    }
}

// Xử lý gửi biểu mẫu ứng tuyển (đã sửa để kiểm tra email trùng lặp)
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['login_submit'])) {
    $fullname = filter_input(INPUT_POST, 'fullname', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
    $experience = filter_input(INPUT_POST, 'experience', FILTER_SANITIZE_STRING);
    $education = filter_input(INPUT_POST, 'education', FILTER_SANITIZE_STRING);
    $position = filter_input(INPUT_POST, 'position', FILTER_SANITIZE_STRING);
    $expected_salary = filter_input(INPUT_POST, 'expected_salary', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
    $captcha = filter_input(INPUT_POST, 'captcha', FILTER_SANITIZE_STRING);

    $errors = [];
    if (empty($fullname)) $errors[] = "Vui lòng nhập họ và tên";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email không hợp lệ";
    if (!preg_match("/^[0-9]{10,11}$/", $phone)) $errors[] = "Số điện thoại phải có 10-11 chữ số";
    if (empty($experience)) $errors[] = "Vui lòng nhập kinh nghiệm làm việc";
    if (empty($education)) $errors[] = "Vui lòng nhập trình độ học vấn";
    if (empty($position)) $errors[] = "Vui lòng chọn vị trí ứng tuyển";
    if ($expected_salary <= 0) $errors[] = "Mức lương mong muốn phải lớn hơn 0";
    if (empty($start_date) || strtotime($start_date) < time()) $errors[] = "Ngày bắt đầu phải là ngày trong tương lai";
    if (empty($captcha) || $captcha != $_SESSION['captcha']) $errors[] = "Mã xác nhận không đúng";

    // Kiểm tra email trùng lặp
    if (empty($errors)) {
        try {
            $stmt_check_email = $db->prepare("SELECT COUNT(*) FROM job_applications WHERE email = :email");
            $stmt_check_email->execute([':email' => $email]);
            $email_count = $stmt_check_email->fetchColumn();

            if ($email_count > 0) {
                $errors[] = "Email này đã được sử dụng để ứng tuyển. Vui lòng sử dụng email khác.";
            }
        } catch (PDOException $e) {
            $errors[] = "Lỗi khi kiểm tra email: " . $e->getMessage();
            error_log("Lỗi khi kiểm tra email trùng lặp: " . $e->getMessage());
        }
    }

    $cv_path = '';
    if (isset($_FILES['cv_file']) && $_FILES['cv_file']['error'] == 0) {
        $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $file_size = $_FILES['cv_file']['size'] / 1024 / 1024;
        
        if (!in_array($_FILES['cv_file']['type'], $allowed_types)) {
            $errors[] = "Vui lòng tải lên file CV định dạng PDF hoặc DOC/DOCX";
        } elseif ($file_size > 5) {
            $errors[] = "Kích thước file CV không được vượt quá 5MB";
        } else {
            $tempPath = $_FILES['cv_file']['tmp_name'];
            $fileName = time() . '_' . basename($_FILES['cv_file']['name']);
            $folderId = '1xPsdMtyABbpFKjnBnaUx7BgHBvg2YXpa';

            error_log("Temp file path: $tempPath, Original filename: $fileName");

            if (file_exists($tempPath)) {
                $fileId = uploadToGoogleDrive($tempPath, $fileName, $folderId);
                if (!str_contains($fileId, 'Lỗi')) {
                    $cv_path = $fileId;
                    error_log("CV File ID assigned: $cv_path");
                } else {
                    $errors[] = "Lỗi tải CV lên Google Drive: $fileId";
                }
            } else {
                $errors[] = "Lỗi: File tạm không tồn tại tại $tempPath";
                error_log("Lỗi: File tạm không tồn tại tại $tempPath");
            }
        }
    } else {
        $errors[] = "Vui lòng tải lên CV";
    }

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO job_applications (fullname, email, phone, experience, education, position, expected_salary, start_date, cv_path, application_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $db->prepare($sql);
            $stmt->execute([$fullname, $email, $phone, $experience, $education, $position, $expected_salary, $start_date, $cv_path]);
            
            $_SESSION['success_message'] = "Đơn ứng tuyển của bạn đã được gửi thành công!";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Có lỗi xảy ra khi gửi đơn. Vui lòng thử lại sau.";
            error_log("Lỗi khi lưu đơn ứng tuyển: " . $e->getMessage());
        }
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
    }
}

// Tạo mã CAPTCHA đơn giản (từ job_application.php)
$_SESSION['captcha'] = mt_rand(1000, 9999);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Tuyển dụng tại Công ty TNHH Phát Triển Công Nghệ Lục Ngạn - Cơ hội nghề nghiệp hấp dẫn với chế độ đãi ngộ tốt.">
    <meta name="keywords" content="tuyển dụng, việc làm, Công Nghệ , Lục Ngạn, Bắc Giang">
    <meta name="author" content="Công ty TNHH Phát Triển Công Nghệ  Lục Ngạn">
    <title>Tuyển Dụng - Công ty TNHH Phát Triển Công Nghệ  Lục Ngạn</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/HRMpv/public/css/job.css">
    <style>
        /* CSS từ login.php */
        .login-container {
            max-width: 400px;
            width: 100%;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .btn-primary {
            background-color: #007bff;
            border: none;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
        .social-login .btn {
            margin: 5px 0;
        }
        .input-group-text {
            background-color: #fff;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="/HRMpv/public/HRM.png" alt="HRM Logo" style="height: 40px;">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#job-details">Chi Tiết Công Việc</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#org-chart">Sơ Đồ Tổ Chức</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#application-form">Ứng Tuyển</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#contact-info">Liên Hệ</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#loginModal">Đăng Nhập</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero-section" id="hero">
        <div class="container">
            <h1>Cơ Hội Nghề Nghiệp Tại Lục Ngạn</h1>
            <p>Tham gia đội ngũ của Công ty TNHH Phát Triển Công Nghệ Lục Ngạn để phát triển sự nghiệp của bạn!</p>
            <a href="#application-form" class="btn btn-apply-now">Ứng Tuyển Ngay</a>
        </div>
    </div>

    <div class="container">
        <!-- Thông báo -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php 
                    echo $_SESSION['success_message'];
                    unset($_SESSION['success_message']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                    echo $_SESSION['error_message'];
                    unset($_SESSION['error_message']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Chi tiết công việc -->
        <div class="section job-details" id="job-details">
            <h2>Chi Tiết Công Việc</h2>
            <div class="row">
                <div class="col-md-6">
                    <div class="benefit-card">
                        <i class="fas fa-money-bill-wave feature-icon"></i>
                        <h4>Mức Lương</h4>
                        <p>8,000,000 - 15,000,000 VNĐ/tháng (tùy năng lực)</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="benefit-card">
                        <i class="fas fa-clock feature-icon"></i>
                        <h4>Giờ Làm Việc</h4>
                        <p>Thứ 2 - Thứ 6: 8:00 - 17:00<br>Nghỉ trưa: 12:00 - 13:30</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="benefit-card">
                        <i class="fas fa-gift feature-icon"></i>
                        <h4>Thưởng & Phúc Lợi</h4>
                        <ul>
                            <li>Thưởng tháng 13</li>
                            <li>Thưởng theo dự án</li>
                            <li>Bảo hiểm đầy đủ</li>
                            <li>Du lịch hàng năm</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="benefit-card">
                        <i class="fas fa-tasks feature-icon"></i>
                        <h4>Mô Tả Công Việc</h4>
                        <ul>
                            <li>Quản lý nhân sự và tuyển dụng</li>
                            <li>Xây dựng chính sách nhân sự</li>
                            <li>Đào tạo và phát triển nhân viên</li>
                            <li>Báo cáo định kỳ</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sơ đồ tổ chức -->
        <div class="section org-chart" id="org-chart">
            <h2>Sơ Đồ Tổ Chức</h2>
            <div class="row text-center">
                <div class="col-12 mb-4">
                    <div class="p-4 bg-primary text-white rounded shadow-sm">Giám Đốc</div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="p-4 bg-info text-white rounded shadow-sm">Trưởng Phòng Nhân Sự</div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="p-4 bg-info text-white rounded shadow-sm">Trưởng Phòng Kỹ Thuật</div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="p-4 bg-info text-white rounded shadow-sm">Trưởng Phòng Kinh Doanh</div>
                </div>
            </div>
        </div>

        <!-- Biểu mẫu ứng tuyển -->
        <div class="section application-form" id="application-form">
            <h2>Đơn Ứng Tuyển</h2>
            <form method="POST" enctype="multipart/form-data" id="applicationForm">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="fullname" class="form-label">Họ và Tên</label>
                        <input type="text" class="form-control" id="fullname" name="fullname" required>
                        <div class="error-message" id="fullname-error"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                        <div class="error-message" id="email-error"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="phone" class="form-label">Số Điện Thoại</label>
                        <input type="tel" class="form-control" id="phone" name="phone" pattern="[0-9]{10,11}" required>
                        <div class="error-message" id="phone-error"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="position" class="form-label">Vị Trí Ứng Tuyển</label>
                        <select class="form-select" id="position" name="position" required>
                            <option value="">Chọn vị trí</option>
                            <?php foreach ($positions as $pos): ?>
                                <option value="<?php echo htmlspecialchars($pos['name']); ?>">
                                    <?php echo htmlspecialchars($pos['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-message" id="position-error"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="experience" class="form-label">Kinh Nghiệm Làm Việc</label>
                        <textarea class="form-control" id="experience" name="experience" rows="3" required></textarea>
                        <div class="error-message" id="experience-error"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="education" class="form-label">Trình Độ Học Vấn</label>
                        <textarea class="form-control" id="education" name="education" rows="3" required></textarea>
                        <div class="error-message" id="education-error"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="expected_salary" class="form-label">Mức Lương Mong Muốn (VNĐ)</label>
                        <input type="number" class="form-control" id="expected_salary" name="expected_salary" min="0" step="100000" required>
                        <div class="error-message" id="expected_salary-error"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="start_date" class="form-label">Ngày Có Thể Bắt Đầu</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" min="<?php echo date('Y-m-d'); ?>" required>
                        <div class="error-message" id="start_date-error"></div>
                    </div>
                    <div class="col-12 mb-3">
                        <label for="cv_file" class="form-label">Tải Lên CV (PDF, DOC, DOCX)</label>
                        <input type="file" class="form-control" id="cv_file" name="cv_file" accept=".pdf,.doc,.docx" required>
                        <div class="error-message" id="cv_file-error"></div>
                        <div id="cv-preview" class="mt-2"></div>
                    </div>
                    <div class="col-12 mb-3">
                        <label for="captcha" class="form-label">Mã Xác Nhận</label>
                        <div class="captcha-container">
                            <img src="data:image/png;base64,<?php echo base64_encode(generateCaptchaImage($_SESSION['captcha'])); ?>" alt="CAPTCHA">
                            <input type="text" class="form-control" id="captcha" name="captcha" required>
                        </div>
                        <div class="error-message" id="captcha-error"></div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Gửi Đơn Ứng Tuyển</button>
            </form>
        </div>

        <!-- FAQ Section -->
        <div class="section faq-section" id="faq">
            <h2>Câu Hỏi Thường Gặp</h2>
            <div class="accordion" id="faqAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header" id="faqHeading1">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse1" aria-expanded="true" aria-controls="faqCollapse1">
                            Quy trình tuyển dụng diễn ra như thế nào?
                        </button>
                    </h2>
                    <div id="faqCollapse1" class="accordion-collapse collapse show" aria-labelledby="faqHeading1" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Quy trình tuyển dụng bao gồm: Nộp đơn ứng tuyển → Sàng lọc hồ sơ → Phỏng vấn → Thông báo kết quả. Thời gian xử lý thường từ 7-14 ngày.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="faqHeading2">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse2" aria-expanded="false" aria-controls="faqCollapse2">
                            Tôi có thể ứng tuyển nhiều vị trí cùng lúc không?
                        </button>
                    </h2>
                    <div id="faqCollapse2" class="accordion-collapse collapse" aria-labelledby="faqHeading2" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Có, bạn có thể ứng tuyển nhiều vị trí. Tuy nhiên, chúng tôi khuyến khích bạn chọn vị trí phù hợp nhất với kinh nghiệm và kỹ năng của mình.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="faqHeading3">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapse3" aria-expanded="false" aria-controls="faqCollapse3">
                            Tôi sẽ nhận kết quả ứng tuyển qua đâu?
                        </button>
                    </h2>
                    <div id="faqCollapse3" class="accordion-collapse collapse" aria-labelledby="faqHeading3" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Kết quả sẽ được gửi qua email bạn đã cung cấp trong đơn ứng tuyển. Vui lòng kiểm tra cả hộp thư rác (spam).
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Thông tin liên hệ -->
        <div class="section contact-info" id="contact-info">
            <h2>Thông Tin Liên Hệ</h2>
            <div class="row">
                <div class="col-md-6">
                    <p><i class="fas fa-building me-2"></i> Công ty TNHH Phát Triển Công Nghệ Lục Ngạn</p>
                    <p><i class="fas fa-map-marker-alt me-2"></i> Địa chỉ: Thị trấn Lục Ngạn, Huyện Lục Ngạn, Tỉnh Bắc Giang</p>
                    <p><i class="fas fa-phone me-2"></i> Điện thoại: 0562044109 </p>
                    <p><i class="fas fa-envelope me-2"></i> Email: <a href="mailto:20211104@eaut.edu.vn">20211104@eaut.edu.vn</a></p>
                </div>
                <div class="col-md-6">
                    <p><i class="fas fa-clock me-2"></i> Giờ làm việc:</p>
                    <p class="ms-4">Thứ 2 - Thứ 6: 8:00 - 17:00</p>
                    <p class="ms-4">Thứ 7: 8:00 - 12:00</p>
                    <p class="ms-4">Chủ nhật: Nghỉ</p>
                </div>
            </div>
        </div>

        <!-- Bản đồ -->
        <div class="map-container">
            <iframe 
                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d59318.97689675419!2d106.64999999999998!3d21.366666699999997!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x314a4d0f1d40c6c5%3A0x2dc5e2b82d1b2f45!2zTOG7pWMgTmfhuqFuLCBC4bqvYyBHaWFuZywgVmnhu4d0IE5hbQ!5e0!3m2!1svi!2s!4v1709700000000!5m2!1svi!2s"
                width="100%" 
                height="100%" 
                style="border:0;" 
                allowfullscreen="" 
                loading="lazy">
            </iframe>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5>Về Chúng Tôi</h5>
                    <p>Công ty TNHH Phát Triển Công Nghệ Lục Ngạn là đơn vị tiên phong trong lĩnh vực phát triển Công Nghệ tại Bắc Giang.</p>
                </div>
                <div class="col-md-6">
                    <h5>Theo Dõi Chúng Tôi</h5>
                    <div class="social-links">
                        <a href="#" class="text-white me-3"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div class="text-center">
                <p>© 2025 Công ty TNHH Phát Triển Công Nghệ Lục Ngạn. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Login Modal -->
    <div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="loginModalLabel">Đăng Nhập - HRM System</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="login-container">
                        <div class="card border-0">
                            <div class="card-body">
                                <div class="text-center logo-area">
                                    <i class="bi bi-people-fill text-primary" style="font-size: 3rem;"></i>
                                    <h2 class="mt-3 fw-bold">HRM System</h2>
                                    <p class="text-muted">Đăng nhập để tiếp tục</p>
                                </div>
                                
                                <?php if (!empty($error)): ?>
                                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                        <?= htmlspecialchars($error) ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($success)): ?>
                                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                                        <i class="bi bi-check-circle-fill me-2"></i>
                                        <?= htmlspecialchars($success) ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($warning)): ?>
                                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                        <?= htmlspecialchars($warning) ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="POST" class="mt-4">
                                    <input type="hidden" name="login_submit" value="1">
                                    <div class="mb-4 input-group">
                                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                        <input type="email" name="email" class="form-control" placeholder="Email đăng nhập" required>
                                    </div>
                                    <div class="mb-4 input-group">
                                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                        <input type="password" name="password" class="form-control" placeholder="Mật khẩu" required>
                                    </div>
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary btn-lg">Đăng Nhập</button>
                                    </div>
                                </form>
                                
                                <div class="text-center mt-4">
                                    <p class="text-muted">Hoặc đăng nhập với</p>
                                    <div class="social-login">
                                        <a href="<?= $google_auth_url ?>" class="btn btn-outline-danger w-100">
                                            <i class="bi bi-google me-2"></i>Google
                                        </a>
                                        <a href="<?= $facebook_auth_url ?>" class="btn btn-outline-primary w-100">
                                            <i class="bi bi-facebook me-2"></i>Facebook
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <style>
    /* CSS cho modal đăng nhập */
    .modal-content {
        border-radius: 10px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .modal-header {
        border-bottom: none;
        padding-bottom: 0;
    }

    .modal-body {
        padding: 20px 40px;
    }

    .login-container {
        max-width: 400px;
        width: 100%;
        margin: 0 auto;
    }

    .logo-area {
        text-align: center;
        margin-bottom: 20px;
    }

    .logo-area i {
        font-size: 3rem;
        color: #007bff;
    }

    .logo-area h2 {
        font-size: 1.75rem;
        font-weight: bold;
        margin-top: 10px;
        margin-bottom: 5px;
    }

    .logo-area p {
        font-size: 0.9rem;
        color: #6c757d;
        margin-bottom: 0;
    }

    .alert {
        font-size: 0.9rem;
        padding: 10px;
        margin-bottom: 20px;
    }

    .input-group {
        margin-bottom: 20px;
    }

    .input-group-text {
        background-color: #fff;
        border-right: none;
        color: #6c757d;
    }

    .form-control {
        border-left: none;
        padding: 10px;
        font-size: 0.9rem;
    }

    .form-control:focus {
        box-shadow: none;
        border-color: #ced4da;
    }

    .btn-primary {
        background-color: #007bff;
        border: none;
        padding: 12px;
        font-size: 1rem;
        font-weight: 500;
        border-radius: 5px;
        width: 100%;
        transition: background-color 0.3s;
    }

    .btn-primary:hover {
        background-color: #0056b3;
    }

    .text-center {
        margin-top: 20px;
    }

    .text-muted {
        font-size: 0.9rem;
        color: #6c757d;
    }

    .social-login {
        margin-top: 10px;
    }

    .social-login .btn {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        padding: 10px;
        margin: 5px 0;
        border-radius: 5px;
        transition: background-color 0.3s;
    }

    .social-login .btn i {
        margin-right: 8px;
    }

    .btn-outline-danger {
        color: #dc3545;
        border-color: #dc3545;
    }

    .btn-outline-danger:hover {
        background-color: #dc3545;
        color: #fff;
    }

    .btn-outline-primary {
        color: #007bff;
        border-color: #007bff;
    }

    .btn-outline-primary:hover {
        background-color: #007bff;
        color: #fff;
    }
</style>
    <!-- Chatbot Container -->
    <div id="chatbot" class="chatbot-container">
        <div class="chatbot-header">
            <h5>Chat với HR</h5>
            <button id="closeChat" class="btn-close"></button>
        </div>
        <div id="chatMessages" class="chatbot-messages"></div>
        <div class="chatbot-suggestions">
            <button class="suggestion-btn" data-message="Công ty làm gì?">Về công ty</button>
            <button class="suggestion-btn" data-message="Vị trí tuyển dụng hiện tại?">Vị trí tuyển dụng</button>
            <button class="suggestion-btn" data-message="Phúc lợi của công ty?">Phúc lợi</button>
            <button class="suggestion-btn" data-message="Làm sao để nộp đơn?">Cách nộp đơn</button>
            <button class="suggestion-btn" data-message="Liên hệ với ai?">Liên hệ</button>
        </div>
        <div class="chatbot-input">
            <input type="text" id="userInput" placeholder="Nhập câu hỏi của bạn...">
            <button id="sendMessage" class="btn btn-primary"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>

    <!-- Chat Button -->
    <button id="openChat" class="chat-btn">
        <i class="fas fa-comment-alt me-2"></i> Chat với HR
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Chatbot logic
        const chatbot = document.getElementById('chatbot');
        const openChatBtn = document.getElementById('openChat');
        const closeChatBtn = document.getElementById('closeChat');
        const chatMessages = document.getElementById('chatMessages');
        const userInput = document.getElementById('userInput');
        const sendMessageBtn = document.getElementById('sendMessage');
        const suggestionBtns = document.querySelectorAll('.suggestion-btn');

        openChatBtn.addEventListener('click', () => {
            chatbot.style.display = 'block';
            openChatBtn.style.display = 'none';
            addBotMessage("Xin chào! Tôi là trợ lý HR của Công ty TNHH Phát Triển Công Nghệ Lục Ngạn. Bạn khỏe không? Chọn một gợi ý bên dưới hoặc nhập câu hỏi của bạn!");
        });

        closeChatBtn.addEventListener('click', () => {
            chatbot.style.display = 'none';
            openChatBtn.style.display = 'block';
        });

        const responses = {
            "công ty làm gì": "Chúng tôi là công ty chuyên phát triển công nghệ tại Bắc Giang, tập trung vào quản lý nhân sự, phần mềm và giải pháp kỹ thuật.",
            "vị trí tuyển dụng": "Hiện tại chúng tôi đang tuyển các vị trí như Trưởng phòng Nhân sự, Kỹ thuật và Kinh doanh. Bạn có thể xem chi tiết tại mục 'Chi Tiết Công Việc'.",
            "phúc lợi": "Chúng tôi cung cấp lương 8-15 triệu VNĐ/tháng, thưởng tháng 13, bảo hiểm, du lịch hàng năm và nhiều phúc lợi khác.",
            "nộp đơn": "Bạn có thể điền form ứng tuyển tại mục 'Đơn Ứng Tuyển' trên trang này. Chỉ cần tải CV và điền thông tin!",
            "liên hệ": "Bạn có thể gọi 0562044109 hoặc email 20211104@eaut.edu.vn để liên hệ trực tiếp với HR."
        };

        function addBotMessage(message) {
            const p = document.createElement('p');
            p.classList.add('bot-message');
            p.textContent = message;
            chatMessages.appendChild(p);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function addUserMessage(message) {
            const p = document.createElement('p');
            p.classList.add('user-message');
            p.textContent = message;
            chatMessages.appendChild(p);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function handleMessage(message) {
            if (!message) return;
            addUserMessage(message);
            let reply = "Xin lỗi, tôi chưa hiểu câu hỏi của bạn. Bạn có thể chọn gợi ý bên dưới hoặc hỏi lại nhé!";
            for (const [key, value] of Object.entries(responses)) {
                if (message.toLowerCase().includes(key)) {
                    reply = value;
                    break;
                }
            }
            setTimeout(() => addBotMessage(reply), 500);
        }

        sendMessageBtn.addEventListener('click', () => {
            const message = userInput.value.trim();
            if (message) {
                handleMessage(message);
                userInput.value = '';
            }
        });

        userInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                const message = userInput.value.trim();
                if (message) {
                    handleMessage(message);
                    userInput.value = '';
                }
            }
        });

        suggestionBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const message = btn.getAttribute('data-message');
                handleMessage(message);
            });
        });

        // Xác thực biểu mẫu ứng tuyển phía client
        document.getElementById('applicationForm').addEventListener('submit', function(e) {
            e.preventDefault();
            let isValid = true;
            const errors = {};

            document.querySelectorAll('.error-message').forEach(el => el.style.display = 'none');

            const fullname = document.getElementById('fullname').value;
            if (!fullname) {
                errors.fullname = "Vui lòng nhập họ và tên";
                document.getElementById('fullname-error').textContent = errors.fullname;
                document.getElementById('fullname-error').style.display = 'block';
                isValid = false;
            }

            const email = document.getElementById('email').value;
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                errors.email = "Email không hợp lệ";
                document.getElementById('email-error').textContent = errors.email;
                document.getElementById('email-error').style.display = 'block';
                isValid = false;
            }

            const phone = document.getElementById('phone').value;
            if (!/^[0-9]{10,11}$/.test(phone)) {
                errors.phone = "Số điện thoại phải có 10-11 chữ số";
                document.getElementById('phone-error').textContent = errors.phone;
                document.getElementById('phone-error').style.display = 'block';
                isValid = false;
            }

            const position = document.getElementById('position').value;
            if (!position) {
                errors.position = "Vui lòng chọn vị trí ứng tuyển";
                document.getElementById('position-error').textContent = errors.position;
                document.getElementById('position-error').style.display = 'block';
                isValid = false;
            }

            const experience = document.getElementById('experience').value;
            if (!experience) {
                errors.experience = "Vui lòng nhập kinh nghiệm làm việc";
                document.getElementById('experience-error').textContent = errors.experience;
                document.getElementById('experience-error').style.display = 'block';
                isValid = false;
            }

            const education = document.getElementById('education').value;
            if (!education) {
                errors.education = "Vui lòng nhập trình độ học vấn";
                document.getElementById('education-error').textContent = errors.education;
                document.getElementById('education-error').style.display = 'block';
                isValid = false;
            }

            const expectedSalary = document.getElementById('expected_salary').value;
            if (expectedSalary <= 0) {
                errors.expected_salary = "Mức lương mong muốn phải lớn hơn 0";
                document.getElementById('expected_salary-error').textContent = errors.expected_salary;
                document.getElementById('expected_salary-error').style.display = 'block';
                isValid = false;
            }

            const startDate = new Date(document.getElementById('start_date').value);
            const today = new Date();
            if (startDate < today) {
                errors.start_date = "Ngày bắt đầu phải là ngày trong tương lai";
                document.getElementById('start_date-error').textContent = errors.start_date;
                document.getElementById('start_date-error').style.display = 'block';
                isValid = false;
            }

            const fileInput = document.getElementById('cv_file');
            if (fileInput.files.length > 0) {
                const fileSize = fileInput.files[0].size / 1024 / 1024;
                const allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                
                if (!allowedTypes.includes(fileInput.files[0].type)) {
                    errors.cv_file = "Vui lòng tải lên file CV định dạng PDF hoặc DOC/DOCX";
                    document.getElementById('cv_file-error').textContent = errors.cv_file;
                    document.getElementById('cv_file-error').style.display = 'block';
                    isValid = false;
                }
                
                if (fileSize > 5) {
                    errors.cv_file = "Kích thước file CV không được vượt quá 5MB";
                    document.getElementById('cv_file-error').textContent = errors.cv_file;
                    document.getElementById('cv_file-error').style.display = 'block';
                    isValid = false;
                }
            } else {
                errors.cv_file = "Vui lòng tải lên CV";
                document.getElementById('cv_file-error').textContent = errors.cv_file;
                document.getElementById('cv_file-error').style.display = 'block';
                isValid = false;
            }

            const captcha = document.getElementById('captcha').value;
            if (!captcha || captcha !== '<?php echo $_SESSION['captcha']; ?>') {
                errors.captcha = "Mã xác nhận không đúng";
                document.getElementById('captcha-error').textContent = errors.captcha;
                document.getElementById('captcha-error').style.display = 'block';
                isValid = false;
            }

            if (isValid) {
                this.submit();
            }
        });

        // Preview CV file
        document.getElementById('cv_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('cv-preview');
            preview.innerHTML = '';

            if (file) {
                const fileName = file.name;
                const fileSize = (file.size / 1024 / 1024).toFixed(2);
                preview.innerHTML = `<p>Đã chọn: ${fileName} (${fileSize} MB)</p>`;
            }
        });

        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                if (!this.getAttribute('data-bs-toggle')) {
                    e.preventDefault();
                    document.querySelector(this.getAttribute('href')).scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Animation on scroll
        const sections = document.querySelectorAll('.section');
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate__animated', 'animate__fadeInUp');
                }
            });
        }, { threshold: 0.1 });

        sections.forEach(section => observer.observe(section));
    </script>
</body>
</html>

<?php
// Hàm tạo hình ảnh CAPTCHA (từ job_application.php)
function generateCaptchaImage($text) {
    $width = 100;
    $height = 40;
    $image = imagecreatetruecolor($width, $height);
    $bgColor = imagecolorallocate($image, 255, 255, 255);
    $textColor = imagecolorallocate($image, 0, 0, 0);
    $noiseColor = imagecolorallocate($image, 200, 200, 200);

    imagefill($image, 0, 0, $bgColor);
    for ($i = 0; $i < 50; $i++) {
        imagesetpixel($image, rand(0, $width), rand(0, $height), $noiseColor);
    }
    imagestring($image, 5, 20, 10, $text, $textColor);
    ob_start();
    imagepng($image);
    $image_data = ob_get_clean();
    imagedestroy($image);
    return $image_data;
}
?>