<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";
require_once '../layouts/header_employee.php';
require_once '../layouts/sidebar_employee.php';
require_once '../layouts/navbar_employee.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

$db = Database::getInstance()->getConnection();
$employee_id = $_SESSION['user_id'] ?? null;

if (!$employee_id) {
    header('Location: /HRMpv/views/login.php');
    exit();
}

// Lấy thông tin nhân viên
$query = "SELECT full_name, department_id FROM employees WHERE id = :employee_id";
$stmt = $db->prepare($query);
$stmt->execute([':employee_id' => $employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// Lấy thông tin check_in và check_out hôm nay
$query = "SELECT id, check_in, check_out, status, approval_note, explanation, explanation_status, 
                 explanation_submitted_at, explanation_approved_at, explanation_note 
          FROM attendance 
          WHERE employee_id = :employee_id AND DATE(check_in) = CURDATE()";
$stmt = $db->prepare($query);
$stmt->execute([':employee_id' => $employee_id]);
$attendance = $stmt->fetch(PDO::FETCH_ASSOC);

$attendance_id = !empty($attendance['id']) ? $attendance['id'] : null;
$check_in = !empty($attendance['check_in']) ? new DateTime($attendance['check_in']) : null;
$check_out = !empty($attendance['check_out']) ? new DateTime($attendance['check_out']) : null;
$status = $attendance['status'] ?? null;
$approval_note = $attendance['approval_note'] ?? '';
$explanation = $attendance['explanation'] ?? '';
$explanation_status = $attendance['explanation_status'] ?? 'pending';
$hoursWorked = 0;

$currentTime = new DateTime();
if ($check_in) {
    if ($check_out) {
        $interval = $check_in->diff($check_out);
        $hoursWorked = $interval->h + ($interval->i / 60);
    } else {
        $interval = $check_in->diff($currentTime);
        $hoursWorked = $interval->h + ($interval->i / 60);
    }
}

$alreadyCheckedOut = !empty($check_out);

// Xử lý checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkout') {
    if (!$attendance_id) {
        $error_message = "Bạn chưa check-in hôm nay!";
    } elseif ($alreadyCheckedOut) {
        $error_message = "Bạn đã checkout trước đó rồi!";
    } elseif ($status !== 'approved_checkin' && $status !== 'approved') {
        $error_message = "Check-in của bạn chưa được HR phê duyệt hoặc bị từ chối. Không thể checkout!";
    } else {
        try {
            $current_time = date('Y-m-d H:i:s');
            $query = "UPDATE attendance SET check_out = :check_out WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':check_out' => $current_time,
                ':id' => $attendance_id
            ]);
            
            $check_out = new DateTime($current_time);
            $interval = $check_in->diff($check_out);
            $hoursWorked = $interval->h + ($interval->i / 60);
            
            $alreadyCheckedOut = true;
            $success_message = "Checkout thành công lúc " . date('H:i:s', strtotime($current_time)) . ". Đang chờ HR phê duyệt cuối ngày.";
            
            if ($hoursWorked < 8) {
                $warning_message = "Bạn đã làm việc " . number_format($hoursWorked, 2) . " giờ (dưới 8 tiếng). Vui lòng gửi giải trình nếu cần.";
            }
        } catch (PDOException $e) {
            $error_message = "Lỗi cơ sở dữ liệu: " . $e->getMessage();
        }
    }
}

// Xử lý gửi giải trình
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_explanation') {
    $explanation_text = trim($_POST['explanation'] ?? '');
    if ($explanation_text && $attendance_id) {
        try {
            $query = "UPDATE attendance 
                      SET explanation = :explanation, 
                          explanation_status = 'pending', 
                          explanation_submitted_at = NOW() 
                      WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':explanation' => $explanation_text,
                ':id' => $attendance_id
            ]);
            $explanation = $explanation_text;
            $explanation_status = 'pending';
            $success_message = "Giải trình của bạn đã được gửi lúc " . date('H:i:s') . ". Đang chờ HR xem xét.";
        } catch (PDOException $e) {
            $error_message = "Lỗi khi gửi giải trình: " . $e->getMessage();
        }
    } else {
        $error_message = "Vui lòng nhập nội dung giải trình!";
    }
}
?>

