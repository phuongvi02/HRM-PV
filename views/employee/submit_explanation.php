<?php
ob_start(); // Bắt đầu bộ đệm đầu ra
session_start();

require_once __DIR__ . "/../../core/Database.php";
require_once '../layouts/header_employee.php';
require_once '../layouts/sidebar_employee.php';
require_once '../layouts/navbar_employee.php';
// Kiểm tra quyền truy cập (chỉ nhân viên với role_id = 5)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 5) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

$db = Database::getInstance()->getConnection();
$employee_id = $_SESSION['user_id'];

// Xử lý nộp giải trình qua POST
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $attendance_id = intval($_POST['attendance_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($attendance_id > 0 && !empty($reason) && strlen($reason) >= 10) {
        try {
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM explanations WHERE attendance_id = ?");
            $checkStmt->execute([$attendance_id]);
            if ($checkStmt->fetchColumn() > 0) {
                $error_message = "Bản ghi này đã được nộp giải trình!";
            } else {
                $stmt = $db->prepare("INSERT INTO explanations (attendance_id, employee_id, reason, status) VALUES (?, ?, ?, 'pending')");
                $stmt->execute([$attendance_id, $employee_id, $reason]);
                $success_message = "Nộp giải trình thành công!";
            }
        } catch (PDOException $e) {
            $error_message = "Lỗi khi nộp giải trình: " . $e->getMessage();
        }
    } else {
        $error_message = "Vui lòng nhập lý do giải trình (tối thiểu 10 ký tự)!";
    }
}

