<?php
session_start();
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header("Location: /HRMpv/views/login.php");
    exit();
}

require_once __DIR__ . "/../../core/Database.php";

$db = Database::getInstance()->getConnection();

// Xử lý cập nhật vai trò và trạng thái
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit'])) {
    $id = intval($_POST['id'] ?? 0);
    $role_id = intval($_POST['role_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    // Kiểm tra dữ liệu hợp lệ
    if ($id > 0) {
        // Kiểm tra role_id có tồn tại trong bảng roles
        $roleCheckStmt = $db->prepare("SELECT COUNT(*) FROM roles WHERE id = ?");
        $roleCheckStmt->execute([$role_id]);
        $roleExists = $roleCheckStmt->fetchColumn() > 0;

        if ($roleExists && in_array($status, ['active', 'paused', 'expired'])) {
            try {
                $stmt = $db->prepare("UPDATE users SET role_id = ?, status = ? WHERE id = ?");
                $stmt->execute([$role_id, $status, $id]);
                $message = "Cập nhật vai trò và trạng thái cho người dùng ID $id thành công!";
                header("Location: manage_users.php?success=" . urlencode($message));
                exit();
            } catch (PDOException $e) {
                $message = "Lỗi khi cập nhật: " . $e->getMessage();
                header("Location: manage_users.php?error=" . urlencode($message));
                exit();
            }
        } else {
            $message = "Dữ liệu không hợp lệ! Vui lòng kiểm tra role_id hoặc trạng thái.";
            header("Location: manage_users.php?error=" . urlencode($message));
            exit();
        }
    } else {
        $message = "ID người dùng không hợp lệ!";
        header("Location: manage_users.php?error=" . urlencode($message));
        exit();
    }
}

// Lấy danh sách người dùng và thông tin vai trò
$usersStmt = $db->query("
    SELECT u.id AS user_id, u.email, u.role_id, u.status
    FROM users u
");
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$rolesStmt = $db->query("SELECT * FROM roles");
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

include '../layouts/header.php';
?>
    <button onclick="history.back()" class="btn btn-secondary mb-3">Quay lại</button>
<div class="container mt-5">
    <h1>Quản lý người dùng</h1>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars(urldecode($_GET['success'])); ?></div>
    <?php elseif (isset($_GET['error'])): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars(urldecode($_GET['error'])); ?></div>
    <?php endif; ?>

    <!-- Danh sách người dùng -->
    <h2 class="mt-4">Danh sách người dùng</h2>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>ID</th>
                <th>Email</th>
                <th>Vai trò</th>
                <th>Trạng thái</th>
                <th>Hành động</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Bạn có chắc chắn muốn cập nhật vai trò?');">
                            <input type="hidden" name="id" value="<?php echo $user['user_id']; ?>">
                            <select name="role_id" class="form-control d-inline-block w-auto">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>" 
                                        <?php echo $role['id'] == $user['role_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                    </td>
                    <td>
                        <select name="status" class="form-control d-inline-block w-auto">
                            <option value="active" <?php echo $user['status'] == 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                            <option value="paused" <?php echo $user['status'] == 'paused' ? 'selected' : ''; ?>>Tạm dừng</option>
                            <option value="expired" <?php echo $user['status'] == 'expired' ? 'selected' : ''; ?>>Hết hạn</option>
                        </select>
                    </td>
                    <td>
                        <button type="submit" name="edit" class="btn btn-sm btn-success">Cập nhật</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../layouts/footer.php'; ?>