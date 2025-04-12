<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";
require_once '../layouts/header_employee.php';
require_once '../layouts/sidebar_employee.php';
require_once '../layouts/navbar_employee.php';

// Kiểm tra phiên làm việc và vai trò (giả sử role_id = 5 là nhân viên)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 5) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

// Lấy employee_id từ session
$employee_id = $_SESSION['user_id'];

$db = Database::getInstance()->getConnection();

// Lấy thông tin hiện tại của nhân viên
$current_info_stmt = $db->prepare("
    SELECT e.basic_salary AS salary, p.name AS position_name, d.name AS department_name, e.start_date
    FROM employees e
    LEFT JOIN positions p ON e.position_id = p.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.id = :employee_id
");
$current_info_stmt->execute([':employee_id' => $employee_id]);
$current_info = $current_info_stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_info) {
    $current_info = ['salary' => 0, 'position_name' => 'N/A', 'department_name' => 'N/A', 'start_date' => null];
}

$start_date = $current_info['start_date'] ? new DateTime($current_info['start_date']) : null;
$today = new DateTime();
$interval = $start_date ? $start_date->diff($today) : null;
$months_worked = $interval ? ($interval->y * 12) + $interval->m + ($interval->d / 30) : 0;
$can_promote = $months_worked >= 3;

// Lấy danh sách chức vụ và phòng ban để hiển thị trong form
$positions_stmt = $db->query("SELECT id, name FROM positions");
$positions = $positions_stmt->fetchAll(PDO::FETCH_ASSOC);

$departments_stmt = $db->query("SELECT id, name FROM departments");
$departments = $departments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Xử lý gửi yêu cầu
// Xử lý gửi yêu cầu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['submit_test']) && !isset($_POST['reset_test'])) {
    $request_type = $_POST['request_type'] ?? '';
    $reason = $_POST['reason'] ?? '';
    $desired_salary = $_POST['desired_salary'] ?? null;
    $desired_position_id = $_POST['desired_position_id'] ?? null;
    $desired_department_id = $_POST['desired_department_id'] ?? null;

    if (empty($request_type) || empty($reason)) {
        $error = "Vui lòng điền đầy đủ thông tin bắt buộc.";
    } elseif ($request_type === 'promotion' && !$can_promote) {
        $error = "Bạn cần làm việc ít nhất 3 tháng để đủ điều kiện thăng chức.";
    } else {
        // Lấy current_position từ thông tin nhân viên
        $current_position = $current_info['position_name'] ?? 'N/A';

        // Xác định requested_position dựa trên desired_position_id (mặc định là N/A nếu không có)
        $requested_position = 'N/A';
        if ($desired_position_id !== null && $desired_position_id !== '') {
            $position_check_stmt = $db->prepare("SELECT name FROM positions WHERE id = :id");
            $position_check_stmt->execute([':id' => $desired_position_id]);
            $position = $position_check_stmt->fetch(PDO::FETCH_ASSOC);
            if ($position) {
                $requested_position = $position['name'];
            } else {
                $desired_position_id = null; // Nếu không tồn tại, đặt về null
            }
        } else {
            $desired_position_id = null;
        }

        // Kiểm tra tính hợp lệ của desired_department_id
        if ($desired_department_id !== null && $desired_department_id !== '') {
            $department_check_stmt = $db->prepare("SELECT id FROM departments WHERE id = :id");
            $department_check_stmt->execute([':id' => $desired_department_id]);
            if (!$department_check_stmt->fetch()) {
                $error = "Phòng ban mong muốn không hợp lệ.";
                $desired_department_id = null; // Nếu không tồn tại, đặt về null và thông báo lỗi
            }
        } else {
            $desired_department_id = null;
        }

        if (!isset($error)) {
            $stmt = $db->prepare("
                INSERT INTO promotion_requests (
                    employee_id, request_type, reason, desired_salary, 
                    desired_position_id, desired_department_id, 
                    current_position, requested_position
                ) VALUES (
                    :employee_id, :request_type, :reason, :desired_salary, 
                    :desired_position_id, :desired_department_id, 
                    :current_position, :requested_position
                )
            ");
            $stmt->execute([
                ':employee_id' => $employee_id,
                ':request_type' => $request_type,
                ':reason' => $reason,
                ':desired_salary' => $desired_salary ?: null,
                ':desired_position_id' => $desired_position_id,
                ':desired_department_id' => $desired_department_id,
                ':current_position' => $current_position,
                ':requested_position' => $requested_position
            ]);
            $success = "Yêu cầu của bạn đã được gửi thành công!";
        }
    }
}

