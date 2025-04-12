<?php
require_once __DIR__ . "/../../core/Database.php";

$db = Database::getInstance()->getConnection();

// Cleanup old records (older than 1 day)
$cleanup_query = "DELETE FROM login_history WHERE login_time < DATE_SUB(NOW(), INTERVAL 1 DAY)";
$db->query($cleanup_query);

// Xử lý filter
$user_id = isset($_GET['user_id']) ? $_GET['user_id'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Lấy danh sách users cho filter
$users = $db->query("SELECT id, email FROM users ORDER BY email")->fetchAll(PDO::FETCH_ASSOC);

// Xây dựng query với filter
$query = "
    SELECT lh.*, u.email, e.full_name,
           CASE 
               WHEN lh.logout_time IS NULL AND lh.session_status = 'expired' THEN 'Phiên hết hạn'
               WHEN lh.logout_time IS NULL AND lh.session_status = 'active' THEN 'Đang hoạt động'
               ELSE lh.logout_time 
           END as logout_status,
           TIMESTAMPDIFF(MINUTE, lh.login_time, COALESCE(lh.logout_time, NOW())) as session_duration
    FROM login_history lh
    JOIN users u ON lh.user_id = u.id
    LEFT JOIN employees e ON u.email = e.email
    WHERE 1=1
";

$params = [];

if ($user_id) {
    $query .= " AND lh.user_id = :user_id";
    $params[':user_id'] = $user_id;
}

if ($date_from) {
    $query .= " AND DATE(lh.login_time) >= :date_from";
    $params[':date_from'] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(lh.login_time) <= :date_to";
    $params[':date_to'] = $date_to;
}

$query .= " ORDER BY lh.login_time DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hàm format thời gian hoạt động
function formatDuration($minutes) {
    if ($minutes < 1) {
        return "< 1 phút";
    }
    if ($minutes < 60) {
        return $minutes . " phút";
    }
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return $hours . " giờ " . ($mins > 0 ? $mins . " phút" : "");
}
?>

<!DOCTYPE html>
<html 
lang="vi">
<head>
<button onclick="history.back()" class="btn btn-secondary mb-3">Quay lại</button>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lịch Sử Đăng Nhập - HRM System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .history-container {
            padding: 20px;
        }
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .history-table {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .status-active {
            color: #28a745;
            font-weight: 500;
        }
        .status-expired {
            color: #dc3545;
            font-weight: 500;
        }
        .session-duration {
            color: #6c757d;
            font-size: 0.9em;
        }
        .info-badge {
            font-size: 0.8em;
            padding: 0.3em 0.6em;
            border-radius: 3px;
            background: #e9ecef;
            color: #495057;
        }
    </style>
</head>
<body>

<div class="history-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Lịch Sử Đăng Nhập</h2>
        <div class="info-badge">
            Lịch sử được lưu trong 24 giờ gần nhất
        </div>
    </div>

    <div class="filter-section">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Người Dùng:</label>
                <select name="user_id" class="form-select">
                    <option value="">Tất cả người dùng</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $user_id == $user['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Từ Ngày:</label>
                <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label">Đến Ngày:</label>
                <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
            </div>

            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">Lọc</button>
            </div>
        </form>
    </div>

    <div class="history-table">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Người Dùng</th>
                    <th>Email</th>
                    <th>Thời Gian Đăng Nhập</th>
                    <th>Thời Gian Đăng Xuất</th>
                    <th>Thời Gian Hoạt Động</th>
                    <th>Địa Chỉ IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $log): ?>
                    <tr>
                        <td><?= $log['id'] ?></td>
                        <td><?= htmlspecialchars($log['full_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($log['email']) ?></td>
                        <td><?= date('d/m/Y H:i:s', strtotime($log['login_time'])) ?></td>
                        <td>
                            <?php if ($log['logout_status'] == 'Đang hoạt động'): ?>
                                <span class="status-active">Đang hoạt động</span>
                            <?php elseif ($log['logout_status'] == 'Phiên hết hạn'): ?>
                                <span class="status-expired">Phiên hết hạn</span>
                            <?php else: ?>
                                <?= date('d/m/Y H:i:s', strtotime($log['logout_status'])) ?>
                            <?php endif; ?>
                        </td>
                        <td class="session-duration">
                            <?= formatDuration($log['session_duration']) ?>
                        </td>
                        <td><?= htmlspecialchars($log['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>