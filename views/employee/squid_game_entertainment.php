<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";
require_once '../layouts/header_employee.php';
require_once '../layouts/sidebar_employee.php';
require_once '../layouts/navbar_employee.php';

// Kiểm tra xác thực
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 5) {
    header('Location: /HRMpv/views/auth/login.php');
    exit();
}

$employee_id = $_SESSION['user_id'];
$db = Database::getInstance()->getConnection();

// Cấu hình trò chơi nâng cao với các cài đặt chi tiết
$games = [
    'squid_game' => [
        'name' => 'Squid Game Challenge',
        'description' => 'Thử thách sinh tồn với các trò chơi dân gian',
        'max_score' => 100,
        'questions_per_game' => 10,
        'time_limit' => 600, // 10 phút
        'difficulty' => 'Hard',
        'rewards' => [
            'gold' => ['min_score' => 90, 'reward' => '2 ngày nghỉ phép'],
            'silver' => ['min_score' => 75, 'reward' => '1 ngày nghỉ phép'],
            'bronze' => ['min_score' => 60, 'reward' => '0.5 ngày nghỉ phép']
        ],
        'rounds' => [
            'red_light_green_light' => [
                'name' => 'Đèn Xanh Đèn Đỏ',
                'points' => 20,
                'time' => 120
            ],
            'dalgona' => [
                'name' => 'Thử Thách Kẹo Dalgona',
                'points' => 20,
                'time' => 180
            ],
            'tug_of_war' => [
                'name' => 'Kéo Co Trí Tuệ',
                'points' => 30,
                'time' => 150
            ],
            'glass_bridge' => [
                'name' => 'Cầu Kính Sinh Tử',
                'points' => 30,
                'time' => 150
            ]
        ]
    ],
    'mental_challenge' => [
        'name' => 'Thử Thách Trí Tuệ',
        'description' => 'Giải đố logic và toán học',
        'max_score' => 80,
        'questions_per_game' => 8,
        'time_limit' => 480, // 8 phút
        'difficulty' => 'Medium',
        'rewards' => [
            'gold' => ['min_score' => 75, 'reward' => 'Voucher 500k'],
            'silver' => ['min_score' => 60, 'reward' => 'Voucher 300k'],
            'bronze' => ['min_score' => 45, 'reward' => 'Voucher 100k']
        ]
    ],
    'memory_master' => [
        'name' => 'Bậc Thầy Trí Nhớ',
        'description' => 'Thử thách khả năng ghi nhớ',
        'max_score' => 60,
        'questions_per_game' => 6,
        'time_limit' => 360, // 6 phút
        'difficulty' => 'Easy',
        'rewards' => [
            'gold' => ['min_score' => 55, 'reward' => 'Phần thưởng bí mật'],
            'silver' => ['min_score' => 45, 'reward' => 'Quà tặng đặc biệt'],
            'bronze' => ['min_score' => 35, 'reward' => 'Phần thưởng khuyến khích']
        ]
    ]
];

// Xử lý lựa chọn trò chơi
$selected_game = $_POST['game_type'] ?? 'squid_game';
if (!array_key_exists($selected_game, $games)) {
    $selected_game = 'squid_game';
}

// Lấy thống kê người chơi
$stats_query = "
    SELECT 
        COUNT(*) as total_games,
        MAX(score) as highest_score,
        AVG(score) as average_score,
        SUM(CASE WHEN score >= :gold_score THEN 1 ELSE 0 END) as gold_medals,
        SUM(CASE WHEN score >= :silver_score AND score < :gold_score THEN 1 ELSE 0 END) as silver_medals,
        SUM(CASE WHEN score >= :bronze_score AND score < :silver_score THEN 1 ELSE 0 END) as bronze_medals
    FROM entertainment_games 
    WHERE employee_id = :employee_id AND game_type = :game_type AND status = 'graded'
