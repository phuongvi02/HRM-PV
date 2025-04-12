<?php
ob_start();
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';

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

// Lấy dữ liệu phòng ban và chức vụ
try {
    $stmt = $db->query("SELECT * FROM departments");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->query("SELECT * FROM positions");
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Lỗi truy vấn cơ sở dữ liệu: " . $e->getMessage());
    $departments = $positions = [];
}

// Xử lý khi submit form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_code = trim($_POST['employee_code'] ?? 'NFL' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT));
    $full_name = trim($_POST['full_name'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $department_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
    $position_id = !empty($_POST['position_id']) ? intval($_POST['position_id']) : null;
    $avatar_id = null;
    $default_password = password_hash('12345@', PASSWORD_DEFAULT);

    // Kiểm tra định dạng số điện thoại Việt Nam
    if (!empty($phone)) {
        $phonePattern = '/^0[35789][0-9]{8}$/'; // Số Việt Nam: 10 chữ số, bắt đầu bằng 03, 05, 07, 08, 09
        if (!preg_match($phonePattern, $phone)) {
            echo "<script>alert('Số điện thoại không hợp lệ. Vui lòng nhập số Việt Nam (10 chữ số, bắt đầu bằng 03, 05, 07, 08, 09).');</script>";
            goto render_form;
        }

        // Kiểm tra số điện thoại đã tồn tại
        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->fetchColumn() > 0) {
            echo "<script>alert('Số điện thoại đã tồn tại. Vui lòng nhập số khác.');</script>";
            goto render_form;
        }
    }

    // Kiểm tra email đã tồn tại
    if (!empty($email)) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            echo "<script>alert('Email đã tồn tại. Vui lòng nhập email khác.');</script>";
            goto render_form;
        }
    }

    // Kiểm tra giới hạn số lượng nhân viên cho phòng ban và chức vụ
    if ($department_id) {
        $stmt = $db->prepare("SELECT max_employees, (SELECT COUNT(*) FROM employees WHERE department_id = ?) AS current_count FROM departments WHERE id = ?");
        $stmt->execute([$department_id, $department_id]);
        $dept = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($dept && $dept['current_count'] >= $dept['max_employees']) {
            echo "<script>alert('Vui lòng chọn phòng ban khác vì phòng ban này đã đủ số lượng tối đa ({$dept['max_employees']})!');</script>";
            goto render_form;
        }
    }

    if ($position_id) {
        $stmt = $db->prepare("SELECT max_employees, (SELECT COUNT(*) FROM employees WHERE position_id = ?) AS current_count FROM positions WHERE id = ?");
        $stmt->execute([$position_id, $position_id]);
        $pos = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pos && $pos['current_count'] >= $pos['max_employees']) {
            echo "<script>alert('Vui lòng chọn chức vụ khác vì chức vụ này đã đủ số lượng tối đa ({$pos['max_employees']})!');</script>";
            goto render_form;
        }
    }

    // Xử lý upload ảnh
    if (!empty($_FILES['avatar']['name'])) {
        error_log("File upload detected: " . print_r($_FILES['avatar'], true));

        if ($_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $tempPath = $_FILES['avatar']['tmp_name'];
            $fileName = basename($_FILES['avatar']['name']);
            $folderId = '1xPsdMtyABbpFKjnBnaUx7BgHBvg2YXpa';

            error_log("Temp file path: $tempPath, Original filename: $fileName");

            if (file_exists($tempPath)) {
                $fileSize = filesize($tempPath);
                error_log("File size: $fileSize bytes");

                $fileId = uploadToGoogleDrive($tempPath, $fileName, $folderId);
                if (!str_contains($fileId, 'Lỗi')) {
                    $avatar_id = $fileId;
                    error_log("Avatar ID assigned: $avatar_id");
                } else {
                    echo "<script>alert('Lỗi tải ảnh lên Google Drive: $fileId');</script>";
                }
            } else {
                echo "<script>alert('Lỗi: File tạm không tồn tại tại $tempPath');</script>";
                error_log("Lỗi: File tạm không tồn tại tại $tempPath");
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
            echo "<script>alert('Lỗi upload file: $errorMsg');</script>";
            error_log("Lỗi upload file, mã lỗi: $errorCode - $errorMsg");
        }
    }

    // Thêm nhân viên vào database
    $sql = "INSERT INTO employees (employee_code, full_name, birth_date, gender, address, phone, email, department_id, position_id, avatar, password, status, created_at)
            VALUES (:employee_code, :full_name, :birth_date, :gender, :address, :phone, :email, :department_id, :position_id, :avatar, :password, 'active', NOW())";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':employee_code' => $employee_code,
            ':full_name' => $full_name,
            ':birth_date' => $birth_date ?: null,
            ':gender' => $gender,
            ':address' => $address,
            ':phone' => $phone,
            ':email' => $email,
            ':department_id' => $department_id,
            ':position_id' => $position_id,
            ':avatar' => $avatar_id,
            ':password' => $default_password
        ]);
        error_log("Thêm nhân viên thành công, Avatar ID: " . ($avatar_id ?: 'Không có'));
        header("Location: list_employee.php?success=Thêm nhân viên thành công! Mật khẩu mặc định: 12345@");
        exit();
    } catch (PDOException $e) {
        error_log("Lỗi PDO: " . $e->getMessage());
        echo "<script>alert('Lỗi khi thêm nhân viên: " . addslashes($e->getMessage()) . "');</script>";
    }
}

