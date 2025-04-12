<?php
ob_start();
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';

$db = Database::getInstance()->getConnection();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Chỉ cho phép HR truy cập (role_id = 3)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Xử lý lọc theo tháng và năm
$filter_month = $_GET['month'] ?? date('m');
$filter_year = $_GET['year'] ?? date('Y');
$filter_date = "$filter_year-$filter_month";

// Lấy danh sách giải trình với bộ lọc
$stmt = $db->prepare("
    SELECT a.id, e.full_name, DATE(a.check_in) as date, a.explanation, a.explanation_status
    FROM attendance a
    JOIN employees e ON a.employee_id = e.id
    WHERE a.explanation IS NOT NULL
    AND DATE_FORMAT(a.check_in, '%Y-%m') = :filter_date
    ORDER BY a.check_in DESC
");
$stmt->execute([':filter_date' => $filter_date]);
$explanations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Xử lý phê duyệt/từ chối giải trình
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $attendance_id = $_POST['attendance_id'];
    $action = $_POST['action'];
    $hr_id = $_SESSION['user_id']; // Lấy ID của HR đang đăng nhập
    
    $new_status = ($action === 'approve') ? 'approved' : 'rejected';
    
    // Cập nhật trạng thái, người duyệt và thời gian duyệt
    $stmt = $db->prepare("
        UPDATE attendance 
        SET explanation_status = :status,
            approved_by = :approved_by,
            approved_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $new_status,
        ':approved_by' => $hr_id,
        ':id' => $attendance_id
    ]);
    
    header("Location: hr_attendance_explanations.php?month=$filter_month&year=$filter_year&success=Đã " . ($action === 'approve' ? 'duyệt' : 'từ chối') . " giải trình");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Danh Sách Giải Trình - HR</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-responsive { margin: 20px 0; }
        .status-pending { background-color: #ffc107 !important; color: black; }
        .status-approved { background-color: #28a745 !important; color: white; }
        .status-rejected { background-color: #dc3545 !important; color: white; }
        .filter-form { margin-bottom: 20px; }
        .content {
            flex: initial !important;
            padding: 0 !important;
            margin-top: 0 !important;
            transition: none !important;
        }
        @media print {
            .no-print { display: none !important; }
            .table-responsive { margin: 0; }
            .container { margin-top: 0; padding: 0; width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-center mb-0">Danh Sách Giải Trình Chấm Công</h2>
            <button onclick="printTable()" class="btn btn-primary no-print">In danh sách</button>
        </div>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success no-print"><?php echo $_GET['success']; ?></div>
        <?php endif; ?>

        <!-- Form lọc theo tháng và năm -->
        <div class="filter-form no-print">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="month" class="form-label">Tháng</label>
                    <select name="month" id="month" class="form-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo $filter_month == $m ? 'selected' : ''; ?>>
                                <?php echo "Tháng $m"; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="year" class="form-label">Năm</label>
                    <select name="year" id="year" class="form-select">
                        <?php 
                        $current_year = date('Y');
                        for ($y = $current_year - 5; $y <= $current_year + 5; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3 align-self-end">
                    <button type="submit" class="btn btn-primary">Lọc</button>
                </div>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead class="table-dark">
                    <tr>
                        <th>STT</th>
                        <th>Tên Nhân Viên</th>
                        <th>Ngày</th>
                        <th>Lý Do Giải Trình</th>
                        <th>Trạng Thái</th>
                        <th class="no-print">Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($explanations) > 0): ?>
                        <?php foreach ($explanations as $index => $exp): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($exp['full_name']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($exp['date'])); ?></td>
                                <td><?php echo htmlspecialchars($exp['explanation']); ?></td>
                                <td>
                                    <span class="badge <?php 
                                        echo $exp['explanation_status'] === 'pending' ? 'status-pending' : 
                                            ($exp['explanation_status'] === 'approved' ? 'status-approved' : 'status-rejected');
                                    ?>">
                                        <?php 
                                        echo $exp['explanation_status'] === 'pending' ? 'Đang chờ' : 
                                            ($exp['explanation_status'] === 'approved' ? 'Đã duyệt' : 'Từ chối');
                                        ?>
                                    </span>
                                </td>
                                <td class="no-print">
                                    <?php if ($exp['explanation_status'] === 'pending'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="attendance_id" value="<?php echo $exp['id']; ?>">
                                            <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Duyệt</button>
                                            <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm">Từ chối</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">Đã xử lý</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center">Không có giải trình nào trong tháng này.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function printTable() {
            window.print();
        }
    </script>
</body>
</html>
<?php
ob_end_flush();
?>