<?php
session_start();

// ============ DATABASE CONFIGURRATION ============
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'juneleez_tech');

function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die(json_encode(['success' => false, 'error' => 'Database connection failed']));
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

function initDatabase() {
    $conn = getConnection();
    
    $conn->query("CREATE TABLE IF NOT EXISTS contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(50),
        company VARCHAR(255),
        service_interest VARCHAR(100),
        budget_range VARCHAR(100),
        message TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $conn->query("CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $result = $conn->query("SELECT * FROM admin_users WHERE username = 'lee'");
    if ($result->num_rows == 0) {
        $hashed = password_hash('jesus', PASSWORD_DEFAULT);
        $conn->query("INSERT INTO admin_users (username, password_hash) VALUES ('lee', '$hashed')");
    }
    
    $conn->close();
}

initDatabase();

// ============ HANDLE API REQUESTS ============
$request_uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

if (strpos($request_uri, 'action=') !== false) {
    parse_str(parse_url($request_uri, PHP_URL_QUERY), $params);
    $action = $params['action'] ?? '';
} else {
    $action = '';
}

// ============ SUBMIT CONTACT FORM ============
if ($method === 'POST' && ($action === 'submit' || strpos($request_uri, 'submit_contact') !== false)) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    
    if (empty($input['first_name']) || empty($input['last_name']) || empty($input['email']) || empty($input['message'])) {
        echo json_encode(['success' => false, 'error' => 'All required fields must be filled']);
        exit;
    }
    
    $first_name = htmlspecialchars(strip_tags($input['first_name']));
    $last_name = htmlspecialchars(strip_tags($input['last_name']));
    $email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
    $phone = isset($input['phone']) ? htmlspecialchars(strip_tags($input['phone'])) : null;
    $company = isset($input['company']) ? htmlspecialchars(strip_tags($input['company'])) : null;
    $service_interest = isset($input['service_interest']) ? htmlspecialchars(strip_tags($input['service_interest'])) : null;
    $budget_range = isset($input['budget_range']) ? htmlspecialchars(strip_tags($input['budget_range'])) : null;
    $message = htmlspecialchars(strip_tags($input['message']));
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email format']);
        exit;
    }
    
    $conn = getConnection();
    $sql = "INSERT INTO contacts (first_name, last_name, email, phone, company, service_interest, budget_range, message) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssss", $first_name, $last_name, $email, $phone, $company, $service_interest, $budget_range, $message);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Message sent successfully!']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    
    $stmt->close();
    $conn->close();
    exit;
}

// ============ SEND REPLY (EMAIL + SMS) ============
if ($action === 'send_reply' && $method === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $messageId = (int)($input['message_id'] ?? 0);
    $replyMessage = trim($input['reply_message'] ?? '');
    $sendEmail = (bool)($input['send_email'] ?? false);
    $sendSms = (bool)($input['send_sms'] ?? false);
    
    if (!$messageId || empty($replyMessage)) {
        echo json_encode(['success' => false, 'error' => 'Message ID and reply content required']);
        exit;
    }
    
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT email, phone, first_name FROM contacts WHERE id = ?");
    $stmt->bind_param("i", $messageId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    $responses = [];
    
    // Send Email
    if ($sendEmail && !empty($user['email'])) {
        $to = $user['email'];
        $subject = "Reply from Juneleez Tech Team";
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: noreply@juneleeztech.com" . "\r\n";
        
        $emailBody = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2>Hello {$user['first_name']},</h2>
            <p>Thank you for reaching out to <strong>Juneleez Tech</strong>. Here's our response:</p>
            <div style='background: #f4f7fc; padding: 15px; border-left: 4px solid #1a6eff;'>
                " . nl2br(htmlspecialchars($replyMessage)) . "
            </div>
            <p>Feel free to reply to this email if you have any further questions.</p>
            <hr>
            <small>Juneleez Tech - Innovation Meets Excellence</small>
        </body>
        </html>
        ";
        
        if (mail($to, $subject, $emailBody, $headers)) {
            $responses[] = "Email sent to {$user['email']}";
        } else {
            $responses[] = "Email failed to send";
        }
    }
    
    // Send SMS via Textbelt (free tier: 1 SMS/day)
    if ($sendSms && !empty($user['phone'])) {
        $phoneNumber = $user['phone'];
        $smsContent = "Juneleez Tech: " . substr($replyMessage, 0, 150);
        
        // Using Textbelt (free tier)
        $smsData = [
            'phone' => $phoneNumber,
            'message' => $smsContent,
            'key' => 'textbelt'  // For free tier, use 'textbelt'; for paid, put your API key
        ];
        
        $ch = curl_init('https://textbelt.com/text');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($smsData));
        $result = curl_exec($ch);
        curl_close($ch);
        
        $smsResult = json_decode($result, true);
        if ($smsResult && ($smsResult['success'] ?? false)) {
            $responses[] = "SMS sent to {$user['phone']}";
        } else {
            $responses[] = "SMS failed: " . ($smsResult['error'] ?? 'unknown error');
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => implode(" | ", $responses)
    ]);
    exit;
}