render_form:
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thêm Nhân Viên</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/HRMpv/public/css/style.css">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f5f5f5; }
        .container { display: flex; min-height: 100vh; }
        .main-content { flex: 1; padding: 20px; position: relative; }
        .background-image { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: url('https://images.unsplash.com/photo-1501785888041-af3ef285b470') no-repeat center center; background-size: cover; filter: brightness(50%); z-index: -2; }
        h2 { font-size: 2em; margin-bottom: 20px; text-align: center; color: #fff; text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.7); }
        .form-container { max-width: 600px; margin: 20px auto; background: rgba(255, 255, 255, 0.9); border-radius: 15px; padding: 20px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3); }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        input, select { width: 100%; padding: 8px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
        input:focus, select:focus { border-color: #007bff; outline: none; }
        button { background: #007bff; color: #fff; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; width: 100%; }
        button:hover { background: #0056b3; }
        .cancel-btn { background: #dc3545; margin-top: 10px; display: block; text-align: center; text-decoration: none; color: #fff; padding: 10px; border-radius: 5px; }
        .cancel-btn:hover { background: #b02a37; }
    </style>
</head>
<body>
    <div class="container">
        <div class="background-image"></div>
        <div class="form-container">
            <h2>Thêm Nhân Viên</h2>
            <form action="add_employee.php" method="POST" enctype="multipart/form-data">
                <label>Mã Nhân Viên</label>
                <input type="text" name="employee_code" value="NFL<?= str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT) ?>" required>
                
                <label>Họ và Tên</label>
                <input type="text" name="full_name" required>
                
                <label>Ngày Sinh</label>
                <input type="date" name="birth_date">
                
                <label>Giới Tính</label>
                <select name="gender" required>
                    <option value="">Chọn giới tính</option>
                    <option value="Nam">Nam</option>
                    <option value="Nữ">Nữ</option>
                    <option value="Khác">Khác</option>
                </select>
                
                <label>Địa Chỉ</label>
                <input type="text" name="address">
                
                <label>Số Điện Thoại</label>
                <input type="text" name="phone" pattern="0[35789][0-9]{8}" title="Số điện thoại phải là số Việt Nam (10 chữ số, bắt đầu bằng 03, 05, 07, 08, 09)" placeholder="VD: 0912345678">
                
                <label>Email</label>
                <input type="email" name="email" required>
                
                <label>Phòng Ban</label>
                <select name="department_id">
                    <option value="">Chọn phòng ban</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <label>Chức Vụ</label>
                <select name="position_id">
                    <option value="">Chọn chức vụ</option>
                    <?php foreach ($positions as $pos): ?>
                        <option value="<?= $pos['id'] ?>"><?= htmlspecialchars($pos['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <label>Ảnh Đại Diện</label>
                <input type="file" name="avatar" accept="image/*">
                
                <button type="submit">Thêm Nhân Viên</button>
                <a href="list_employee.php" class="cancel-btn">Hủy</a>
            </form>
        </div>
    </div>
</body>
</html>
<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>