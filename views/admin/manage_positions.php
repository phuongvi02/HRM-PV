<?php
session_start();
// Kiểm tra xem người dùng đã đăng nhập và có vai trò admin (role_id = 1) hay không
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header("Location: /HRMpv/views/login.php");
    exit();
}

// Kết nối tới cơ sở dữ liệu
require_once __DIR__ . "/../../core/Database.php";
$db = Database::getInstance()->getConnection();

// Xử lý thêm/sửa/xóa chức vụ
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Thêm chức vụ mới
    if (isset($_POST['add'])) {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? ''); // Lấy mô tả chức vụ
        $salary = floatval($_POST['salary'] ?? 0);
        $maxEmployees = intval($_POST['max_employees'] ?? 0);
        if (!empty($name) && $salary >= 0 && $maxEmployees >= 0) {
            // Thêm cả description vào câu lệnh INSERT
            $stmt = $db->prepare("INSERT INTO positions (name, description, salary, max_employees) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $description ?: NULL, $salary, $maxEmployees]); // Nếu description rỗng, lưu là NULL
            header("Location: manage_positions.php?success=1");
            exit();
        } else {
            header("Location: manage_positions.php?error=invalid_input");
            exit();
        }
    } 
    // Sửa thông tin chức vụ
    elseif (isset($_POST['edit'])) {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? ''); // Lấy mô tả mới
        $salary = floatval($_POST['salary'] ?? 0);
        $maxEmployees = intval($_POST['max_employees'] ?? 0);
        if ($id > 0 && !empty($name) && $salary >= 0 && $maxEmployees >= 0) {
            // Kiểm tra số lượng nhân viên hiện tại
            $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE position_id = ?");
            $stmt->execute([$id]);
            $currentCount = $stmt->fetchColumn();
            if ($currentCount > $maxEmployees) {
                header("Location: manage_positions.php?error=max_employees_exceeded");
                exit();
            }
            // Cập nhật cả description
            $stmt = $db->prepare("UPDATE positions SET name = ?, description = ?, salary = ?, max_employees = ? WHERE id = ?");
            $stmt->execute([$name, $description ?: NULL, $salary, $maxEmployees, $id]);
            header("Location: manage_positions.php?success=2");
            exit();
        } else {
            header("Location: manage_positions.php?error=invalid_input");
            exit();
        }
    } 
    // Xóa chức vụ
    elseif (isset($_POST['delete'])) {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            // Kiểm tra xem chức vụ có nhân viên không
            $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE position_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                header("Location: manage_positions.php?error=position_in_use");
                exit();
            }
            $stmt = $db->prepare("DELETE FROM positions WHERE id = ?");
            $stmt->execute([$id]);
            header("Location: manage_positions.php?success=3");
            exit();
        } else {
            header("Location: manage_positions.php?error=invalid_id");
            exit();
        }
    }
}