<link rel="stylesheet" href="/HRMpv/public/css/index_nv.css">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-danger text-white">
                    <h2 class="mb-0 text-center">Chấm Công - Checkout</h2>
                </div>
                <div class="card-body text-center">
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success">
                            <?= $success_message ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger">
                            <?= $error_message ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($warning_message)): ?>
                        <div class="alert alert-warning">
                            <?= $warning_message ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="time-display mb-4" id="currentTime"></div>
                    
                    <div class="employee-info mb-4">
                        <h4><?= htmlspecialchars($employee['full_name'] ?? 'Nhân viên') ?></h4>
                        <p class="text-muted">Giờ làm việc: 08:00 - 17:00 (Yêu cầu đủ 8 tiếng mỗi ngày)</p>
                    </div>
                    
                    <?php if (!$check_in): ?>
                        <div class="alert alert-warning">
                            <p>Bạn chưa check-in hôm nay!</p>
                            <a href="checkin.php" class="btn btn-success mt-2">Đi đến Check-in</a>
                        </div>
                    <?php else: ?>
                        <div class="work-info mb-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h5 class="card-title">Thời gian Check-in</h5>
                                            <p class="card-text fs-4"><?= $check_in->format('H:i:s') ?></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h5 class="card-title">Thời gian làm việc</h5>
                                            <p class="card-text fs-4" id="hoursWorked"><?= number_format($hoursWorked, 2) ?> giờ</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($status === 'pending'): ?>
                            <div class="alert alert-warning">
                                <p class="mb-0">Check-in lúc <strong><?= $check_in->format('H:i:s') ?></strong> đang xử lý bởi HR.</p>
                                <p class="text-muted mt-2">Bạn không thể checkout cho đến khi check-in được phê duyệt.</p>
                            </div>
                        <?php elseif ($status === 'rejected'): ?>
                            <div class="alert alert-danger">
                                <p class="mb-0">Check-in lúc <strong><?= $check_in->format('H:i:s') ?></strong> đã bị HR từ chối.</p>
                                <p class="text-muted mt-2">Bạn không được phép checkout.</p>
                                <?php if ($approval_note): ?>
                                    <p class="mt-2"><strong>Ghi chú từ HR:</strong> <?= htmlspecialchars($approval_note) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($alreadyCheckedOut): ?>
                            <div class="alert alert-info">
                                <p class="mb-0">Bạn đã checkout lúc <strong><?= $check_out->format('H:i:s') ?></strong></p>
                                <p class="mb-0">Tổng thời gian làm việc: <strong><?= number_format($hoursWorked, 2) ?> giờ</strong></p>
                                <p class="mb-0">Trạng thái: 
                                    <span class="badge <?= $status == 'approved' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                        <?= $status == 'approved' ? 'Đã duyệt toàn bộ' : 'Đã duyệt Check-in, chờ duyệt Check-out' ?>
                                    </span>
                                </p>
                                <?php if ($approval_note): ?>
                                    <p class="mt-2"><strong>Ghi chú từ HR:</strong> <?= htmlspecialchars($approval_note) ?></p>
                                <?php endif; ?>
                                <?php if ($explanation): ?>
                                    <div class="explanation-details mt-3">
                                        <h6 class="mb-2">Bản Giải Trình:</h6>
                                        <div class="explanation-box">
                                            <p><strong>Lý do:</strong> <?= htmlspecialchars($explanation) ?></p>
                                            <p><strong>Thời gian gửi:</strong> <?= $attendance['explanation_submitted_at'] ? date('d/m/Y H:i:s', strtotime($attendance['explanation_submitted_at'])) : 'Không xác định' ?></p>
                                          
                                                <span class="badge <?= $explanation_status == 'approved' ? 'bg-success' : ($explanation_status == 'rejected' ? 'bg-danger' : 'bg-warning text-dark') ?>">
                                                    <?= $explanation_status == 'approved' ? 'Đã duyệt' : ($explanation_status == 'rejected' ? 'Từ chối' : 'Đang xử lý') ?>
                                                </span>
                                            </p>
                                            <?php if ($attendance['explanation_approved_at']): ?>
                                                <p><strong>Thời gian xử lý:</strong> <?= date('d/m/Y H:i:s', strtotime($attendance['explanation_approved_at'])) ?></p>
                                            <?php endif; ?>
                                            <?php if ($attendance['explanation_note']): ?>
                                                <p><strong>Ghi chú từ HR:</strong> <?= htmlspecialchars($attendance['explanation_note']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($hoursWorked < 8 && !$explanation): ?>
                                    <p class="text-danger mt-2">Bạn đã làm việc dưới 8 tiếng và chưa gửi giải trình. Vui lòng gửi giải trình trong 24 giờ, nếu không sẽ bị trừ lương.</p>
                                    <button type="button" class="btn btn-warning mt-2" onclick="showExplanationModal(<?= $attendance_id ?>)">
                                        Gửi Giải Trình
                                    </button>
                                <?php endif; ?>
                            </div>
                            <button class="btn btn-secondary btn-lg" disabled>
                                <i class="fas fa-check-circle me-2"></i> Đã Checkout
                            </button>
                            <?php if ($status === 'approved'): ?>
                                <script>
                                    Swal.fire({
                                        title: "Về đi em! 🥰",
                                        text: "HR đã phê duyệt chấm công của bạn. Chúc bạn một buổi tối vui vẻ!",
                                        icon: "success",
                                        confirmButtonText: "Cảm ơn HR!"
                                    });
                                </script>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="POST" id="checkoutForm">
                                <input type="hidden" name="action" value="checkout">
                                <button type="submit" class="btn btn-danger btn-lg" id="checkoutBtn">
                                    <i class="fas fa-sign-out-alt me-2"></i> Checkout Ngay
                                </button>
                            </form>
                            <?php if ($hoursWorked < 8 && !$explanation): ?>
                                <div class="alert alert-warning mt-3">
                                    <p class="mb-2">Bạn chưa làm đủ 8 tiếng (hiện tại: <?= number_format($hoursWorked, 2) ?> giờ). Nếu checkout, bạn cần gửi giải trình trong 24 giờ để tránh bị trừ lương.</p>
                                    <button type="button" class="btn btn-warning" onclick="showExplanationModal(<?= $attendance_id ?>)">
                                        Gửi Giải Trình
                                    </button>
                                </div>
                            <?php elseif ($explanation): ?>
                                <div class="alert alert-info mt-3">
                                    <h6 class="mb-2">Bản Giải Trình:</h6>
                                    <div class="explanation-box">
                                        <p><strong>Lý do:</strong> <?= htmlspecialchars($explanation) ?></p>
                                        <p><strong>Thời gian gửi:</strong> <?= $attendance['explanation_submitted_at'] ? date('d/m/Y H:i:s', strtotime($attendance['explanation_submitted_at'])) : 'Không xác định' ?></p>
                                        <p><strong>Trạng thái:</strong> 
                                            <span class="badge <?= $explanation_status == 'pending' ? 'bg-warning text-dark' : ($explanation_status == 'approved' ? 'bg-success' : 'bg-danger') ?>">
                                                <?= $explanation_status == 'pending' ? 'Đang xử lý' : ($explanation_status == 'approved' ? 'Đã duyệt' : 'Từ chối') ?>
                                            </span>
                                        </p>
                                        <?php if ($attendance['explanation_approved_at']): ?>
                                            <p><strong>Thời gian xử lý:</strong> <?= date('d/m/Y H:i:s', strtotime($attendance['explanation_approved_at'])) ?></p>
                                        <?php endif; ?>
                                        <?php if ($attendance['explanation_note']): ?>
                                            <p><strong>Ghi chú từ HR:</strong> <?= htmlspecialchars($attendance['explanation_note']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card mt-4 shadow">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0">Lịch sử chấm công gần đây</h4>
                </div>
                <div class="card-body">
                    <?php
                    $query = "SELECT check_in, check_out, status, approval_note, explanation, explanation_status, 
                                     explanation_submitted_at, explanation_approved_at, explanation_note 
                              FROM attendance 
                              WHERE employee_id = :employee_id 
                              ORDER BY check_in DESC LIMIT 5";
                    $stmt = $db->prepare($query);
                    $stmt->execute([':employee_id' => $employee_id]);
                    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (count($history) > 0):
                    ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Ngày</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Thời gian làm việc</th>
                                
                                    <th>Ghi chú HR</th>
                                    <th>Giải trình</th>
                                </tr>
                            </thead>
                            <tbody>
    <?php foreach ($history as $record): 
        $check_in_hist = new DateTime($record['check_in']);
        $hours_worked = 0;
        
        if (!empty($record['check_out'])) {
            $check_out_hist = new DateTime($record['check_out']);
            $interval = $check_in_hist->diff($check_out_hist);
            $hours_worked = $interval->h + ($interval->i / 60);
        }
    ?>
    <tr>
        <td><?= date('d/m/Y', strtotime($record['check_in'])) ?></td>
        <td><?= date('H:i:s', strtotime($record['check_in'])) ?></td>
        <td><?= !empty($record['check_out']) ? date('H:i:s', strtotime($record['check_out'])) : '<span class="badge bg-info">Đang làm việc</span>' ?></td>
        <td><?= !empty($record['check_out']) ? sprintf("%.2f giờ", $hours_worked) : '-' ?></td>
        <td><?= htmlspecialchars($record['approval_note'] ?? '-') ?></td>
        <td>
            <?php if ($record['explanation']): ?>
                <button type="button" class="btn btn-sm btn-info" onclick="showExplanationDetails('<?= htmlspecialchars($record['explanation']) ?>', '<?= $record['explanation_submitted_at'] ? date('d/m/Y H:i:s', strtotime($record['explanation_submitted_at'])) : 'Không xác định' ?>', '<?= $record['explanation_status'] ?>', '<?= $record['explanation_approved_at'] ? date('d/m/Y H:i:s', strtotime($record['explanation_approved_at'])) : '' ?>', '<?= htmlspecialchars($record['explanation_note'] ?? '') ?>')">
                    Xem giải trình
                </button>
            <?php else: ?>
                -
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-center">Không có dữ liệu chấm công gần đây.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal nhập giải trình -->
    <div class="modal fade" id="explanationModal" tabindex="-1" aria-labelledby="explanationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="explanationModalLabel">Gửi Giải Trình</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="explanationForm" method="POST">
                        <input type="hidden" name="action" value="submit_explanation">
                        <input type="hidden" name="attendance_id" id="modal_attendance_id">
                        <div class="detail-row">
                            <span class="label">Lý do:</span>
                            <input type="text" name="explanation" id="modal_explanation" class="value form-control" placeholder="Nhập lý do checkout sớm...">
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-primary">Gửi</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal hiển thị chi tiết giải trình -->
    <div class="modal fade" id="explanationDetailsModal" tabindex="-1" aria-labelledby="explanationDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="explanationDetailsModalLabel">Chi Tiết Giải Trình</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="detail-row">
                        <span class="label">Lý do:</span>
                        <span class="value" id="explanationReason"></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Thời gian gửi:</span>
                        <span class="value" id="explanationSubmittedAt"></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Trạng thái:</span>
                        <span class="value" id="explanationStatus"></span>
                    </div>
                    <div class="detail-row" id="explanationApprovedAtRow" style="display: none;">
                        <span class="label">Thời gian xử lý:</span>
                        <span class="value" id="explanationApprovedAt"></span>
                    </div>
                    <div class="detail-row" id="explanationNoteRow" style="display: none;">
                        <span class="label">Ghi chú từ HR:</span>
                        <span class="value" id="explanationNote"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>
</div>


<style>
.time-display {
    font-size: 28px;
    font-weight: bold;
    color: #dc3545;
}

.btn-danger {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.btn-danger:hover {
    transform: scale(1.05);
    box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
}

.alert {
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert-success {
    background: #e6f4ea;
    border: 1px solid #28a745;
    color: #2e7d32;
}

.alert-danger {
    background: #fce4e4;
    border: 1px solid #dc3545;
    color: #d32f2f;
}

.alert-warning {
    background: #fff3cd;
    border: 1px solid #ffeeba;
    color: #856404;
}

.alert-info {
    background: #e7f3fe;
    border: 1px solid #b6d4fe;
    color: #084298;
}

.alert .btn-close {
    font-size: 16px;
    opacity: 0.6;
    transition: all 0.3s ease;
    background: none;
    border: none;
}

.alert .btn-close:hover {
    opacity: 1;
    transform: rotate(90deg);
}

/* Modal nhập giải trình */
.modal-content {
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
}

.modal-header {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: #ffffff;
    border-bottom: none;
    padding: 10px 15px;
}

.modal-header .modal-title {
    font-size: 18px;
    font-weight: bold;
}

.modal-header .btn-close {
    filter: invert(1);
}

.modal-body {
    padding: 15px;
    font-size: 14px;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e5e7eb;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-row .label {
    color: #374151;
    font-weight: normal;
    flex: 0 0 40%;
}

.detail-row .value {
    color: #1e3c72;
    font-weight: 500;
    flex: 0 0 60%;
    text-align: right;
}

.detail-row .form-control {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 5px 10px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.detail-row .form-control:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    outline: none;
}

.modal-footer {
    border-top: none;
    padding: 10px 15px;
    justify-content: flex-end;
}

.modal-footer .btn {
    padding: 6px 12px;
    font-size: 14px;
}

.modal-footer .btn-primary {
    background: linear-gradient(90deg, #3b82f6, #1e40af);
    color: #ffffff;
}

.modal-footer .btn-primary:hover {
    background: linear-gradient(90deg, #2563eb, #1e3a8a);
}

.modal-footer .btn-secondary {
    background: #6c757d;
    color: #ffffff;
}

.modal-footer .btn-secondary:hover {
    background: #5a6268;
}

/* Table */
.table {
    margin-bottom: 0;
}

.table th {
    background-color: #f8f9fa;
    border-top: none;
    padding: 12px 15px;
}

.table td {
    padding: 12px 15px;
    vertical-align: middle;
}

.table-striped tbody tr:nth-of-type(odd) {
    background-color: #f9fafb;
}

.badge {
    padding: 6px 12px;
    border-radius: 4px;
    font-weight: 500;
    display: inline-block;
    text-align: center;
    min-width: 100px;
}

.bg-success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.bg-warning {
    background-color: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
}

.bg-danger {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}



.bg-primary {
    background-color: #cfe2ff;
    color: #084298;
    border: 1px solid #b6d4fe;
}

.bg-secondary {
    background-color: #e2e3e5;
    color: #41464b;
    border: 1px solid #d3d6d8;
}
</style>

<?php require_once '../layouts/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Cập nhật thời gian thực cho đồng hồ
    function updateTime() {
        const now = new Date();
        const options = { 
            hour: '2-digit', 
            minute: '2-digit', 
            second: '2-digit',
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        };
        const formattedDate = now.toLocaleDateString('vi-VN', options);
        document.getElementById('currentTime').textContent = formattedDate;
    }
    updateTime();
    setInterval(updateTime, 1000);

    // Cập nhật thời gian làm việc (nếu chưa checkout)
    const checkInTime = "<?= $check_in ? $check_in->format('Y-m-d H:i:s') : 'null' ?>";
    const alreadyCheckedOut = <?= $alreadyCheckedOut ? 'true' : 'false' ?>;
    let hoursWorkedElement = document.getElementById('hoursWorked');

    function updateHoursWorked() {
        if (checkInTime && checkInTime !== 'null' && !alreadyCheckedOut) {
            const checkIn = new Date(checkInTime);
            const now = new Date();
            const diffMs = now - checkIn;
            const diffHours = diffMs / (1000 * 60 * 60);
            if (hoursWorkedElement) {
                hoursWorkedElement.textContent = diffHours.toFixed(2) + ' giờ';
            }
        }
    }

    if (!alreadyCheckedOut && hoursWorkedElement) {
        updateHoursWorked();
        setInterval(updateHoursWorked, 60000);
    }

    // Xử lý sự kiện khi nhấn nút Checkout
    const checkoutBtn = document.getElementById("checkoutBtn");
    if (checkoutBtn) {
        checkoutBtn.addEventListener("click", function(e) {
            e.preventDefault();
            let hoursWorked = parseFloat(document.getElementById('hoursWorked').textContent) || 0;
            
            if (hoursWorked < 8) {
                Swal.fire({
                    title: "Bạn ra sớm đấy! ⏳",
                    text: "Bạn chưa làm đủ 8 tiếng! Bạn có chắc chắn muốn checkout không?",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonText: "Vẫn checkout",
                    cancelButtonText: "Tiếp tục làm việc"
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.getElementById("checkoutForm").submit();
                    }
                });
            } else {
                document.getElementById("checkoutForm").submit();
            }
        });
    }

    // Hiển thị modal nhập giải trình
    function showExplanationModal(attendanceId) {
        // Đặt giá trị cho input ẩn trong modal
        document.getElementById('modal_attendance_id').value = attendanceId;
        document.getElementById('modal_explanation').value = ''; // Xóa nội dung giải trình cũ

        // Mở modal
        const explanationModal = new bootstrap.Modal(document.getElementById('explanationModal'));
        explanationModal.show();
    }

    // Hiển thị chi tiết giải trình trong modal
    function showExplanationDetails(reason, submittedAt, status, approvedAt, note) {
        // Điền thông tin vào modal
        document.getElementById('explanationReason').textContent = reason;
        document.getElementById('explanationSubmittedAt').textContent = submittedAt;
        
        const statusElement = document.getElementById('explanationStatus');
        statusElement.textContent = status === 'approved' ? 'Đã duyệt' : (status === 'rejected' ? 'Từ chối' : 'Đang xử lý');
        statusElement.className = 'badge ' + (status === 'approved' ? 'bg-success' : (status === 'rejected' ? 'bg-danger' : 'bg-warning text-dark'));

        // Hiển thị thời gian xử lý nếu có
        const approvedAtRow = document.getElementById('explanationApprovedAtRow');
        const approvedAtElement = document.getElementById('explanationApprovedAt');
        if (approvedAt) {
            approvedAtElement.textContent = approvedAt;
            approvedAtRow.style.display = 'flex';
        } else {
            approvedAtRow.style.display = 'none';
        }

        // Hiển thị ghi chú từ HR nếu có
        const noteRow = document.getElementById('explanationNoteRow');
        const noteElement = document.getElementById('explanationNote');
        if (note) {
            noteElement.textContent = note;
            noteRow.style.display = 'flex';
        } else {
            noteRow.style.display = 'none';
        }

        // Mở modal
        const explanationDetailsModal = new bootstrap.Modal(document.getElementById('explanationDetailsModal'));
        explanationDetailsModal.show();
    }

    // Gán hàm showExplanationModal vào global scope để có thể gọi từ HTML
    window.showExplanationModal = showExplanationModal;
    window.showExplanationDetails = showExplanationDetails;
});
</script>