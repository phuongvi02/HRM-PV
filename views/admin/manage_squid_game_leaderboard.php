<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";

// Kiểm tra phiên làm việc và vai trò (giả sử role_id = 1 là Quản trị)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

$db = Database::getInstance()->getConnection();

// Tự động xóa dữ liệu của ngày hôm trước
$delete_stmt = $db->prepare("
    DELETE FROM entertainment_games 
    WHERE DATE(created_at) < CURDATE()
");
$delete_stmt->execute();

// Danh sách trò chơi
$games = [
    'squid_game' => ['name' => 'Squid Game', 'max_score' => 40],
    'picture_puzzle' => ['name' => 'Đuổi Hình Bắt Chữ', 'max_score' => 30],
    'who_wants_to_be_a_millionaire' => ['name' => 'Ai Là Triệu Phú', 'max_score' => 30],
    'math_puzzle' => ['name' => 'Đố Vui Toán Học', 'max_score' => 30],
];

// Xử lý chọn trò chơi
$selected_game = $_POST['game_type'] ?? 'squid_game';
if (!array_key_exists($selected_game, $games)) {
    $selected_game = 'squid_game';
}

// Lấy bảng xếp hạng
$leaderboard_stmt = $db->prepare("
    SELECT e.full_name, g.score, g.created_at, g.employee_answers, g.questions,
           (SELECT COUNT(*) FROM entertainment_games g2 WHERE g2.employee_id = g.employee_id AND g2.game_type = :game_type AND g2.status = 'graded') AS play_count
    FROM entertainment_games g
    JOIN employees e ON g.employee_id = e.id
    WHERE g.game_type = :game_type AND g.status = 'graded' AND g.score IS NOT NULL
    ORDER BY g.score DESC, g.created_at ASC
");
$leaderboard_stmt->execute([':game_type' => $selected_game]);
$leaderboard = $leaderboard_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="/HRMpv/public/css/styles.css">
<button onclick="history.back()" class="btn btn-secondary mb-3">Quay lại</button>
<style>
.admin-leaderboard-container {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
    min-height: calc(100vh - 200px);
    margin-left: 250px;
    transition: margin-left 0.3s ease;
}

.admin-leaderboard-container h2 {
    margin-bottom: 30px;
    text-align: center;
    color: #333;
    font-size: 2rem;
}

.game-selector {
    margin-bottom: 20px;
    text-align: center;
}

.game-selector select {
    padding: 8px;
    font-size: 1rem;
    border-radius: 4px;
    border: none;
    background: #fff;
    color: #333;
}

.leaderboard-table {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.leaderboard-table table {
    width: 100%;
    border-collapse: collapse;
}

.leaderboard-table th,
.leaderboard-table td {
    padding: 12px;
    border: 1px solid #e5e7eb;
    text-align: left;
}

.leaderboard-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #374151;
}

.leaderboard-table tbody tr:hover {
    background: #f9fafb;
}

.btn-details {
    background: #3b82f6;
    color: white;
    padding: 5px 10px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9rem;
    transition: background 0.3s ease;
}

.btn-details:hover {
    background: #2563eb;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    justify-content: center;
    align-items: center;
}

.modal-content {
    background: white;
    padding: 20px;
    border-radius: 8px;
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    position: relative;
}

.close-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    font-size: 1.5rem;
    cursor: pointer;
    color: #333;
}

@media (max-width: 768px) {
    .admin-leaderboard-container {
        margin-left: 0;
        padding: 10px;
    }

    .admin-leaderboard-container h2 {
        font-size: 1.5rem;
    }

    .leaderboard-table {
        padding: 15px;
    }

    .leaderboard-table th,
    .leaderboard-table td {
        padding: 10px;
        font-size: 0.9rem;
    }

    .btn-details {
        padding: 4px 8px;
        font-size: 0.85rem;
    }
}

@media (max-width: 576px) {
    .admin-leaderboard-container {
        padding: 5px;
    }

    .admin-leaderboard-container h2 {
        font-size: 1.25rem;
    }

    .leaderboard-table th,
    .leaderboard-table td {
        padding: 8px;
        font-size: 0.85rem;
    }

    .btn-details {
        padding: 3px 6px;
        font-size: 0.8rem;
    }
}
</style>

<div class="admin-leaderboard-container">
    <h2>Bảng Xếp Hạng Giải Trí - Nhân Viên</h2>

    <!-- Chọn trò chơi -->
    <div class="game-selector">
        <form method="POST" id="gameSelectorForm">
            <label for="game_type">Chọn trò chơi:</label>
            <select name="game_type" id="game_type" onchange="document.getElementById('gameSelectorForm').submit()">
                <?php foreach ($games as $game_type => $game_info): ?>
                    <option value="<?= $game_type ?>" <?= $selected_game === $game_type ? 'selected' : '' ?>>
                        <?= $game_info['name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- Bảng xếp hạng -->
    <div class="leaderboard-table">
        <table>
            <thead>
                <tr>
                    <th>Xếp hạng</th>
                    <th>Nhân viên</th>
                    <th>Điểm</th>
                    <th>Thời gian hoàn thành</th>
                    <th>Số lần chơi</th>
                    <th>Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($leaderboard)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center;">Chưa có nhân viên nào tham gia <?= $games[$selected_game]['name'] ?>.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($leaderboard as $index => $entry): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars($entry['full_name']) ?></td>
                            <td><?= $entry['score'] ?>/<?= $games[$selected_game]['max_score'] ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($entry['created_at'])) ?></td>
                            <td><?= $entry['play_count'] ?></td>
                            <td>
                                <button class="btn-details" onclick='showDetails(<?= json_encode($entry) ?>, <?= json_encode($selected_game) ?>)'>Xem chi tiết</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal để hiển thị chi tiết -->
<div id="detailsModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal()">×</span>
        <h3>Chi Tiết Kết Quả - <span id="employeeName"></span></h3>
        <p><strong>Điểm:</strong> <span id="employeeScore"></span></p>
        <h4>Câu trả lời:</h4>
        <div id="answersDetails"></div>
    </div>
</div>

<script>
function showDetails(entry, gameType) {
    const modal = document.getElementById('detailsModal');
    const employeeName = document.getElementById('employeeName');
    const employeeScore = document.getElementById('employeeScore');
    const answersDetails = document.getElementById('answersDetails');

    employeeName.textContent = entry.full_name;
    employeeScore.textContent = `${entry.score}/${gameType === 'squid_game' ? 40 : 30}`;

    const questions = JSON.parse(entry.questions || '[]');
    const answers = JSON.parse(entry.employee_answers || '[]');
    let html = '';

    questions.forEach((question, index) => {
        const userAnswer = answers[index] || 'Không trả lời';
        const correctAnswer = question.correct_answer;
        const isCorrect = userAnswer === correctAnswer;

        html += `
            <div style="margin-bottom: 15px;">
                <p><strong>Câu ${index + 1}: ${question.question_text || getRoundName(question.game_round)}</strong></p>
                <p><strong>Đáp án của nhân viên:</strong> ${formatAnswer(question, userAnswer)}</p>
                <p><strong>Đáp án đúng:</strong> ${formatAnswer(question, correctAnswer)}</p>
                <p><strong>Kết quả:</strong> <span style="color: ${isCorrect ? '#28a745' : '#e74c3c'}">${isCorrect ? 'Đúng' : 'Sai'}</span></p>
            </div>
        `;
    });

    answersDetails.innerHTML = html;
    modal.style.display = 'flex';
}

function closeModal() {
    document.getElementById('detailsModal').style.display = 'none';
}

function getRoundName(round) {
    switch (round) {
        case 'red_light_green_light': return 'Đèn Xanh, Đèn Đỏ';
        case 'dalgona': return 'Cắt Kẹo Dalgona';
        case 'tug_of_war': return 'Kéo Co';
        case 'glass_bridge': return 'Nhảy Cầu Kính';
        default: return 'Không xác định';
    }
}

function formatAnswer(question, answer) {
    if (!answer) return 'Không trả lời';
    if (question.question_type === 'multiple_choice' && question.options) {
        const options = JSON.parse(question.options);
        return options[answer] || answer;
    } else if (question.question_type === 'short_answer') {
        return answer;
    } else if (question.question_type === 'ordering' && question.options) {
        const options = JSON.parse(question.options);
        const order = answer.split(',');
        return order.map(id => options[id]).join(' → ');
    }
    return answer;
}

window.onclick = function(event) {
    const modal = document.getElementById('detailsModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
};
</script>

<?php
require_once '../layouts/footer.php';
?>