// Lấy danh sách chức vụ cùng số lượng nhân viên
$positionsStmt = $db->query("
    SELECT p.*, 
           (SELECT COUNT(*) FROM employees e WHERE e.position_id = p.id) AS employee_count 
    FROM positions p
");
$positions = $positionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy thông tin tổng số lượng
$deptCountStmt = $db->query("SELECT COUNT(*) FROM departments");
$deptCount = $deptCountStmt->fetchColumn();

$posCountStmt = $db->query("SELECT COUNT(*) FROM positions");
$posCount = $posCountStmt->fetchColumn();

$empCountStmt = $db->query("SELECT COUNT(*) FROM employees");
$empCount = $empCountStmt->fetchColumn();

include '../layouts/header.php';
?>

<div class="container mt-5">
    <h1>Quản lý chức vụ</h1>
    <button onclick="history.back()" class="btn btn-secondary mb-3">Quay lại</button>

    <!-- Hiển thị thông tin tổng số lượng -->
    <div class="alert alert-info">
        <strong>Thông tin hệ thống:</strong><br>
        Số lượng phòng ban: <?php echo $deptCount; ?><br>
        Số lượng chức vụ: <?php echo $posCount; ?><br>
        Số lượng nhân viên: <?php echo $empCount; ?>
    </div>

    <!-- Hiển thị thông báo thành công hoặc lỗi -->
    <?php if (isset($_GET['success'])): ?>
        <?php if ($_GET['success'] == 1): ?>
            <div class="alert alert-success">Thêm chức vụ thành công!</div>
        <?php elseif ($_GET['success'] == 2): ?>
            <div class="alert alert-success">Cập nhật chức vụ thành công!</div>
        <?php elseif ($_GET['success'] == 3): ?>
            <div class="alert alert-success">Xóa chức vụ thành công!</div>
        <?php endif; ?>
    <?php elseif (isset($_GET['error'])): ?>
        <?php if ($_GET['error'] == 'invalid_input'): ?>
            <div class="alert alert-danger">Dữ liệu không hợp lệ! Vui lòng kiểm tra tên, mức lương và số lượng tối đa.</div>
        <?php elseif ($_GET['error'] == 'invalid_id'): ?>
            <div class="alert alert-danger">ID không hợp lệ!</div>
        <?php elseif ($_GET['error'] == 'position_in_use'): ?>
            <div class="alert alert-danger">Không thể xóa chức vụ vì đang có nhân viên sử dụng!</div>
        <?php elseif ($_GET['error'] == 'max_employees_exceeded'): ?>
            <div class="alert alert-danger">Số lượng tối đa không được nhỏ hơn số nhân viên hiện tại!</div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Form thêm chức vụ -->
    <h2>Thêm chức vụ</h2>
    <form method="POST">
        <div class="mb-3">
            <label>Tên chức vụ:</label>
            <input type="text" name="name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Mô tả chức vụ:</label>
            <textarea name="description" class="form-control" rows="4"></textarea> <!-- Thêm textarea cho mô tả -->
        </div>
        <div class="mb-3">
            <label>Mức lương (VND):</label>
            <input type="number" name="salary" step="1000" min="0" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Số lượng tối đa nhân viên:</label>
            <input type="number" name="max_employees" min="0" class="form-control" required>
        </div>
        <button type="submit" name="add" class="btn btn-primary">Thêm</button>
    </form>

    <!-- Danh sách chức vụ -->
    <h2 class="mt-5">Danh sách chức vụ</h2>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>ID</th>
                <th>Tên</th>
                <th>Mô tả</th> <!-- Thêm cột mô tả -->
                <th>Mức lương</th>
                <th>Số lượng tối đa</th>
                <th>Số lượng hiện tại</th>
                <th>Hành động</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($positions as $position): ?>
                <tr>
                    <td><?php echo htmlspecialchars($position['id']); ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="id" value="<?php echo $position['id']; ?>">
                            <input type="text" name="name" value="<?php echo htmlspecialchars($position['name']); ?>" class="form-control d-inline-block w-auto">
                    </td>
                    <td>
                            <textarea name="description" class="form-control d-inline-block w-auto" rows="2"><?php echo htmlspecialchars($position['description'] ?? ''); ?></textarea> <!-- Thêm textarea cho mô tả -->
                    </td>
                    <td>
                            <input type="number" name="salary" step="1000" min="0" value="<?php echo htmlspecialchars($position['salary']); ?>" class="form-control d-inline-block w-auto">
                    </td>
                    <td>
                            <input type="number" name="max_employees" min="0" value="<?php echo htmlspecialchars($position['max_employees']); ?>" class="form-control d-inline-block w-auto">
                            <button type="submit" name="edit" class="btn btn-sm btn-success">Cập nhật</button>
                        </form>
                    </td>
                    <td><?php echo htmlspecialchars($position['employee_count']); ?></td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa chức vụ này?');">
                            <input type="hidden" name="id" value="<?php echo $position['id']; ?>">
                            <button type="submit" name="delete" class="btn btn-sm btn-danger">Xóa</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../layouts/footer.php'; ?>