<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action     = $_GET['action'] ?? '';
$token      = $_GET['token'] ?? '';
$account_id = $_GET['account_id'] ?? '';

if (!$token || !$account_id) {
    echo json_encode(['success' => false, 'errors' => [['message' => 'Thiếu token hoặc account_id']]]);
    exit;
}

function cf_request(string $method, string $url, string $token, array $body = []): array {
    $opts = [
        'http' => [
            'method'  => $method,
            'header'  => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ]
    ];
    if ($body) {
        $opts['http']['content'] = json_encode($body);
    }
    $ctx  = stream_context_create($opts);
    $raw  = file_get_contents($url, false, $ctx);
    return json_decode($raw ?: '{}', true) ?? [];
}

switch ($action) {

    // Lấy danh sách ảnh (hỗ trợ phân trang qua continuation_token)
    case 'list':
        $per_page = min((int)($_GET['per_page'] ?? 100), 10000);
        $cursor   = $_GET['cursor'] ?? '';
        $url      = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/images/v1?per_page={$per_page}";
        if ($cursor) $url .= "&continuation_token=" . urlencode($cursor);
        echo json_encode(cf_request('GET', $url, $token));
        break;

    // Lấy chi tiết 1 ảnh
    case 'detail':
        $id  = $_GET['id'] ?? '';
        if (!$id) { echo json_encode(['success'=>false,'errors'=>[['message'=>'Thiếu id']]]); break; }
        $url = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/images/v1/{$id}";
        echo json_encode(cf_request('GET', $url, $token));
        break;

    // Xoá ảnh
    case 'delete':
        $id  = $_GET['id'] ?? '';
        if (!$id) { echo json_encode(['success'=>false,'errors'=>[['message'=>'Thiếu id']]]); break; }
        $url = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/images/v1/{$id}";
        echo json_encode(cf_request('DELETE', $url, $token));
        break;

    // Upload ảnh từ URL
    case 'upload_url':
        $image_url = $_POST['url'] ?? $_GET['url'] ?? '';
        if (!$image_url) { echo json_encode(['success'=>false,'errors'=>[['message'=>'Thiếu url ảnh']]]); break; }

        $boundary = uniqid();
        $body     = "--{$boundary}\r\nContent-Disposition: form-data; name=\"url\"\r\n\r\n{$image_url}\r\n--{$boundary}--\r\n";
        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Authorization: Bearer {$token}\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\n",
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ]
        ];
        $raw = file_get_contents(
            "https://api.cloudflare.com/client/v4/accounts/{$account_id}/images/v1",
            false,
            stream_context_create($opts)
        );
        echo $raw ?: json_encode(['success'=>false,'errors'=>[['message'=>'Upload thất bại']]]);
        break;

    // Upload ảnh từ file máy tính
    case 'upload_file':
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success'=>false,'errors'=>[['message'=>'Không nhận được file']]]);
            break;
        }

        $file     = $_FILES['file'];
        $tmpPath  = $file['tmp_name'];
        $fileName = basename($file['name']);
        $mimeType = mime_content_type($tmpPath) ?: 'application/octet-stream';
        $fileData = file_get_contents($tmpPath);

        if ($fileData === false) {
            echo json_encode(['success'=>false,'errors'=>[['message'=>'Không đọc được file']]]);
            break;
        }

        $boundary = '----CFUpload' . uniqid();
        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileName}\"\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= $fileData . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Authorization: Bearer {$token}\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\n",
                'content' => $body,
                'timeout' => 60,
                'ignore_errors' => true,
            ]
        ];
        $raw = file_get_contents(
            "https://api.cloudflare.com/client/v4/accounts/{$account_id}/images/v1",
            false,
            stream_context_create($opts)
        );
        echo $raw ?: json_encode(['success'=>false,'errors'=>[['message'=>'Upload thất bại']]]);
        break;

    default:
        echo json_encode(['success' => false, 'errors' => [['message' => 'Action không hợp lệ']]]);
}
