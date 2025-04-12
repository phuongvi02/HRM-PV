<?php
require_once __DIR__ . "/../../core/ChatBot.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode([
        'status' => 'error',
        'response' => [
            'error' => 'Phương thức không được hỗ trợ. Vui lòng sử dụng POST.',
            'timestamp' => date('H:i')
        ]
    ]));
}

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Dữ liệu đầu vào không phải JSON hợp lệ: ' . json_last_error_msg());
    }

    if (!is_array($input)) {
        throw new Exception('Dữ liệu đầu vào không hợp lệ.');
    }

    $question = $input['question'] ?? '';
    if (empty($question)) {
        throw new Exception('Vui lòng nhập câu hỏi!');
    }

    $chatbot = new ChatBot();
    $answer = $chatbot->getAnswerFromTogether($question);

    echo json_encode([
        'status' => 'success',
        'response' => [
            'answer' => $answer,
            'timestamp' => date('H:i')
        ]
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'response' => [
            'error' => $e->getMessage(),
            'timestamp' => date('H:i')
        ]
    ]);
}
exit;