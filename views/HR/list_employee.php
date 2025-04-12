<?php
ob_start();

// Kiểm tra và yêu cầu các file cần thiết
$databasePath = __DIR__ . "/../../core/Database.php";
$sidebarPath = __DIR__ . '/../../views/layouts/sidebar_hr.php';

if (!file_exists($databasePath)) {
    die("Lỗi: File Database.php không tồn tại tại $databasePath");
}
if (!file_exists($sidebarPath)) {
    die("Lỗi: File sidebar_hr.php không tồn tại tại $sidebarPath");
}

require_once $databasePath;
require_once $sidebarPath;

// Kết nối cơ sở dữ liệu
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    die("Lỗi kết nối cơ sở dữ liệu: " . $e->getMessage());
}

// Xử lý tìm kiếm, lọc và phân trang
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$department = isset($_GET['department']) ? trim($_GET['department']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10; // Số nhân viên mỗi trang
$offset = ($page - 1) * $limit;

$whereClause = '';
$params = [];

if (!empty($search)) {
    $whereClause .= "WHERE e.full_name LIKE :search OR e.email LIKE :search OR e.phone LIKE :search";
    $params[':search'] = "%$search%";
}
if (!empty($department)) {
    $whereClause .= ($whereClause ? ' AND ' : 'WHERE ') . "d.name = :department";
    $params[':department'] = $department;
}

// Đếm tổng số nhân viên để phân trang
$countQuery = "SELECT COUNT(*) FROM employees e LEFT JOIN departments d ON e.department_id = d.id $whereClause";
try {
    $countStmt = $db->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalEmployees = $countStmt->fetchColumn();
    $totalPages = ceil($totalEmployees / $limit);
} catch (PDOException $e) {
    error_log("Lỗi đếm nhân viên: " . $e->getMessage());
    $totalEmployees = 0;
    $totalPages = 1;
}

// Truy vấn danh sách nhân viên, lấy lương từ hợp đồng
$query = "SELECT 
    e.id, e.full_name, e.email, e.phone, e.avatar, e.created_at, e.contract_end_date,
    d.name AS department, p.name AS position,
    (SELECT basic_salary 
     FROM contracts c 
     WHERE c.employee_id = e.id 
     AND c.start_date <= NOW() 
     AND (c.end_date IS NULL OR c.end_date >= NOW()) 
     ORDER BY c.start_date DESC 
     LIMIT 1) AS salary,
    (SELECT COUNT(*) FROM attendance a WHERE a.employee_id = e.id AND a.late_minutes > 0) AS late_count
FROM employees e
LEFT JOIN departments d ON e.department_id = d.id
LEFT JOIN positions p ON e.position_id = p.id
$whereClause
ORDER BY e.created_at DESC
LIMIT :limit OFFSET :offset";

try {
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database query error: " . $e->getMessage());
    $employees = [];
}

// Xử lý avatar
$employeeAvatars = [];
$defaultAvatar = '/HRMpv/public/images/default-avatar.png';

// Kiểm tra file ảnh mặc định có tồn tại không
if (!file_exists($_SERVER['DOCUMENT_ROOT'] . $defaultAvatar)) {
    error_log("Lỗi: Ảnh mặc định không tồn tại tại " . $_SERVER['DOCUMENT_ROOT'] . $defaultAvatar);
    $defaultAvatar = 'https://via.placeholder.com/50x50?text=Avatar'; // Sử dụng ảnh placeholder nếu không tìm thấy
}

foreach ($employees as $key => $employee) {
    $avatar = trim($employee['avatar'] ?? '');
    if (!empty($avatar) && preg_match('/^[a-zA-Z0-9_-]+$/', $avatar)) {
        $employeeAvatars[$employee['id']] = [
            'thumbnail' => "https://drive.google.com/thumbnail?id=$avatar&sz=w80-h80",
            'large' => "https://drive.google.com/uc?export=view&id=$avatar"
        ];
    } else {
        $employeeAvatars[$employee['id']] = [
            'thumbnail' => $defaultAvatar,
            'large' => $defaultAvatar
        ];
    }
    $employees[$key]['avatarUrls'] = $employeeAvatars[$employee['id']];
}

$successMessage = isset($_GET['success']) ? htmlspecialchars($_GET['success']) : '';
$errorMessage = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';

function formatCurrency($amount) {
    return $amount === null ? 'Chưa cập nhật' : number_format($amount, 0, ',', '.') . ' VNĐ';
}

function formatDate($date) {
    return date('d/m/Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Danh Sách Nhân Viên</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/HRMpv/public/css/list.css">
</head>
<body>
    <div class="container">
        <h2>Danh Sách Nhân Viên</h2>

        <?php if ($errorMessage): ?>
            <div style="color: red; text-align: center; margin-bottom: 15px;"><?php echo $errorMessage; ?></div>
        <?php endif; ?>
        <?php if ($successMessage): ?>
            <div style="color: green; text-align: center; margin-bottom: 15px;"><?php echo $successMessage; ?></div>
        <?php endif; ?>

        <div class="search-filter">
            <input type="text" id="search" placeholder="Tìm kiếm theo tên, email hoặc số điện thoại..." value="<?php echo htmlspecialchars($search); ?>">
            <select id="department" onchange="applyFilter()">
                <option value="">Tất cả phòng ban</option>
                <?php
                try {
                    $deptQuery = $db->query("SELECT name FROM departments");
                    foreach ($deptQuery->fetchAll(PDO::FETCH_COLUMN) as $dept) {
                        $selected = $dept === $department ? 'selected' : '';
                        echo "<option value='$dept' $selected>$dept</option>";
                    }
                } catch (PDOException $e) {
                    error_log("Lỗi truy vấn phòng ban: " . $e->getMessage());
                    echo "<option value=''>Không thể tải phòng ban</option>";
                }
                ?>
            </select>
            <button onclick="applyFilter()">Tìm</button>
            <a href="add_employee.php" class="btn btn-primary"><i class="fas fa-plus"></i> Thêm Nhân Viên</a>
        </div>

        <?php if (empty($employees)): ?>
            <p style="text-align: center; color: #666;">Không tìm thấy nhân viên nào.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Ảnh</th>
                        <th>Họ tên</th>
                        <th>Email</th>
                        <th>Điện thoại</th>
                        <th>Phòng ban</th>
                        <th>Chức vụ</th>
                        <th>Lương</th>
                        <th>Ngày tạo</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td>
                                <img src="<?php echo htmlspecialchars($employee['avatarUrls']['thumbnail']); ?>" 
                                     class="avatar" 
                                     alt="Avatar của <?php echo htmlspecialchars($employee['full_name']); ?>" 
                                     loading="lazy" 
                                     onerror="this.src='<?php echo $defaultAvatar; ?>'">
                            </td>
                            <td><?php echo htmlspecialchars($employee['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($employee['email']); ?></td>
                            <td><?php echo htmlspecialchars($employee['phone'] ?? 'Chưa cập nhật'); ?></td>
                            <td><?php echo htmlspecialchars($employee['department'] ?? 'Chưa cập nhật'); ?></td>
                            <td><?php echo htmlspecialchars($employee['position'] ?? 'Chưa cập nhật'); ?></td>
                            <td><?php echo formatCurrency($employee['salary']); ?></td>
                            <td><?php echo formatDate($employee['created_at']); ?></td>
                            <td>
                                <a href="edit_employee.php?id=<?php echo $employee['id']; ?>" class="btn btn-primary"><i class="fas fa-edit"></i> Sửa</a>
                                <a href="delete_employee.php?id=<?php echo $employee['id']; ?>" class="btn btn-danger" onclick="return confirm('Bạn có chắc muốn xóa?');"><i class="fas fa-trash"></i> Xóa</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Chatbot Container -->
    <div class="chatbot-container" id="chatbot-container">
        <div class="chatbot-header">
            <span>HR Chatbot</span>
            <button class="chatbot-close" id="chatbot-close"><i class="fas fa-times"></i></button>
        </div>
        <div class="chatbot-messages" id="chatbot-messages">
            <div class="message bot-message">Chào bạn! Tôi có thể giúp gì về HR hôm nay?</div>
        </div>
        <div class="chatbot-input">
            <input type="text" id="chatbot-input" placeholder="Hỏi tôi bất cứ điều gì về HR...">
            <button id="chatbot-send"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>

    <!-- Chatbot Icon -->
    <div class="chatbot-icon" id="chatbot-toggle">
        <i class="fas fa-comment-dots"></i>
    </div>

    <script>
        // Truyền dữ liệu nhân viên từ PHP sang JavaScript
        const employees = <?php echo json_encode($employees); ?>;
        const currentDate = new Date('<?php echo date('Y-m-d'); ?>');

        document.addEventListener('DOMContentLoaded', function() {
            const chatbotToggle = document.getElementById('chatbot-toggle');
            const chatbotContainer = document.getElementById('chatbot-container');
            const chatbotClose = document.getElementById('chatbot-close');
            const chatbotMessages = document.getElementById('chatbot-messages');
            const chatbotInput = document.getElementById('chatbot-input');
            const chatbotSend = document.getElementById('chatbot-send');

            // Toggle chatbot
            chatbotToggle.onclick = function() {
                chatbotContainer.classList.toggle('active');
            };

            // Đóng chatbot
            chatbotClose.onclick = function() {
                chatbotContainer.classList.remove('active');
            };

            // Gửi tin nhắn
            chatbotSend.onclick = function() {
                sendMessage();
            };

            chatbotInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    sendMessage();
                }
            });

            function sendMessage() {
                const message = chatbotInput.value.trim();
                if (!message) return;

                addMessage('user', message);
                chatbotInput.value = '';
                processMessage(message);
            }

            // Hiển thị tin nhắn
            function addMessage(sender, text) {
                const msgDiv = document.createElement('div');
                msgDiv.classList.add('message', `${sender}-message`);
                msgDiv.textContent = text;
                chatbotMessages.appendChild(msgDiv);
                chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
            }

            // Xử lý tin nhắn dựa trên dữ liệu SQL
            function processMessage(message) {
                let response = "Tôi chưa hiểu câu hỏi của bạn. Hãy thử lại!";
                message = message.toLowerCase();

                if (message === 'hi') {
                    response = "Tôi chưa hiểu câu hỏi của bạn. Hãy thử lại!";
                } else if (message === 'xin chào') {
                    response = "Chào bạn! Tôi là HR Chatbot, sẵn sàng hỗ trợ bạn.";
                } else if (message === 'chấm công') {
                    response = "Vui lòng cung cấp thêm thông tin (ví dụ: 'Nhân viên ID 1 đi muộn bao nhiêu lần?').";
                } 
                // Chấm công: Số lần đi muộn
                else if (message.match(/nhân viên id (\d+) đi muộn bao nhiêu lần/)) {
                    const employeeId = parseInt(message.match(/nhân viên id (\d+)/)[1]);
                    const employee = employees.find(emp => emp.id == employeeId);
                    if (employee) {
                        response = `Nhân viên ID ${employeeId} (${employee.full_name}) đã đi muộn ${employee.late_count || 0} lần.`;
                    } else {
                        response = `Không tìm thấy nhân viên với ID ${employeeId}.`;
                    }
                } 
                // Thông tin nhân viên
                else if (message.match(/thông tin nhân viên id (\d+)/)) {
                    const employeeId = parseInt(message.match(/thông tin nhân viên id (\d+)/)[1]);
                    const employee = employees.find(emp => emp.id == employeeId);
                    if (employee) {
                        response = `Thông tin nhân viên ID ${employeeId}: Tên: ${employee.full_name}, Email: ${employee.email}, SĐT: ${employee.phone || 'Chưa cập nhật'}, Phòng ban: ${employee.department || 'Chưa cập nhật'}, Chức vụ: ${employee.position || 'Chưa cập nhật'}, Lương: ${employee.salary ? parseInt(employee.salary).toLocaleString('vi-VN') + ' VNĐ' : 'Chưa cập nhật'}.`;
                    } else {
                        response = `Không tìm thấy nhân viên với ID ${employeeId}.`;
                    }
                } 
                // Nhân viên sắp phải nghỉ
                else if (message.match(/nhân viên nào sắp phải nghỉ/)) {
                    const soonToLeave = employees.filter(emp => {
                        if (!emp.contract_end_date) return false;
                        const endDate = new Date(emp.contract_end_date);
                        const daysLeft = (endDate - currentDate) / (1000 * 60 * 60 * 24);
                        return daysLeft >= 0 && daysLeft <= 30;
                    });
                    if (soonToLeave.length > 0) {
                        response = "Các nhân viên sắp phải nghỉ (hết hợp đồng trong 30 ngày tới):\n" + 
                            soonToLeave.map(emp => `ID ${emp.id} (${emp.full_name}) - Hết hợp đồng: ${new Date(emp.contract_end_date).toLocaleDateString('vi-VN')}`).join('\n');
                    } else {
                        response = "Hiện không có nhân viên nào sắp phải nghỉ trong 30 ngày tới.";
                    }
                }

                setTimeout(() => addMessage('bot', response), 500);
            }

            // Tìm kiếm và lọc
            function applyFilter() {
                const search = document.getElementById('search').value;
                const department = document.getElementById('department').value;
                window.location.href = `?search=${encodeURIComponent(search)}&department=${encodeURIComponent(department)}`;
            }

            // Debounce tìm kiếm
            let timeout;
            document.getElementById('search').addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(applyFilter, 500);
            });
        });
    </script>
</body>
</html>

<?php
ob_end_flush();
?>