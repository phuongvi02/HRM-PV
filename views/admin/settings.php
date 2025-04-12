<?php
session_start();
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header("Location: /HRMpv/views/login.php");
    exit();
}

require_once __DIR__ . "/../../core/Database.php";

$db = Database::getInstance()->getConnection();

// Xử lý sửa/xóa thông số
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['edit'])) {
        $id = intval($_POST['id'] ?? 0);
        $value = trim($_POST['value'] ?? '');
        if ($id > 0 && !empty($value)) {
            $stmt = $db->prepare("UPDATE settings SET value = ? WHERE id = ?");
            $stmt->execute([$value, $id]);
            header("Location: settings.php?success=2");
            exit();
        } else {
            header("Location: settings.php?error=invalid_input");
            exit();
        }
    } elseif (isset($_POST['delete'])) { // Xử lý xóa
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM settings WHERE id = ?");
            $stmt->execute([$id]);
            header("Location: settings.php?success=3");
            exit();
        } else {
            header("Location: settings.php?error=invalid_id");
            exit();
        }
    }
}

// Lấy danh sách tất cả thông số từ bảng settings
$settingsStmt = $db->query("SELECT * FROM settings ORDER BY name");
$settings = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);


?>
    <button onclick="history.back()" class="btn btn-secondary mb-3">Quay lại</button>
<link rel="stylesheet" href="/HRMpv/public/css/settings.css">

<div class="container mt-5">
    <h1>Quản lý thông số hệ thống</h1>

    <?php if (isset($_GET['success'])): ?>
        <?php if ($_GET['success'] == 2): ?>
            <div class="alert alert-success">Cập nhật thông số thành công!</div>
        <?php elseif ($_GET['success'] == 3): ?>
            <div class="alert alert-success">Xóa thông số thành công!</div>
        <?php endif; ?>
    <?php elseif (isset($_GET['error'])): ?>
        <?php if ($_GET['error'] == 'invalid_input' || $_GET['error'] == 'invalid_id'): ?>
            <div class="alert alert-danger">Dữ liệu không hợp lệ!</div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Danh sách thông số -->
    <h2 class="mt-5">Danh sách thông số</h2>
    <?php if (empty($settings)): ?>
        <div class="alert alert-info">Chưa có thông số nào. Vui lòng liên hệ quản trị viên để thêm thông số.</div>
    <?php else: ?>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tên thông số</th>
                    <th>Giá trị</th>
                    <th>Đơn vị</th>
                    <th>Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($settings as $setting): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($setting['id']); ?></td>
                        <td>
                            <?php
                            $display_name = '';
                            switch ($setting['name']) {
                                case 'bhxh_rate':
                                    $display_name = 'Tỷ lệ BHXH';
                                    break;
                                case 'bhyt_rate':
                                    $display_name = 'Tỷ lệ BHYT';
                                    break;
                                case 'bhtn_rate':
                                    $display_name = 'Tỷ lệ BHTN';
                                    break;
                                case 'personal_deduction':
                                    $display_name = 'Giảm trừ gia cảnh';
                                    break;
                                case 'tax_rate_1':
                                    $display_name = 'Thuế suất TNCN (đến 5 triệu)';
                                    break;
                                case 'tax_rate_2':
                                    $display_name = 'Thuế suất TNCN (5-10 triệu)';
                                    break;
                                case 'tax_rate_3':
                                    $display_name = 'Thuế suất TNCN (10-18 triệu)';
                                    break;
                                case 'tax_rate_4':
                                    $display_name = 'Thuế suất TNCN (18-32 triệu)';
                                    break;
                                case 'tax_rate_5':
                                    $display_name = 'Thuế suất TNCN (32-52 triệu)';
                                    break;
                                case 'tax_rate_6':
                                    $display_name = 'Thuế suất TNCN (52-80 triệu)';
                                    break;
                                case 'tax_rate_7':
                                    $display_name = 'Thuế suất TNCN (trên 80 triệu)';
                                    break;
                                default:
                                    $display_name = htmlspecialchars($setting['name']);
                            }
                            echo $display_name;
                            ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="id" value="<?php echo $setting['id']; ?>">
                                <input type="number" name="value" step="<?php echo ($setting['name'] === 'personal_deduction') ? '1' : '0.01'; ?>" value="<?php echo htmlspecialchars($setting['value']); ?>" class="form-control d-inline-block w-auto">
                                <button type="submit" name="edit" class="btn btn-sm btn-success">Cập nhật</button>
                            </form>
                        </td>
                        <td>
                            <?php
                            echo ($setting['name'] === 'personal_deduction') ? 'VNĐ' : '%';
                            ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa thông số này?');">
                                <input type="hidden" name="id" value="<?php echo $setting['id']; ?>">
                                <button type="submit" name="delete" class="btn btn-sm btn-danger">Xóa</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include '../layouts/footer.php'; ?>