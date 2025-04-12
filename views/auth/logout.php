<?php
session_start();
require_once __DIR__ . "/../../core/Database.php";

if (isset($_SESSION['user_id'])) {
    $db = Database::getInstance()->getConnection();
    
    // Update the latest login history record for this user
    $update_query = "
        UPDATE login_history 
        SET logout_time = NOW(),
            session_status = 'logged_out'
        WHERE user_id = :user_id 
        AND logout_time IS NULL 
        ORDER BY login_time DESC 
        LIMIT 1
    ";
    
    $stmt = $db->prepare($update_query);
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: /HRMpv/views/auth/job_application.php");
exit();