// ============ ADMIN LOGIN ============
if ($method === 'POST' && $action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    
    $conn = getConnection();
    $sql = "SELECT * FROM admin_users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            echo json_encode(['success' => true, 'message' => 'Login successful']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid password']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'User not found']);
    }
    
    $stmt->close();
    $conn->close();
    exit;
}

if ($action === 'check_auth') {
    header('Content-Type: application/json');
    echo json_encode(['logged_in' => isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true]);
    exit;
}

if ($action === 'logout') {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ============ GET MESSAGES ============
if (($action === 'get_messages' || $action === 'messages') && $method === 'GET') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;
    $status = isset($_GET['status']) ? $_GET['status'] : 'all';
    
    $conn = getConnection();
    
    $where = "";
    if ($status === 'read') $where = "WHERE is_read = 1";
    elseif ($status === 'unread') $where = "WHERE is_read = 0";
    
    $countSql = "SELECT COUNT(*) as total FROM contacts $where";
    $countResult = $conn->query($countSql);
    $total = $countResult->fetch_assoc()['total'];
    
    $sql = "SELECT * FROM contacts $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    
    $unreadResult = $conn->query("SELECT COUNT(*) as unread FROM contacts WHERE is_read = 0");
    $unreadCount = $unreadResult->fetch_assoc()['unread'];
    
    echo json_encode([
        'success' => true,
        'data' => $messages,
        'total' => $total,
        'unread_count' => $unreadCount,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ]);
    
    $stmt->close();
    $conn->close();
    exit;
}

if ($action === 'mark_read' && $method === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Message ID required']);
        exit;
    }
    
    $conn = getConnection();
    $sql = "UPDATE contacts SET is_read = 1 WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Marked as read']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Update failed']);
    }
    
    $stmt->close();
    $conn->close();
    exit;
}