// Lấy danh sách bản ghi chấm công cần giải trình
try {
    $stmt = $db->prepare("
        SELECT a.id, a.date, a.check_in, a.check_out, a.has_explanation, e.status 
        FROM attendance a 
        LEFT JOIN explanations e ON a.id = e.attendance_id 
        WHERE a.employee_id = ? AND a.has_explanation = 1
    ");
    $stmt->execute([$employee_id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Lỗi khi lấy dữ liệu: " . $e->getMessage();
    $records = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lịch sự Giải Trình </title>
    <link rel="stylesheet" href="/HRMpv/public/css/index_nv.css">
</head>
<body>
    <div class="container">
        <header class="header">
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
                Lịch sự Giải Trình
            </h1>
        </header>

        <!-- Hiển thị thông báo từ PHP -->
        <div id="messages">
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>
        </div>

        <div class="table-container">
            <table class="records-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="records-body">
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="6" class="text-center">Không có bản ghi nào cần giải trình.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <?php
                            $status = $record['status'] ?? 'Chưa nộp';
                            $badgeClass = '';
                            switch ($status) {
                                case 'pending':
                                    $badgeClass = 'badge-pending';
                                    break;
                                case 'approved':
                                    $badgeClass = 'badge-approved';
                                    break;
                                case 'rejected':
                                    $badgeClass = 'badge-rejected';
                                    break;
                                default:
                                    $badgeClass = 'badge-default';
                            }
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($record['id']) ?></td>
                                <td><?= htmlspecialchars(date('d/m/Y', strtotime($record['date']))) ?></td>
                                <td><?= htmlspecialchars($record['check_in'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($record['check_out'] ?? 'Chưa checkout') ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span></td>
                                <td>
                                    <?php if (!$record['status'] || $record['status'] === 'rejected'): ?>
                                        <button class="action-btn" data-attendance-id="<?= $record['id'] ?>" data-date="<?= date('d/m/Y', strtotime($record['date'])) ?>">Nộp giải trình</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal cho nộp giải trình -->
    <div id="explanation-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modal-title">Submit Explanation</h2>
                <button class="close-button">×</button>
            </div>
            <form id="explanation-form" method="POST" onsubmit="return confirm('Bạn chắc chắn muốn nộp giải trình này?');">
                <input type="hidden" id="attendance-id" name="attendance_id">
                <div class="form-group">
                    <label for="explanation">Explanation:</label>
                    <textarea id="explanation" name="reason" required></textarea>
                </div>
                <div class="button-group">
                    <button type="submit" class="submit-btn">Submit</button>
                    <button type="button" class="cancel-btn">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // JavaScript để xử lý modal
        const modal = document.getElementById('explanation-modal');
        const closeButton = document.querySelector('.close-button');
        const cancelButton = document.querySelector('.cancel-btn');
        const actionButtons = document.querySelectorAll('.action-btn');
        const form = document.getElementById('explanation-form');
        const attendanceIdInput = document.getElementById('attendance-id');
        const modalTitle = document.getElementById('modal-title');

        // Mở modal khi nhấn nút "Nộp giải trình"
        actionButtons.forEach(button => {
            button.addEventListener('click', () => {
                const attendanceId = button.getAttribute('data-attendance-id');
                const date = button.getAttribute('data-date');
                attendanceIdInput.value = attendanceId;
                modalTitle.textContent = `Submit Explanation - Date ${date}`;
                modal.style.display = 'block';
            });
        });

        // Đóng modal
        closeButton.addEventListener('click', () => {
            modal.style.display = 'none';
        });

        cancelButton.addEventListener('click', () => {
            modal.style.display = 'none';
        });

        // Đóng modal khi nhấn ngoài
        window.addEventListener('click', (event) => {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
    </script>

    <!-- CSS nội tuyến để thay thế style.css (nếu cần) -->
    <style>
:root {
    --primary-color: #2563eb;
    --danger-color: #dc2626;
    --success-color: #16a34a;
    --warning-color: #ca8a04;
    --background-color: #f3f4f6;
    --surface-color: #ffffff;
    --text-color: #1f2937;
    --border-color: #e5e7eb;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: system-ui, -apple-system, sans-serif;
    background-color: var(--background-color);
    color: var(--text-color);
    line-height: 1.5;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem;
    margin-left: 250px; /* Đảm bảo không bị che bởi sidebar */
    transition: margin-left 0.3s ease;
}

.header {
    margin-bottom: 2rem;
}

.header h1 {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.875rem;
    font-weight: 600;
}

.table-container {
    background-color: var(--surface-color);
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.records-table {
    width: 100%;
    border-collapse: collapse;
}

.records-table th,
.records-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.records-table th {
    background-color: #f8fafc;
    font-weight: 600;
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 500;
}

.badge-pending {
    background-color: #fef3c7;
    color: var(--warning-color);
}

.badge-approved {
    background-color: #dcfce7;
    color: var(--success-color);
}

.badge-rejected {
    background-color: #fee2e2;
    color: var(--danger-color);
}

.badge-not-submitted {
    background-color: #f3f4f6;
    color: #6b7280;
}

.button {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: background-color 0.2s;
}

.button-primary {
    background-color: var(--primary-color);
    color: white;
}

.button-primary:hover {
    background-color: #1d4ed8;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}

.modal-content {
    background-color: var(--surface-color);
    border-radius: 0.5rem;
    width: 100%;
    max-width: 500px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h2 {
    font-size: 1.25rem;
    font-weight: 600;
}

.close-button {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #6b7280;
}

.form-group {
    padding: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.form-group textarea {
    width: 100%;
    min-height: 120px;
    padding: 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 0.375rem;
    resize: vertical;
}

.button-group {
    display: flex;
    gap: 0.75rem;
    padding: 1rem;
    border-top: 1px solid var(--border-color);
}

.submit-btn,
.cancel-btn {
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
}

.submit-btn {
    background-color: var(--primary-color);
    color: white;
}

.cancel-btn {
    background-color: #e5e7eb;
    color: #374151;
}

.message {
    margin-bottom: 1rem;
    padding: 1rem;
    border-radius: 0.375rem;
    font-weight: 500;
}

.message-success {
    background-color: #dcfce7;
    color: var(--success-color);
}

.message-error {
    background-color: #fee2e2;
    color: var(--danger-color);
}

/* Responsive Design */
@media (min-width: 769px) {
    .container {
        margin-left: 250px; /* Đảm bảo không bị che bởi sidebar */
    }
}

@media (max-width: 768px) {
    .container {
        margin-left: 0; /* Ẩn sidebar trên mobile */
        padding: 1rem;
    }

    .header h1 {
        font-size: 1.5rem;
    }

    .records-table th,
    .records-table td {
        padding: 0.75rem;
        font-size: 0.9rem;
    }

    .modal-content {
        width: 90%;
        max-width: 400px;
    }

    .form-group textarea {
        min-height: 100px;
    }
}

@media (max-width: 576px) {
    .container {
        padding: 0.5rem;
    }

    .header h1 {
        font-size: 1.25rem;
    }

    .records-table th,
    .records-table td {
        padding: 0.5rem;
        font-size: 0.85rem;
    }

    .button {
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
    }

    .modal-content {
        width: 95%;
        max-width: 350px;
    }

    .modal-header h2 {
        font-size: 1.1rem;
    }

    .form-group {
        padding: 0.75rem;
    }

    .button-group {
        flex-direction: column;
        gap: 0.5rem;
    }

    .submit-btn,
    .cancel-btn {
        width: 100%;
        padding: 0.75rem;
    }
}
</style>
</body>
</html>
<script></script>
<?php ob_end_flush(); ?>