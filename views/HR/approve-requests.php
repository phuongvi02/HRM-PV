<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";
require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';

// Kiểm tra quyền truy cập (giả sử role_id = 2 là quản lý)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

$db = Database::getInstance()->getConnection();

// Lấy danh sách yêu cầu nghỉ phép và làm thêm giờ chờ duyệt
$leave_query = "
    SELECT lr.*, e.full_name, d.name AS department_name, 'leave' AS request_type
    FROM leave_requests AS lr
    JOIN employees AS e ON lr.employee_id = e.id
    LEFT JOIN departments AS d ON e.department_id = d.id
    WHERE lr.status = 'Chờ duyệt'
    ORDER BY lr.created_at DESC
";

$overtime_query = "
    SELECT ot.*, e.full_name, d.name AS department_name, 'overtime' AS request_type
    FROM overtime_requests AS ot
    JOIN employees AS e ON ot.employee_id = e.id
    LEFT JOIN departments AS d ON e.department_id = d.id
    WHERE ot.status = 'Chờ duyệt'
    ORDER BY ot.created_at DESC
";

$leaveRequests = $db->query($leave_query)->fetchAll(PDO::FETCH_ASSOC);
$overtimeRequests = $db->query($overtime_query)->fetchAll(PDO::FETCH_ASSOC);
$allRequests = array_merge($leaveRequests, $overtimeRequests);

// Kiểm tra nếu 'created_at' có tồn tại trong dữ liệu, nếu không thì dùng thời gian khác
usort($allRequests, function($a, $b) {
    $timeA = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
    $timeB = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
    return $timeB - $timeA;
});
ob_end_flush();

?>


<link rel="stylesheet" href="/HRMpv/public/css/styles.css">
<style>
.approve-container {
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
    min-height: calc(100vh - 200px);
}

.approve-container h2 {
    margin-bottom: 30px;
    text-align: center;
    color: #333;
}

.request-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
}

.request-info {
    flex: 1;
    min-width: 300px;
    margin-bottom: 15px;
}

.request-info h4 {
    margin-bottom: 10px;
    color: #007bff;
}

.request-info p {
    margin: 5px 0;
    color: #555;
}

.request-info p strong {
    color: #333;
}

.request-info .leave-details,
.request-info .overtime-details {
    margin-top: 5px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    min-width: 200px;
}

.alert {
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid transparent;
    border-radius: 4px;
    text-align: center;
}

.alert-info {
    color: #31708f;
    background-color: #d9edf7;
    border-color: #bce8f1;
}

.btn {
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    border: none;
    font-weight: 500;
    transition: background-color 0.3s;
}

.btn-success {
    background-color: #28a745;
    color: white;
}

.btn-success:hover {
    background-color: #218838;
}

.btn-danger {
    background-color: #dc3545;
    color: white;
}

.btn-danger:hover {
    background-color: #c82333;
}

@media (max-width: 768px) {
    .request-card {
        flex-direction: column;
        align-items: flex-start;
    }

    .action-buttons {
        margin-top: 15px;
        justify-content: flex-start;
    }
}
/* Ghi đè để bỏ các thuộc tính của .content */
.content {
    flex: initial !important;
    padding: 0 !important;
    margin-top: 0 !important;
    transition: none !important;
}
</style>

<div class="approve-container">
    <h2>Duyệt Yêu Cầu Nghỉ Phép & Làm Thêm Giờ</h2>
    
    <?php if (empty($allRequests)): ?>
        <div class="alert alert-info">Không có yêu cầu nào cần duyệt.</div>
    <?php else: ?>
        <?php foreach ($allRequests as $request): ?>
            <div class="request-card" id="request-<?= $request['id'] ?>">
                <div class="request-info">
                    <h4><?= htmlspecialchars($request['full_name']) ?></h4>
                    <p><strong>Phòng ban:</strong> <?= htmlspecialchars($request['department_name'] ?: 'N/A') ?></p>
                    <?php if ($request['request_type'] === 'leave'): ?>
                        <div class="leave-details">
                            <p><strong>Thời gian nghỉ:</strong> 
                                <?= date('d/m/Y', strtotime($request['start_date'])) ?> - 
                                <?= date('d/m/Y', strtotime($request['end_date'])) ?>
                            </p>
                            <p><strong>Số ngày:</strong> 
                                <?php
                                    $start = new DateTime($request['start_date']);
                                    $end = new DateTime($request['end_date']);
                                    $days = $start->diff($end)->days + 1;
                                    echo $days . ' ngày';
                                ?>
                            </p>
                            <p><strong>Lý do:</strong> <?= htmlspecialchars($request['reason']) ?></p>
                        </div>
                    <?php elseif ($request['request_type'] === 'overtime'): ?>
                        <div class="overtime-details">
                            <p><strong>Ngày làm thêm:</strong> <?= date('d/m/Y', strtotime($request['overtime_date'])) ?></p>
                            <p><strong>Số giờ:</strong> <?= htmlspecialchars($request['hours']) ?> giờ</p>
                            <p><strong>Lý do:</strong> <?= htmlspecialchars($request['reason']) ?></p>
                        </div>
                    <?php endif; ?>
                    <p><strong>Ngày yêu cầu:</strong> 
                        <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                    </p>
                    <p><strong>Trạng thái:</strong> <?= htmlspecialchars($request['status']) ?></p>
                </div>
                
                <div class="action-buttons">
                    <button type="button" class="btn btn-success approve-btn" data-id="<?= $request['id'] ?>" data-type="<?= $request['request_type'] ?>">
                        Duyệt
                    </button>
                    <button type="button" class="btn btn-danger reject-btn" data-id="<?= $request['id'] ?>" data-type="<?= $request['request_type'] ?>">
                        Từ chối
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    function handleRequest(requestId, requestType, action) {
        $.ajax({
            url: 'process_request.php', // Tệp xử lý chung cho cả leave và overtime
            type: 'POST',
            data: {
                request_id: requestId,
                request_type: requestType,
                action: action
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    window.location.href = 'leave-history.php'; // Chuyển hướng đến lịch sử (có thể thay bằng trang khác)
                } else {
                    alert('Có lỗi xảy ra: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                console.log('Lỗi AJAX: ' + error);
                alert('Có lỗi xảy ra khi kết nối đến máy chủ. Vui lòng kiểm tra console để biết chi tiết.');
            }
        });
    }

    $('.approve-btn').click(function() {
        if (confirm('Bạn có chắc chắn muốn duyệt yêu cầu này?')) {
            handleRequest($(this).data('id'), $(this).data('type'), 'approve');
        }
    });

    $('.reject-btn').click(function() {
        if (confirm('Bạn có chắc chắn muốn từ chối yêu cầu này?')) {
            handleRequest($(this).data('id'), $(this).data('type'), 'reject');
        }
    });
});
</script>

<?php require_once '../layouts/footer.php'; ?>