";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindValue(':gold_score', $games[$selected_game]['rewards']['gold']['min_score'], PDO::PARAM_INT);
$stats_stmt->bindValue(':silver_score', $games[$selected_game]['rewards']['silver']['min_score'], PDO::PARAM_INT);
$stats_stmt->bindValue(':bronze_score', $games[$selected_game]['rewards']['bronze']['min_score'], PDO::PARAM_INT);
$stats_stmt->bindValue(':employee_id', $employee_id, PDO::PARAM_INT);
$stats_stmt->bindValue(':game_type', $selected_game, PDO::PARAM_STR);
$stats_stmt->execute();
$player_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Lấy trò chơi hiện tại hoặc tạo mới
$game_check_stmt = $db->prepare("
    SELECT id, status, score, questions, started_at, completed_at, attempts
    FROM entertainment_games 
    WHERE employee_id = :employee_id 
    AND game_type = :game_type 
    AND status IN ('pending', 'in_progress')
    ORDER BY created_at DESC 
    LIMIT 1
");
if (!$game_check_stmt) {
    die("Lỗi chuẩn bị câu lệnh: " . $db->errorInfo()[2]);
}
$game_check_stmt->bindValue(':employee_id', (int)$employee_id, PDO::PARAM_INT);
$game_check_stmt->bindValue(':game_type', (string)$selected_game, PDO::PARAM_STR);
$executed = $game_check_stmt->execute();
if (!$executed) {
    die("Lỗi thực thi câu lệnh: " . $game_check_stmt->errorInfo()[2]);
}
$game = $game_check_stmt->fetch(PDO::FETCH_ASSOC);

// Đảm bảo kiểu dữ liệu
$employee_id = (int)$employee_id;
$selected_game = (string)$selected_game;

if (!$game) {
    // Tạo trò chơi mới với lựa chọn câu hỏi nâng cao
    $questions_per_game = $games[$selected_game]['questions_per_game'];
    $selected_questions = [];
    
    $difficulty_distribution = [
        'easy' => ceil($questions_per_game * 0.3),
        'medium' => ceil($questions_per_game * 0.4),
        'hard' => floor($questions_per_game * 0.3)
    ];
    
    foreach ($difficulty_distribution as $difficulty => $count) {
        $question_stmt = $db->prepare("
            SELECT * FROM questions 
            WHERE game_type = :game_type AND difficulty = :difficulty
            ORDER BY RAND() 
            LIMIT :count
        ");
        if (!$question_stmt) {
            die("Lỗi chuẩn bị câu lệnh: " . $db->errorInfo()[2]);
        }
        $question_stmt->bindValue(':game_type', $selected_game, PDO::PARAM_STR);
        $question_stmt->bindValue(':difficulty', $difficulty, PDO::PARAM_STR);
        $question_stmt->bindValue(':count', $count, PDO::PARAM_INT);
        $executed = $question_stmt->execute();
        if (!$executed) {
            die("Lỗi thực thi câu lệnh: " . $question_stmt->errorInfo()[2]);
        }
        $questions = $question_stmt->fetchAll(PDO::FETCH_ASSOC);
        $selected_questions = array_merge($selected_questions, $questions);
    }
    
    shuffle($selected_questions);
    
    $game_stmt = $db->prepare("
        INSERT INTO entertainment_games (
            employee_id, game_type, status, questions, 
            started_at, attempts
        )
        VALUES (:employee_id, :game_type, 'pending', :questions, NOW(), 1)
    ");
    $game_stmt->bindValue(':employee_id', $employee_id, PDO::PARAM_INT);
    $game_stmt->bindValue(':game_type', $selected_game, PDO::PARAM_STR);
    $game_stmt->bindValue(':questions', json_encode($selected_questions), PDO::PARAM_STR);
    $game_stmt->execute();
    
    $game_id = $db->lastInsertId();
    $game = [
        'id' => $game_id,
        'status' => 'pending',
        'questions' => json_encode($selected_questions),
        'started_at' => date('Y-m-d H:i:s'),
        'attempts' => 1
    ];
}

// Xử lý nộp trò chơi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_game'])) {
    $game_id = $_POST['game_id'] ?? 0;
    $answers = json_decode($_POST['answers'] ?? '[]', true);
    $completion_time = $_POST['completion_time'] ?? 0;
    
    $game_stmt = $db->prepare("
        SELECT questions, started_at 
        FROM entertainment_games 
        WHERE id = :game_id AND employee_id = :employee_id
    ");
    $game_stmt->bindValue(':game_id', $game_id, PDO::PARAM_INT);
    $game_stmt->bindValue(':employee_id', $employee_id, PDO::PARAM_INT);
    $game_stmt->execute();
    $game_data = $game_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($game_data) {
        $questions = json_decode($game_data['questions'], true);
        $score = 0;
        $correct_answers = 0;
        $detailed_results = [];
        
        foreach ($questions as $index => $question) {
            $user_answer = $answers[$index] ?? '';
            $is_correct = false;
            $points_earned = 0;
            
            switch ($question['question_type']) {
                case 'multiple_choice':
                    $is_correct = strcasecmp($user_answer, $question['correct_answer']) === 0;
                    $points_earned = $is_correct ? 10 : 0;
                    break;
                case 'ordering':
                    $is_correct = $user_answer === $question['correct_answer'];
                    $points_earned = $is_correct ? 15 : 0;
                    break;
                case 'short_answer':
                    $is_correct = strtolower(trim($user_answer)) === strtolower(trim($question['correct_answer']));
                    $points_earned = $is_correct ? 20 : 0;
                    break;
            }
            
            if ($is_correct) {
                $correct_answers++;
                $score += $points_earned;
            }
            
            $detailed_results[] = [
                'question_id' => $question['id'],
                'is_correct' => $is_correct,
                'points_earned' => $points_earned,
                'user_answer' => $user_answer,
                'correct_answer' => $question['correct_answer']
            ];
        }
        
        $time_taken = time() - strtotime($game_data['started_at']);
        $time_bonus = max(0, min(20, 20 - floor($time_taken / 60)));
        $score += $time_bonus;
        
        $update_stmt = $db->prepare("
            UPDATE entertainment_games 
            SET 
                status = 'graded',
                score = :score,
                completion_time = :completion_time,
                correct_answers = :correct_answers,
                employee_answers = :employee_answers,
                detailed_results = :detailed_results,
                completed_at = NOW(),
                time_bonus = :time_bonus
            WHERE id = :game_id AND employee_id = :employee_id
        ");
        $update_stmt->bindValue(':score', $score, PDO::PARAM_INT);
        $update_stmt->bindValue(':completion_time', $completion_time, PDO::PARAM_INT);
        $update_stmt->bindValue(':correct_answers', $correct_answers, PDO::PARAM_INT);
        $update_stmt->bindValue(':employee_answers', json_encode($answers), PDO::PARAM_STR);
        $update_stmt->bindValue(':detailed_results', json_encode($detailed_results), PDO::PARAM_STR);
        $update_stmt->bindValue(':time_bonus', $time_bonus, PDO::PARAM_INT);
        $update_stmt->bindValue(':game_id', $game_id, PDO::PARAM_INT);
        $update_stmt->bindValue(':employee_id', $employee_id, PDO::PARAM_INT);
        $update_stmt->execute();
        
        if ($score >= $games[$selected_game]['rewards']['gold']['min_score']) {
            $achievement = 'gold';
        } elseif ($score >= $games[$selected_game]['rewards']['silver']['min_score']) {
            $achievement = 'silver';
        } elseif ($score >= $games[$selected_game]['rewards']['bronze']['min_score']) {
            $achievement = 'bronze';
        }
        
        if (isset($achievement)) {
            $reward = $games[$selected_game]['rewards'][$achievement]['reward'];
            $_SESSION['success_message'] = "Chúc mừng! Bạn đã đạt huy chương $achievement và nhận được phần thưởng: $reward";
        } else {
            $_SESSION['success_message'] = "Hoàn thành trò chơi! Điểm của bạn: $score";
        }
        
    }
}

// Lấy dữ liệu bảng xếp hạng
$leaderboard_stmt = $db->prepare("
    SELECT 
        e.full_name,
        e.employee_code,
        d.name as department_name,
        g.score,
        g.completion_time,
        g.correct_answers,
        g.completed_at
    FROM entertainment_games g
    JOIN employees e ON g.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE g.game_type = :game_type AND g.status = 'graded'
    ORDER BY g.score DESC, g.completion_time ASC
    LIMIT 10
");
$leaderboard_stmt->bindValue(':game_type', $selected_game, PDO::PARAM_STR);
$leaderboard_stmt->execute();
$leaderboard = $leaderboard_stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy xếp hạng phòng ban
$department_rankings_stmt = $db->prepare("
    SELECT 
        d.name as department_name,
        COUNT(DISTINCT g.employee_id) as participants,
        AVG(g.score) as avg_score,
        MAX(g.score) as highest_score
    FROM entertainment_games g
    JOIN employees e ON g.employee_id = e.id
    JOIN departments d ON e.department_id = d.id
    WHERE g.game_type = :game_type AND g.status = 'graded'
    GROUP BY d.id
    ORDER BY avg_score DESC
    LIMIT 5
");
$department_rankings_stmt->bindValue(':game_type', $selected_game, PDO::PARAM_STR);
$department_rankings_stmt->execute();
$department_rankings = $department_rankings_stmt->fetchAll(PDO::FETCH_ASSOC);

// Bỏ giới hạn chơi hàng ngày
$daily_limit_reached = false; // Cho phép chơi không giới hạn

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Squid Game - Giải Trí Nhân Viên</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/HRMpv/public/css/styles.css">
    <style>
        .game-container {
            background: linear-gradient(135deg, #1a1a1a, #4a4a4a);
            color: #fff;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            max-width: 1200px;
            margin: 0 auto;
        }

        .game-header {
            text-align: center;
            margin-bottom: 2.5rem;
            padding: 1.5rem;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }

        .game-title {
            font-size: 2.5rem;
            font-weight: bold;
            color: #ff4655;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
            margin-bottom: 0.75rem;
        }

        .game-description {
            font-size: 1.2rem;
            color: #ccc;
            max-width: 700px;
            margin: 0 auto;
            line-height: 1.5;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: rgba(255,255,255,0.1);
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.15);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #ff4655;
            margin: 0.5rem 0;
        }

        .stat-label {
            color: #ccc;
            font-size: 0.9rem;
        }

        .game-section {
            background: rgba(255,255,255,0.05);
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2.5rem;
        }

        .question-container {
            background: rgba(0,0,0,0.3);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .question-text {
            font-size: 1.2rem;
            color: #fff;
            margin-bottom: 1rem;
            line-height: 1.4;
        }

        .options-container {
            display: grid;
            gap: 0.75rem;
        }

        .option-button {
            background: rgba(255,255,255,0.1);
            border: none;
            padding: 1rem;
            border-radius: 5px;
            color: #fff;
            cursor: pointer;
            transition: background 0.3s ease;
            text-align: left;
        }

        .option-button:hover {
            background: rgba(255,255,255,0.2);
        }

        .timer-container {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(0,0,0,0.8);
            padding: 1rem;
            border-radius: 50%;
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #ff4655;
            z-index: 1000;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }

        .progress-bar {
            height: 8px;
            background: rgba(255,255,255,0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .progress-fill {
            height: 100%;
            background: #ff4655;
            transition: width 0.3s ease;
        }

        .leaderboard-container {
            background: rgba(0,0,0,0.3);
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2.5rem;
        }

        .leaderboard-title {
            color: #ff4655;
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            text-align: center;
            font-weight: bold;
        }

        .leaderboard-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 0.75rem;
        }

        .leaderboard-table th {
            background: rgba(255,255,255,0.1);
            padding: 1rem;
            color: #ccc;
            font-weight: normal;
            text-align: center;
        }

        .leaderboard-table td {
            background: rgba(255,255,255,0.05);
            padding: 1rem;
            color: #fff;
            text-align: center;
        }

        .medal {
            font-size: 1.5rem;
            margin-right: 0.5rem;
        }

        .gold { color: #ffd700; }
        .silver { color: #c0c0c0; }
        .bronze { color: #cd7f32; }

        @media (max-width: 768px) {
            .game-container {
                padding: 1.5rem;
                margin: 0 10px;
            }

            .game-title {
                font-size: 2rem;
            }

            .game-description {
                font-size: 1rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .timer-container {
                width: 60px;
                height: 60px;
                font-size: 1.2rem;
            }

            .leaderboard-table th,
            .leaderboard-table td {
                padding: 0.75rem;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 576px) {
            .game-title {
                font-size: 1.75rem;
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .stat-label {
                font-size: 0.8rem;
            }

            .game-section {
                padding: 1.5rem;
            }
        }

        .achievement-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            margin: 0.2rem;
            background: rgba(255,255,255,0.1);
        }

        .achievement-gold {
            background: linear-gradient(45deg, #ffd700, #ffb347);
            color: #000;
        }

        .achievement-silver {
            background: linear-gradient(45deg, #c0c0c0, #8e8e8e);
            color: #000;
        }

        .achievement-bronze {
            background: linear-gradient(45deg, #cd7f32, #a0522d);
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="game-container">
        <!-- Game Header -->
        <div class="game-header">
            <h1 class="game-title">
                <i class="fas fa-gamepad"></i> <?= $games[$selected_game]['name'] ?>
            </h1>
            <p class="game-description"><?= $games[$selected_game]['description'] ?></p>
        </div>

        <!-- Player Stats -->
        <div class="stats-container">
            <div class="stat-card">
                <i class="fas fa-trophy"></i>
                <div class="stat-value"><?= $player_stats['highest_score'] ?? 0 ?></div>
                <div class="stat-label">Điểm Cao Nhất</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-medal gold"></i>
                <div class="stat-value"><?= $player_stats['gold_medals'] ?? 0 ?></div>
                <div class="stat-label">Huy Chương Vàng</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-medal silver"></i>
                <div class="stat-value"><?= $player_stats['silver_medals'] ?? 0 ?></div>
                <div class="stat-label">Huy Chương Bạc</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-medal bronze"></i>
                <div class="stat-value"><?= $player_stats['bronze_medals'] ?? 0 ?></div>
                <div class="stat-label">Huy Chương Đồng</div>
            </div>
        </div>

        <!-- Game Selection -->
        <div class="game-section">
            <form method="POST" id="gameSelectorForm" class="mb-4">
                <div class="row align-items-center">
                    <div class="col-md-4 col-12 mb-2 mb-md-0">
                        <select name="game_type" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($games as $type => $info): ?>
                                <option value="<?= $type ?>" <?= $selected_game === $type ? 'selected' : '' ?>>
                                    <?= $info['name'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8 col-12">
                        <div class="d-flex justify-content-md-end justify-content-start flex-wrap gap-2">
                            <span class="badge bg-info">
                                <i class="fas fa-clock"></i> <?= $games[$selected_game]['time_limit'] / 60 ?> phút
                            </span>
                            <span class="badge bg-warning">
                                <i class="fas fa-star"></i> <?= $games[$selected_game]['difficulty'] ?>
                            </span>
                            <span class="badge bg-success">
                                <i class="fas fa-coins"></i> <?= $games[$selected_game]['max_score'] ?> điểm
                            </span>
                        </div>
                    </div>
                </div>
            </form>

            <?php if ($daily_limit_reached): ?>
                <div class="alert alert-warning text-center">
                    <i class="fas fa-exclamation-triangle"></i>
                    Bạn đã đạt giới hạn số lần chơi trong ngày. Vui lòng quay lại vào ngày mai!
                </div>
            <?php elseif ($game && $game['status'] === 'pending'): ?>
                <div id="gameInterface">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 0%"></div>
                    </div>
                    <div id="questionContainer" class="question-container">
                        <!-- Questions will be loaded here -->
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Leaderboard -->
        <div class="leaderboard-container">
            <h3 class="leaderboard-title">
                <i class="fas fa-crown"></i> Bảng Xếp Hạng
            </h3>
            <table class="leaderboard-table">
                <thead>
                    <tr>
                        <th>Hạng</th>
                        <th>Nhân viên</th>
                        <th>Phòng ban</th>
                        <th>Điểm</th>
                        <th>Thời gian</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaderboard as $index => $entry): ?>
                        <tr>
                            <td>
                                <?php
                                switch ($index) {
                                    case 0:
                                        echo '<i class="fas fa-medal gold"></i>';
                                        break;
                                    case 1:
                                        echo '<i class="fas fa-medal silver"></i>';
                                        break;
                                    case 2:
                                        echo '<i class="fas fa-medal bronze"></i>';
                                        break;
                                    default:
                                        echo $index + 1;
                                }
                                ?>
                            </td>
                            <td><?= htmlspecialchars($entry['full_name']) ?></td>
                            <td><?= htmlspecialchars($entry['department_name']) ?></td>
                            <td><?= $entry['score'] ?></td>
                            <td><?= gmdate("i:s", $entry['completion_time']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Department Rankings -->
        <div class="game-section">
            <h3 class="text-center mb-4">
                <i class="fas fa-building"></i> Xếp Hạng Phòng Ban
            </h3>
            <div class="row justify-content-center">
                <?php foreach ($department_rankings as $rank): ?>
                    <div class="col-md-4 col-sm-6 mb-3">
                        <div class="stat-card">
                            <h4><?= htmlspecialchars($rank['department_name']) ?></h4>
                            <div class="stat-value"><?= round($rank['avg_score'], 1) ?></div>
                            <div class="stat-label">Điểm Trung Bình</div>
                            <div class="mt-2">
                                <small>Số người tham gia: <?= $rank['participants'] ?></small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        let currentQuestion = 0;
        let questions = <?= $game ? $game['questions'] : '[]' ?>;
        let answers = [];
        let startTime = new Date();
        let gameTimer;

        function startGame() {
            if (questions.length > 0) {
                showQuestion(0);
                startTimer();
            }
        }

        function showQuestion(index) {
            const question = questions[index];
            const container = document.getElementById('questionContainer');
            const progress = ((index + 1) / questions.length) * 100;
            document.querySelector('.progress-fill').style.width = `${progress}%`;

            let html = `
                <h4 class="question-text">${question.question_text}</h4>
                <div class="options-container">
            `;

            if (question.question_type === 'multiple_choice') {
                const options = JSON.parse(question.options);
                Object.entries(options).forEach(([key, value]) => {
                    html += `
                        <button type="button" class="option-button" onclick="selectAnswer('${key}')">
                            ${value}
                        </button>
                    `;
                });
            } else if (question.question_type === 'short_answer') {
                html += `
                    <input type="text" class="form-control" placeholder="Nhập câu trả lời..."
                           onkeyup="if(event.keyCode===13)submitAnswer(this.value)">
                `;
            }

            html += '</div>';
            container.innerHTML = html;
        }

        function selectAnswer(answer) {
            answers[currentQuestion] = answer;
            nextQuestion();
        }

        function submitAnswer(answer) {
            answers[currentQuestion] = answer;
            nextQuestion();
        }

        function nextQuestion() {
            currentQuestion++;
            if (currentQuestion < questions.length) {
                showQuestion(currentQuestion);
            } else {
                endGame();
            }
        }

        function startTimer() {
            const timerElement = document.createElement('div');
            timerElement.className = 'timer-container';
            document.body.appendChild(timerElement);

            const timeLimit = <?= $games[$selected_game]['time_limit'] ?>;
            const endTime = new Date(startTime.getTime() + timeLimit * 1000);

            gameTimer = setInterval(() => {
                const now = new Date();
                const timeLeft = Math.max(0, Math.floor((endTime - now) / 1000));

                if (timeLeft === 0) {
                    endGame();
                    return;
                }

                const minutes = FlexibleProcessorRegistry(timeLeft / 60);
                const seconds = timeLeft % 60;
                timerElement.innerHTML = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            }, 1000);
        }

        function endGame() {
            clearInterval(gameTimer);
            const completionTime = Math.floor((new Date() - startTime) / 1000);

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="game_id" value="<?= $game['id'] ?? 0 ?>">
                <input type="hidden" name="answers" value="${JSON.stringify(answers)}">
                <input type="hidden" name="completion_time" value="${completionTime}">
                <input type="hidden" name="submit_game" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        if (document.getElementById('gameInterface')) {
            startGame();
        }
    </script>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
</body>
</html>