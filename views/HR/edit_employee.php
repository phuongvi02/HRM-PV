<?php
ob_start();
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

function uploadToGoogleDrive($filePath, $fileName, $folderId = null) {
    // Đường dẫn tuyệt đối đến file JSON, sử dụng DIRECTORY_SEPARATOR để tương thích đa nền tảng
    $jsonPath = 'C:/xampp/htdocs/HRMpv/hrm2003-057461bf62af.json';
    
    // Kiểm tra xem file JSON có tồn tại không
    if (!file_exists($jsonPath)) {
        error_log("Lỗi: File JSON không tồn tại tại " . $jsonPath);
        return 'Lỗi: File JSON không tồn tại tại ' . $jsonPath;
    }

    try {
        $client = new Client();
        $client->setAuthConfig($jsonPath);
        $client->setScopes([Drive::DRIVE_FILE]);

        $service = new Drive($client);
        $file = new DriveFile();
        $file->setName($fileName);
        
        if ($folderId) {
            $file->setParents([$folderId]);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception("Không thể đọc file tại: $filePath");
        }

        $uploadedFile = $service->files->create($file, [
            'data' => $content,
            'mimeType' => mime_content_type($filePath) ?: 'application/octet-stream',
            'uploadType' => 'multipart',
            'fields' => 'id'
        ]);

        $permission = new Drive\Permission();
        $permission->setType('anyone');
        $permission->setRole('reader');
        $service->permissions->create($uploadedFile->id, $permission);

        return $uploadedFile->id;
    } catch (Exception $e) {
        error_log("Lỗi khi tải lên Google Drive: " . $e->getMessage());
        return 'Lỗi khi tải lên: ' . $e->getMessage();
    }
}

$db = Database::getInstance()->getConnection();

// Lấy thông tin nhân viên dựa trên ID
$employee_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $db->prepare("SELECT * FROM employees WHERE id = :id");
$stmt->execute([':id' => $employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header("Location: list_employee.php?error=Không tìm thấy nhân viên!");
    exit();
}

// Lấy danh sách phòng ban và chức vụ
try {
    $stmt = $db->query("SELECT * FROM departments");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("SELECT * FROM positions");
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Lỗi truy vấn cơ sở dữ liệu: " . $e->getMessage());
    $departments = [];
    $positions = [];
}

// Xử lý khi form được gửi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $department_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
    $position_id = !empty($_POST['position_id']) ? intval($_POST['position_id']) : null;

    // Lấy mức lương từ bảng positions
    $salary = null;
    if ($position_id) {
        $stmt = $db->prepare("SELECT salary FROM positions WHERE id = :position_id");
        $stmt->execute([':position_id' => $position_id]);
        $position = $stmt->fetch(PDO::FETCH_ASSOC);
        $salary = $position['salary'] ?? null;
    }

    // Giữ avatar cũ nếu không tải ảnh mới
    $avatar_id = $employee['avatar'];
    if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $tempPath = $_FILES['avatar']['tmp_name'];
        $fileName = basename($_FILES['avatar']['name']);
        $folderId = '1xPsdMtyABbpFKjnBnaUx7BgHBvg2YXpa';

        if (!file_exists($tempPath)) {
            echo "<script>alert('Lỗi: File tạm không tồn tại tại $tempPath');</script>";
        } else {
            $fileId = uploadToGoogleDrive($tempPath, $fileName, $folderId);
            if (!str_contains($fileId, 'Lỗi')) {
                $avatar_id = $fileId;
            } else {
                echo "<script>alert('Lỗi tải ảnh lên Google Drive: $fileId');</script>";
            }
        }
    }

    // Cập nhật thông tin nhân viên
    $sql = "UPDATE employees SET 
            full_name = :full_name, 
            birth_date = :birth_date, 
            gender = :gender, 
            address = :address, 
            phone = :phone, 
            email = :email, 
            department_id = :department_id, 
            position_id = :position_id, 
            salary = :salary, 
            avatar = :avatar 
            WHERE id = :id";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':full_name' => $full_name,
            ':birth_date' => $birth_date ?: null,
            ':gender' => $gender,
            ':address' => $address,
            ':phone' => $phone,
            ':email' => $email,
            ':department_id' => $department_id,
            ':position_id' => $position_id,
            ':salary' => $salary,
            ':avatar' => $avatar_id,
            ':id' => $employee_id
        ]);
        header("Location: list_employee.php?success=Cập nhật nhân viên thành công!");
        exit();
    } catch (PDOException $e) {
        error_log("Lỗi PDO: " . $e->getMessage());
        echo "<script>alert('Lỗi khi cập nhật nhân viên: " . addslashes($e->getMessage()) . "');</script>";
    }
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chỉnh Sửa Nhân Viên</title>
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
        img { max-width: 100px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
 
        
        <main class="main-content">
          

            <div class="background-image"></div>

            <div class="form-container">
                <h2>Chỉnh Sửa Nhân Viên</h2>
                <form action="edit_employee.php?id=<?= htmlspecialchars($employee_id) ?>" method="POST" enctype="multipart/form-data">
                    <label>Họ và Tên</label>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($employee['full_name']) ?>" required>

                    <label>Ngày Sinh</label>
                    <input type="date" name="birth_date" value="<?= htmlspecialchars($employee['birth_date']) ?>" required>

                    <label>Giới Tính</label>
                    <select name="gender" required>
                        <option value="Nam" <?= $employee['gender'] === 'Nam' ? 'selected' : '' ?>>Nam</option>
                        <option value="Nữ" <?= $employee['gender'] === 'Nữ' ? 'selected' : '' ?>>Nữ</option>
                        <option value="Khác" <?= $employee['gender'] === 'Khác' ? 'selected' : '' ?>>Khác</option>
                    </select>

                    <label>Địa Chỉ</label>
                    <input type="text" name="address" value="<?= htmlspecialchars($employee['address']) ?>" required>

                    <label>Số Điện Thoại</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($employee['phone']) ?>" required>

                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($employee['email']) ?>" required>

                    <label>Phòng Ban</label>
                    <select name="department_id" required>
                        <option value="">Chọn phòng ban</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" <?= $employee['department_id'] == $dept['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Chức Vụ</label>
                    <select name="position_id" required>
                        <option value="">Chọn chức vụ</option>
                        <?php foreach ($positions as $pos): ?>
                            <option value="<?= $pos['id'] ?>" <?= $employee['position_id'] == $pos['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pos['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Ảnh Đại Diện</label>
                    <?php if ($employee['avatar']): ?>
                        <img src="https://drive.google.com/uc?id=<?= htmlspecialchars($employee['avatar']) ?>" alt="Avatar">
                    <?php endif; ?>
                    <input type="file" name="avatar" accept="image/*">

                    <button type="submit">Cập Nhật</button>
                    <a href="list_employee.php" class="cancel-btn">Hủy</a>
                </form>
            </div>
        </main>
    </div>
</body>
</html>