if ($action === 'delete' && $method === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Message ID required']);
        exit;
    }
    
    $conn = getConnection();
    $sql = "DELETE FROM contacts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Delete failed']);
    }
    
    $stmt->close();
    $conn->close();
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Juneleez Tech - Admin Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0a1a2e 0%, #0d2250 100%);
            min-height: 100vh;
        }
        
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .login-box {
            background: rgba(13,34,80,0.95);
            border: 1px solid rgba(0,229,255,0.3);
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
            
        }
        
        .login-box h1 { color: #00e5ff; font-size: 28px; margin-bottom: 10px; text-align: center; }
        .login-box p { color: #8899bb; text-align: center; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; color: #00e5ff; margin-bottom: 8px; font-size: 14px; }
        .form-group input {
            width: 100%;
            padding: 12px;
            background: rgba(10,22,40,0.8);
            border: 1px solid rgba(0,229,255,0.2);
            border-radius: 8px;
            color: white;
            font-size: 16px;
        }
        .form-group input:focus { outline: none; border-color: #00e5ff; }
        
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #00e5ff, #0066ff);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        button:hover { transform: translateY(-2px); }
         .alogo{
            text-align: center;
        }
        
        .error-message {
            background: rgba(255,0,0,0.2);
            border: 1px solid red;
            color: #ff6b6b;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .admin-container { display: none; }
        
        .admin-header {
            background: linear-gradient(135deg, #0d2250, #0a1a2e);
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(0,229,255,0.3);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .admin-header h1 { color: #00e5ff; font-size: 24px; }
        .user-info { display: flex; gap: 20px; align-items: center; color: #e0e6f0; }
        .logout-btn {
            background: rgba(255,59,48,0.2);
            color: #ff6b6b;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            border: 1px solid #ff3b30;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
        }
        .stat-card {
            background: rgba(13,34,80,0.6);
            border: 1px solid rgba(0,229,255,0.2);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        .stat-card h3 { color: #8899bb; font-size: 14px; margin-bottom: 10px; }
        .stat-card .number { font-size: 32px; color: #00e5ff; font-weight: bold; }
        
        .filters { padding: 0 30px 20px; display: flex; gap: 10px; flex-wrap: wrap; }
        .filter-btn {
            padding: 8px 16px;
            background: rgba(13,34,80,0.6);
            border: 1px solid rgba(0,229,255,0.2);
            border-radius: 8px;
            color: #e0e6f0;
            cursor: pointer;
        }
        .filter-btn.active { background: #00e5ff; color: #0a1a2e; }
        
        .messages-container { padding: 0 30px 30px; }
        .message-card {
            background: rgba(13,34,80,0.4);
            border: 1px solid rgba(0,229,255,0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
        }
        .message-card.unread { border-left: 3px solid #00e5ff; background: rgba(0,229,255,0.05); }
        .message-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .message-info h3 { color: #00e5ff; font-size: 18px; margin-bottom: 5px; }
        .message-info p { color: #8899bb; font-size: 12px; }
        .message-actions { display: flex; gap: 10px; }
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 12px;
        }
        .read-btn { background: rgba(0,229,255,0.2); color: #00e5ff; }
        .delete-btn { background: rgba(255,59,48,0.2); color: #ff6b6b; }
        .reply-btn { background: rgba(76,175,80,0.2); color: #4caf50; }
        .message-content { color: #c0cce0; line-height: 1.6; }
        .message-details {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(0,229,255,0.1);
            font-size: 12px;
            color: #8899bb;
            flex-wrap: wrap;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 30px;
            padding-bottom: 30px;
        }
        .page-btn {
            padding: 8px 12px;
            background: rgba(13,34,80,0.6);
            border: 1px solid rgba(0,229,255,0.2);
            border-radius: 6px;
            color: #e0e6f0;
            cursor: pointer;
        }
        .page-btn.active { background: #00e5ff; color: #0a1a2e; }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: #0d2250;
            border: 1px solid #00e5ff;
            border-radius: 16px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            color: white;
        }
        .modal-content h2 { color: #00e5ff; margin-bottom: 20px; }
        .modal-content textarea {
            width: 100%;
            padding: 10px;
            background: #0a1a2e;
            border: 1px solid #00e5ff;
            color: white;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .modal-content label { display: block; margin-bottom: 10px; }
        .modal-buttons { display: flex; gap: 10px; justify-content: flex-end; }
        .modal-buttons button { width: auto; padding: 8px 16px; }
        .close-modal { background: #ff3b30; }
        
        .loading { text-align: center; padding: 40px; color: #00e5ff; }
        
        @media (max-width: 768px) {
            .stats-container, .filters, .messages-container { padding: 20px; }
            .message-header { flex-direction: column; }
            .message-details { flex-direction: column; gap: 5px; }
        }

        body.light-mode {
            background: linear-gradient(135deg, #e8f0ff 0%, #f0f4ff 100%);
            color: #0d1a30;
        }
        body.light-mode .login-box {
            background: rgba(255,255,255,0.97);
            border-color: rgba(26,110,255,0.3);
        }
        body.light-mode .login-box h1 { color: #1a6eff; }
        body.light-mode .form-group input {
            background: #f4f7ff;
            border-color: rgba(26,110,255,0.25);
            color: #0d1a30;
        }
        body.light-mode .admin-header {
            background: linear-gradient(135deg, #dce8ff, #eef3ff);
            border-bottom-color: rgba(26,110,255,0.2);
        }
        body.light-mode #adminName {
            background: linear-gradient(135deg, #6195f4, #eef3ff);
            color: #010f2a;
            border-radius:5px;
            padding:3px;
        }
        body.light-mode .admin-header h1 { color: #1a6eff; }
        body.light-mode .stat-card {
            background: rgba(255,255,255,0.85);
            border-color: rgba(26,110,255,0.18);
        }
        body.light-mode .stat-card .number { color: #1a6eff; }
        body.light-mode .filter-btn {
            background: rgba(255,255,255,0.85);
            color: #1a2a4a;
        }
        body.light-mode .filter-btn.active { background: #1a6eff; color: #fff; }
        body.light-mode .message-card {
            background: rgba(255,255,255,0.8);
            border-color: rgba(26,110,255,0.12);
        }
        body.light-mode .message-card.unread {
            border-left-color: #1a6eff;
            background: rgba(26,110,255,0.04);
        }
        body.light-mode .message-info h3 { color: #1a6eff; }
        body.light-mode .message-content { color: #1a2a4a; }
        body.light-mode .modal-content {
            background: #ffffff;
            border-color: #1a6eff;
            color: #0d1a30;
        }
        body.light-mode .modal-content textarea {
            background: #f4f7ff;
            border-color: #1a6eff;
            color: #0d1a30;
        }
        
        #theme-toggle {
            position: fixed;
            bottom: 28px;
            right: 28px;
            z-index: 9999;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 1px solid rgba(0,229,255,0.4);
            background: rgba(13,34,80,0.8);
            backdrop-filter: blur(12px);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
       
    </style>
</head>
<body>
    <div id="loginSection" class="login-container">
        <div class="login-box">
            <div class="alogo" ><img src="HALF.png" alt="" style="height: 90px;width: 150px;"></div>
            <h1>Admin Login</h1>
            <p>Juneleez Tech</p>
            <div id="loginError" class="error-message" style="display: none;"></div>
            <form id="loginForm">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="username" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" id="password" required>
                </div>
                <button type="submit">Login →</button>
            </form>
        </div>
    </div>
    
    <div id="adminSection" class="admin-container">
        <div class="admin-header">
            <h1>📋 Juneleez Tech - Admin Dashboard</h1>
            <div class="user-info">
                <span id="adminName">Admin</span>
                <a href="?action=logout" class="logout-btn">Logout</a>
            </div>
        </div>
        
        <div class="stats-container">
            <div class="stat-card"><h3>Total Messages</h3><div class="number" id="totalCount">0</div></div>
            <div class="stat-card"><h3>Unread</h3><div class="number" id="unreadCount">0</div></div>
            <div class="stat-card"><h3>Read</h3><div class="number" id="readCount">0</div></div>
        </div>
        
        <div class="filters">
            <button class="filter-btn active" data-status="all">All Messages</button>
            <button class="filter-btn" data-status="unread">Unread</button>
            <button class="filter-btn" data-status="read">Read</button>
        </div>
        
        <div class="messages-container" id="messagesContainer">
            <div class="loading">Loading messages...</div>
        </div>
        
        <div class="pagination" id="pagination"></div>
    </div>
    
    <!-- Reply Modal -->
    <div id="replyModal" class="modal">
        <div class="modal-content">
            <h2>✉️ Reply to User</h2>
            <textarea id="replyMessageText" rows="5" placeholder="Type your reply here..."></textarea>
            <label><input type="checkbox" id="replyEmailCheck" checked> Send via Email</label>
            <label><input type="checkbox" id="replySmsCheck"> Send via SMS (if phone provided)</label>
            <div class="modal-buttons">
                <button onclick="closeReplyModal()" class="close-modal">Cancel</button>
                <button onclick="sendReply()" style="background: #4caf50;">Send Reply</button>
            </div>
        </div>
    </div>
    
    <script>
        let currentReplyId = null;
        
        async function checkAuth() {
            try {
                const response = await fetch('admin.php?action=check_auth');
                const data = await response.json();
                if (data.logged_in) {
                    showAdminPanel();
                    loadMessages();
                }
            } catch (error) {
                console.error('Auth check failed:', error);
            }
        }
        
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            
            try {
                const response = await fetch('admin.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });
                const data = await response.json();
                if (data.success) {
                    showAdminPanel();
                    loadMessages();
                } else {
                    const errorDiv = document.getElementById('loginError');
                    errorDiv.textContent = data.error;
                    errorDiv.style.display = 'block';
                    setTimeout(() => { errorDiv.style.display = 'none'; }, 3000);
                }
            } catch (error) {
                alert('Login failed. Please try again.');
            }
        });
        
        function showAdminPanel() {
            document.getElementById('loginSection').style.display = 'none';
            document.getElementById('adminSection').style.display = 'block';
        }
        
        let currentPage = 1;
        let currentStatus = 'all';
        let totalPages = 1;
        
        async function loadMessages() {
            try {
                const response = await fetch(`admin.php?action=get_messages&page=${currentPage}&limit=10&status=${currentStatus}`);
                const data = await response.json();
                if (data.success) {
                    displayMessages(data.data);
                    updateStats(data.total, data.unread_count);
                    updatePagination(data.pagination);
                } else {
                    document.getElementById('messagesContainer').innerHTML = '<div class="loading">Error loading messages</div>';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('messagesContainer').innerHTML = '<div class="loading">Error connecting to server</div>';
            }
        }
        
        function displayMessages(messages) {
            if (messages.length === 0) {
                document.getElementById('messagesContainer').innerHTML = '<div class="loading">No messages found</div>';
                return;
            }
            
            const html = messages.map(msg => `
                <div class="message-card ${msg.is_read ? '' : 'unread'}" data-id="${msg.id}">
                    <div class="message-header">
                        <div class="message-info">
                            <h3>${escapeHtml(msg.first_name)} ${escapeHtml(msg.last_name)}</h3>
                            <p>${escapeHtml(msg.email)} • ${msg.phone ? escapeHtml(msg.phone) : 'No phone'}</p>
                        </div>
                        <div class="message-actions">
                            ${!msg.is_read ? `<button class="action-btn read-btn" onclick="markAsRead(${msg.id})">📖 Mark as Read</button>` : ''}
                            <button class="action-btn reply-btn" onclick="openReplyModal(${msg.id})">✉️ Reply</button>
                            <button class="action-btn delete-btn" onclick="deleteMessage(${msg.id})">🗑️ Delete</button>
                        </div>
                    </div>
                    <div class="message-content">
                        <strong>Message:</strong><br>
                        ${escapeHtml(msg.message)}
                    </div>
                    <div class="message-details">
                        <span>🏢 ${msg.company || 'No company'}</span>
                        <span>💼 ${msg.service_interest || 'No service selected'}</span>
                        <span>💰 ${msg.budget_range || 'No budget specified'}</span>
                        <span>📅 ${new Date(msg.created_at).toLocaleString()}</span>
                    </div>
                </div>
            `).join('');
            
            document.getElementById('messagesContainer').innerHTML = html;
        }
        
        function updateStats(total, unreadCount) {
            document.getElementById('totalCount').textContent = total;
            document.getElementById('unreadCount').textContent = unreadCount;
            document.getElementById('readCount').textContent = total - unreadCount;
        }
        
        function updatePagination(pagination) {
            totalPages = pagination.total_pages;
            let paginationHtml = '';
            for (let i = 1; i <= totalPages; i++) {
                paginationHtml += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
            }
            document.getElementById('pagination').innerHTML = paginationHtml;
        }
        
        function goToPage(page) { currentPage = page; loadMessages(); }
        
        async function markAsRead(id) {
            try {
                const response = await fetch('admin.php?action=mark_read', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();
                if (data.success) loadMessages();
            } catch (error) { alert('Error marking message as read'); }
        }
        
        async function deleteMessage(id) {
            if (confirm('Are you sure you want to delete this message?')) {
                try {
                    const response = await fetch('admin.php?action=delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: id })
                    });
                    const data = await response.json();
                    if (data.success) loadMessages();
                    else alert('Error deleting message');
                } catch (error) { alert('Error deleting message'); }
            }
        }
        
        function openReplyModal(id) {
            currentReplyId = id;
            document.getElementById('replyMessageText').value = '';
            document.getElementById('replyEmailCheck').checked = true;
            document.getElementById('replySmsCheck').checked = false;
            document.getElementById('replyModal').style.display = 'flex';
        }
        
        function closeReplyModal() {
            document.getElementById('replyModal').style.display = 'none';
            currentReplyId = null;
        }
        
        async function sendReply() {
            const replyMessage = document.getElementById('replyMessageText').value.trim();
            const sendEmail = document.getElementById('replyEmailCheck').checked;
            const sendSms = document.getElementById('replySmsCheck').checked;
            
            if (!replyMessage) {
                alert('Please enter a reply message.');
                return;
            }
            
            if (!sendEmail && !sendSms) {
                alert('Please select at least one method (Email or SMS).');
                return;
            }
            
            try {
                const response = await fetch('admin.php?action=send_reply', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        message_id: currentReplyId,
                        reply_message: replyMessage,
                        send_email: sendEmail,
                        send_sms: sendSms
                    })
                });
                const data = await response.json();
                if (data.success) {
                    alert(data.message);
                    closeReplyModal();
                    loadMessages(); // Refresh to show any updates
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                alert('Failed to send reply. Check your connection.');
            }
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentStatus = btn.dataset.status;
                currentPage = 1;
                loadMessages();
            });
        });
        
        checkAuth();
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('replyModal');
            if (event.target === modal) closeReplyModal();
        }
    </script>
    
    <button id="theme-toggle" title="Toggle dark/light mode">🌙</button>
    <script>
        const toggleBtn = document.getElementById('theme-toggle');
        function applyTheme(dark) {
            document.body.classList.toggle('light-mode', !dark);
            toggleBtn.textContent = dark ? '🌙' : '☀️';
            localStorage.setItem('jt-admin-theme', dark ? 'dark' : 'light');
        }
        const saved = localStorage.getItem('jt-admin-theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(saved ? saved === 'dark' : prefersDark);
        toggleBtn.addEventListener('click', () => applyTheme(document.body.classList.contains('light-mode')));
    </script>
</body>
</html>