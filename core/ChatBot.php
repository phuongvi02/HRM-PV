<?php
if (!extension_loaded('curl')) {
    die(json_encode([
        'status' => 'error',
        'response' => [
            'error' => 'Extension cURL không được cài đặt.',
            'timestamp' => date('H:i')
        ]
    ]));
}

if (!extension_loaded('json')) {
    die(json_encode([
        'status' => 'error',
        'response' => [
            'error' => 'Extension JSON không được cài đặt.',
            'timestamp' => date('H:i')
        ]
    ]));
}

class ChatBot {
    private $apiUrl = 'https://api.together.xyz/v1/chat/completions';
    private $apiKey;

    public function __construct() {
        $tokenFile = __DIR__ . '/token.txt';
        if (!file_exists($tokenFile)) {
            throw new Exception("File token.txt không tồn tại tại: " . $tokenFile);
        }
        $this->apiKey = trim(file_get_contents($tokenFile));
        if (empty($this->apiKey)) {
            throw new Exception("Không thể đọc API key từ token.txt");
        }
    }

    public function getAnswerFromTogether($question) {
        $question = trim(strip_tags($question));
        if (strlen($question) > 500) {
            throw new Exception("Câu hỏi quá dài, tối đa 500 ký tự.");
        }

        $ch = curl_init();

        $data = [
            'messages' => [
                ['role' => 'system', 'content' => 'Bạn là một trợ lý AI hữu ích và thân thiện, chuyên hỗ trợ nhân viên trong hệ thống HRM.'],
                ['role' => 'user', 'content' => $question]
            ],
            'model' => 'nvidia/llama-3.1-nemotron-70b-instruct-HF',
            'max_tokens' => 150,
            'temperature' => 0.7
        ];

        curl_setopt($ch, CURLOPT_URL, $this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = 'Lỗi cURL: ' . curl_error($ch);
            error_log(date('Y-m-d H:i:s') . " - $error - Câu hỏi: $question\n", 3, __DIR__ . '/error.log');
            curl_close($ch);
            throw new Exception($error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            if ($httpCode === 401) {
                $error = 'API key không hợp lệ hoặc đã hết hạn.';
            } elseif ($httpCode === 429) {
                $error = 'Quá nhiều yêu cầu, vui lòng thử lại sau.';
            } else {
                $error = 'Lỗi API: Mã HTTP ' . $httpCode . ' - Phản hồi: ' . $response;
            }
            error_log(date('Y-m-d H:i:s') . " - $error - Câu hỏi: $question\n", 3, __DIR__ . '/error.log');
            throw new Exception($error);
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = 'Lỗi giải mã JSON: ' . json_last_error_msg();
            error_log(date('Y-m-d H:i:s') . " - $error - Câu hỏi: $question\n", 3, __DIR__ . '/error.log');
            throw new Exception($error);
        }

        if (!isset($result['choices'][0]['message']['content'])) {
            $error = 'Không tìm thấy trường "choices" trong phản hồi';
            error_log(date('Y-m-d H:i:s') . " - $error - Câu hỏi: $question\n", 3, __DIR__ . '/error.log');
            throw new Exception($error);
        }

        return trim($result['choices'][0]['message']['content']);
    }
}