// Xử lý nộp bài test và chấm điểm tự động
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_test'])) {
    $test_id = $_POST['test_id'] ?? 0;
    $answer = trim($_POST['answer'] ?? '');

    $test_stmt = $db->prepare("
        SELECT correct_answer 
        FROM tests 
        WHERE id = :test_id AND employee_id = :employee_id
    ");
    $test_stmt->execute([':test_id' => $test_id, ':employee_id' => $employee_id]);
    $test = $test_stmt->fetch(PDO::FETCH_ASSOC);

    if ($test) {
        $correct_answer = trim($test['correct_answer']);
        $score = (strcasecmp($answer, $correct_answer) === 0) ? 10 : 0;

        $update_stmt = $db->prepare("
            UPDATE tests 
            SET employee_answer = :answer, status = 'graded', score = :score 
            WHERE id = :test_id AND employee_id = :employee_id
        ");
        $update_stmt->execute([
            ':answer' => $answer,
            ':score' => $score,
            ':test_id' => $test_id,
            ':employee_id' => $employee_id
        ]);
        $success = "Bài test của bạn đã được nộp và chấm điểm! Điểm: $score/10";
    } else {
        $error = "Không tìm thấy bài test.";
    }
}

// Xử lý làm lại bài test
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_test'])) {
    $test_id = $_POST['test_id'] ?? 0;

    $reset_stmt = $db->prepare("
        UPDATE tests 
        SET employee_answer = NULL, status = 'pending', score = NULL 
        WHERE id = :test_id AND employee_id = :employee_id
    ");
    $reset_stmt->execute([
        ':test_id' => $test_id,
        ':employee_id' => $employee_id
    ]);
    $success = "Bài test đã được reset. Bạn có thể làm lại!";
}

// Kiểm tra và tạo bài test mẫu nếu chưa có
$test_check_stmt = $db->prepare("SELECT id FROM tests WHERE employee_id = :employee_id");
$test_check_stmt->execute([':employee_id' => $employee_id]);
if (!$test_check_stmt->fetch()) {
    $test_stmt = $db->prepare("
        INSERT INTO tests (employee_id, test_type, question, options, correct_answer, status)
        VALUES (:employee_id, 'multiple_choice', :question, :options, :correct_answer, 'pending')
    ");
    $test_stmt->execute([
        ':employee_id' => $employee_id,
        ':question' => 'Kế toán trưởng cần làm gì để quản lý tài chính hiệu quả?',
        ':options' => json_encode(['A. Lập báo cáo tài chính', 'B. Kiểm tra hóa đơn', 'C. Cả hai', 'D. Không làm gì']),
        ':correct_answer' => 'C'
    ]);
}

// Lấy danh sách yêu cầu và bài test
$requests_stmt = $db->prepare("
    SELECT pr.*, p.name AS position_name, d.name AS department_name, t.id AS test_id, t.question, t.options, t.status AS test_status, t.employee_answer, t.score
    FROM promotion_requests pr
    LEFT JOIN positions p ON pr.desired_position_id = p.id
    LEFT JOIN departments d ON pr.desired_department_id = d.id
    LEFT JOIN tests t ON pr.employee_id = t.employee_id
    WHERE pr.employee_id = :employee_id
    ORDER BY pr.created_at DESC
");
$requests_stmt->execute([':employee_id' => $employee_id]);
$requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="/HRMpv/public/css/styles.css">

<style>
.promotion-container {
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
    min-height: calc(100vh - 200px);
    margin-left: 250px;
    transition: margin-left 0.3s ease;
}

.promotion-container h2 {
    margin-bottom: 30px;
    text-align: center;
    color: #333;
    font-size: 2rem;
}

.current-info {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.current-info p {
    margin: 5px 0;
    color: #374151;
}

.promotion-form {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #333;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 1rem;
}

.form-group textarea {
    height: 100px;
    resize: vertical;
}

.btn-submit {
    background: #3b82f6;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 1rem;
    transition: background 0.3s ease;
}

.btn-submit:hover {
    background: #2563eb;
}

.btn-reset {
    background: #f39c12;
    color: white;
    padding: 5px 10px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9rem;
    transition: background 0.3s ease;
    margin-left: 10px;
}

.btn-reset:hover {
    background: #e67e22;
}

.requests-list {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.requests-list table {
    width: 100%;
    border-collapse: collapse;
}

.requests-list th,
.requests-list td {
    padding: 12px;
    border: 1px solid #e5e7eb;
    text-align: left;
}

.requests-list th {
    background: #f8f9fa;
    font-weight: 600;
    color: #374151;
}

.requests-list tbody tr:hover {
    background: #f9fafb;
}

.status-pending {
    color: #f39c12;
    font-weight: 500;
}

.status-approved {
    color: #28a745;
    font-weight: 500;
}

.status-rejected {
    color: #e74c3c;
    font-weight: 500;
}

.alert {
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid transparent;
    border-radius: 4px;
    text-align: center;
}

.alert-success {
    color: #155724;
    background-color: #d4edda;
    border-color: #c3e6cb;
}

.alert-danger {
    color: #721c24;
    background-color: #f8d7da;
    border-color: #f5c6cb;
}

.note {
    color: #555;
    font-style: italic;
    margin-top: 10px;
    text-align: center;
}

.test-section {
    margin-top: 20px;
    padding: 15px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.test-section label {
    display: block;
    margin: 5px 0;
}

/* Responsive Design */
@media (max-width: 768px) {
    .promotion-container {
        margin-left: 0;
        padding: 10px;
    }

    .promotion-container h2 {
        font-size: 1.5rem;
    }

    .promotion-form,
    .requests-list {
        padding: 15px;
    }

    .requests-list th,
    .requests-list td {
        padding: 10px;
        font-size: 0.9rem;
    }
}

@media (max-width: 576px) {
    .promotion-container {
        padding: 5px;
    }

    .promotion-container h2 {
        font-size: 1.25rem;
    }

    .form-group label {
        font-size: 0.9rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        font-size: 0.9rem;
    }

    .btn-submit {
        padding: 8px 16px;
        font-size: 0.9rem;
    }

    .requests-list th,
    .requests-list td {
        padding: 8px;
        font-size: 0.85rem;
    }
}
</style>

<div class="promotion-container">
    <h2>Gửi Yêu Cầu Thăng Chức/Tăng Lương/Đổi Chức Vụ/Chuyển Phòng Ban</h2>

    <!-- Hiển thị thông tin hiện tại -->
    <div class="current-info">
        <p><strong>Chức vụ hiện tại:</strong> <?= htmlspecialchars($current_info['position_name'] ?? 'N/A') ?></p>
        <p><strong>Phòng ban hiện tại:</strong> <?= htmlspecialchars($current_info['department_name'] ?? 'N/A') ?></p>
          </div>

    <!-- Hiển thị thông báo -->
    <?php if (isset($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php elseif (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Form gửi yêu cầu -->
    <div class="promotion-form">
        <form method="POST">
            <div class="form-group">
                <label for="request_type">Loại yêu cầu <span style="color: red;">*</span></label>
                <select name="request_type" id="request_type" required>
                    <option value="">Chọn loại yêu cầu</option>
                    <option value="promotion">Thăng chức</option>
                    <option value="salary_increase">Tăng lương</option>
                    <option value="position_change">Đổi chức vụ</option>
                    <option value="department_change">Chuyển phòng ban</option>
                </select>
            </div>
            <div class="form-group">
                <label for="reason">Lý do <span style="color: red;">*</span></label>
                <textarea name="reason" id="reason" placeholder="Nhập lý do..." required></textarea>
            </div>
            <div class="form-group">
                <label for="desired_salary">Mức lương mong muốn (nếu có)</label>
                <input type="number" name="desired_salary" id="desired_salary" placeholder="Nhập mức lương mong muốn (VNĐ)" min="0">
            </div>
            <div class="form-group">
                <label for="desired_position_id">Chức vụ mong muốn (nếu có)</label>
                <select name="desired_position_id" id="desired_position_id">
                    <option value="">Chọn chức vụ</option>
                    <?php foreach ($positions as $position): ?>
                        <option value="<?= $position['id'] ?>"><?= htmlspecialchars($position['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="desired_department_id">Phòng ban mong muốn (nếu có)</label>
                <select name="desired_department_id" id="desired_department_id">
                    <option value="">Chọn phòng ban</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= $department['id'] ?>"><?= htmlspecialchars($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-submit">Gửi Yêu Cầu</button>
        </form>
        <p class="note">Lưu ý: Để được thăng chức, bạn cần làm việc ít nhất 3 tháng. Bạn có thể làm bài test bất kỳ lúc nào để kiểm tra năng lực.</p>
    </div>

    <!-- Danh sách yêu cầu đã gửi và bài test -->
    <?php if (!empty($requests)): ?>
        <div class="requests-list">
            <h3>Danh sách yêu cầu đã gửi và bài test</h3>
            <table>
                <thead>
                    <tr>
                        <th>Loại yêu cầu</th>
                        <th>Lý do</th>
                        <th>Mức lương mong muốn</th>
                        <th>Chức vụ mong muốn</th>
                        <th>Phòng ban mong muốn</th>
                        <th>Trạng thái</th>
                        <th>Ngày gửi</th>
                        <th>Bài test</th>
                        <th>Điểm</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <tr>
                            <td>
                                <?php
                                switch ($request['request_type']) {
                                    case 'promotion': echo 'Thăng chức'; break;
                                    case 'salary_increase': echo 'Tăng lương'; break;
                                    case 'position_change': echo 'Đổi chức vụ'; break;
                                    case 'department_change': echo 'Chuyển phòng ban'; break;
                                    default: echo 'N/A';
                                }
                                ?>
                            </td>
                            <td><?= htmlspecialchars($request['reason'] ?? 'N/A') ?></td>
                            <td><?= $request['desired_salary'] ? number_format($request['desired_salary'], 0, ',', '.') . ' VNĐ' : 'N/A' ?></td>
                            <td><?= htmlspecialchars($request['position_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($request['department_name'] ?? 'N/A') ?></td>
                            <td class="status-<?= $request['status'] ?>">
                                <?php
                                switch ($request['status']) {
                                    case 'pending': echo 'Đang chờ duyệt'; break;
                                    case 'approved': echo 'Đã phê duyệt'; break;
                                    case 'rejected': echo 'Đã từ chối'; break;
                                    default: echo 'N/A';
                                }
                                ?>
                            </td>
                            <td>
    <?php if ($request['test_id'] && $request['test_status'] === 'pending'): ?>
        <div class="test-section">
            <form method="POST">
                <p><strong>Câu hỏi:</strong> <?= htmlspecialchars($request['question']) ?></p>
                <?php $options = json_decode($request['options'], true); ?>
                <?php foreach ($options as $option): ?>
                    <?php $option_value = substr($option, 0, 1); // Lấy ký tự đầu tiên (A, B, C, D) ?>
                    <label><input type="radio" name="answer" value="<?= htmlspecialchars($option_value) ?>" required> <?= htmlspecialchars($option) ?></label>
                <?php endforeach; ?>
                <input type="hidden" name="test_id" value="<?= $request['test_id'] ?>">
                <input type="hidden" name="submit_test" value="1">
                <button type="submit" class="btn-submit">Nộp bài</button>
            </form>
        </div>
    <?php elseif ($request['test_status'] === 'submitted'): ?>
        Đã nộp (Chưa chấm)
    <?php elseif ($request['test_status'] === 'graded'): ?>
        Đã chấm
        <form method="POST" style="display: inline;">
            <input type="hidden" name="test_id" value="<?= $request['test_id'] ?>">
            <input type="hidden" name="reset_test" value="1">
            <button type="submit" class="btn-reset">Làm lại</button>
        </form>
    <?php else: ?>
        Chưa có bài test
    <?php endif; ?>
</td>
                            <td>
                                <?= ($request['test_status'] === 'graded' && $request['score'] !== null) ? $request['score'] . '/10' : 'N/A' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
require_once '../layouts/footer.php';
?>
