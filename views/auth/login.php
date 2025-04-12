<?php
session_start();
session_regenerate_id(true); // Bảo mật session
require_once __DIR__ . "/../../core/Database.php";

// Google OAuth
$google_client_id = 'YOUR_GOOGLE_CLIENT_ID'; 
$google_redirect_uri = 'http://yourdomain.com/google_callback.php';
$google_auth_url = "https://accounts.google.com/o/oauth2/auth?response_type=code&client_id={$google_client_id}&redirect_uri={$google_redirect_uri}&scope=email%20profile";

// Facebook OAuth
$facebook_app_id = 'YOUR_FACEBOOK_APP_ID'; 
$facebook_redirect_uri = 'http://yourdomain.com/facebook_callback.php';
$facebook_auth_url = "https://www.facebook.com/v12.0/dialog/oauth?client_id={$facebook_app_id}&redirect_uri={$facebook_redirect_uri}&scope=email";

$error = "";
$success = "";
$warning = ""; // Định nghĩa biến warning
$db = Database::getInstance()->getConnection();

// Hàm kiểm tra và cập nhật quyền mới nhất từ database
function updateUserRole($db, $user_id) {
    $stmt = $db->prepare("SELECT role_id FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['role_id'] != $_SESSION['role_id']) {
        $_SESSION['role_id'] = $user['role_id'];
        return true; // Quyền đã được cập nhật
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($email) && !empty($password)) {
        try {
            // Kiểm tra trong bảng employees trước
            $stmt_emp = $db->prepare("
                SELECT id, email, password, status, contract_end_date 
                FROM employees 
                WHERE email = :email
            ");
            $stmt_emp->execute([':email' => $email]);
            $employee = $stmt_emp->fetch(PDO::FETCH_ASSOC);

            if ($employee) {
                // Kiểm tra trạng thái tài khoản
                switch ($employee['status']) {
                    case 'active':
                        // Tiếp tục xử lý
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
                    // Kiểm tra trạng thái nghỉ phép (chỉ chặn khi ngày hiện tại trong khoảng nghỉ phép)
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
                    // Kiểm tra hợp đồng
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
                            // Kiểm tra hoặc tạo bản ghi trong users
                            $stmt_user = $db->prepare("SELECT id, role_id, status FROM users WHERE email = :email");
                            $stmt_user->execute([':email' => $email]);
                            $user = $stmt_user->fetch(PDO::FETCH_ASSOC);

                            if ($user) {
                                // Kiểm tra trạng thái trong bảng users
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

                                // Ghi lịch sử đăng nhập
                                $stmt_log = $db->prepare("
                                    INSERT INTO login_history (user_id, ip_address, login_time)
                                    VALUES (:user_id, :ip_address, NOW())
                                ");
                                $stmt_log->execute([
                                    ':user_id' => $_SESSION['user_id'],
                                    ':ip_address' => $_SERVER['REMOTE_ADDR']
                                ]);

                                // Kiểm tra quyền mới nhất
                                if (updateUserRole($db, $_SESSION['user_id'])) {
                                    $success = "Quyền của bạn đã được nâng cấp!";
                                }

                                // Điều hướng dựa trên role_id
                                switch ($_SESSION['role_id']) {
                                    case 1: // Admin
                                        header('Location: /HRMpv/views/admin/index_admin.php');
                                        exit();
                                    case 2: // HR
                                        header('Location: /HRMpv/views/lanhdao/reports.php');
                                        exit();
                                    case 3: // Payroll
                                        header('Location: /HRMpv/views/HR/list_employee.php');
                                        exit();
                                    case 4: // Leader
                                        header('Location: /HRMpv/views/ketoan/tinhluong.php');
                                        exit();
                                    case 5: // Employee
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
                // Kiểm tra trong bảng users
                $stmt_users = $db->prepare("SELECT id, email, password, role_id, status FROM users WHERE email = :email");
                $stmt_users->execute([':email' => $email]);
                $user = $stmt_users->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Kiểm tra trạng thái tài khoản
                    switch ($user['status']) {
                        case 'active':
                            // Tiếp tục xử lý
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

                            // Ghi lịch sử đăng nhập
                            $stmt_log = $db->prepare("
                                INSERT INTO login_history (user_id, ip_address, login_time)
                                VALUES (:user_id, :ip_address, NOW())
                            ");
                            $stmt_log->execute([
                                ':user_id' => $user['id'],
                                ':ip_address' => $_SERVER['REMOTE_ADDR']
                            ]);

                            // Kiểm tra quyền mới nhất
                            if (updateUserRole($db, $user['id'])) {
                                $success = "Quyền của bạn đã được nâng cấp!";
                            }

                            // Điều hướng dựa trên role_id
                            switch ($_SESSION['role_id']) {
                                case 1: // Admin
                                    header('Location: /HRMpv/views/admin/index_admin.php');
                                    exit();
                                case 2: // HR
                                    header('Location: /HRMpv/views/lanhdao/reports.php');
                                    exit();
                                case 3: // Payroll
                                    header('Location: /HRMpv/views/HR/list_employee.php');
                                    exit();
                                case 4: // Leader
                                    header('Location: /HRMpv/views/ketoan/tinhluong.php');
                                    exit();
                                case 5: // Employee
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
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập - HRM System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
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
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6">
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
                    <div class="text-center mt-3 text-muted">
                        <small>© <?= date('Y') ?> HRM System. All rights reserved.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>