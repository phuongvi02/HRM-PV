<?php
// Kiểm tra session nếu cần (tùy vào cách bạn tổ chức mã nguồn)
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header("Location: /auth/login.php");
    exit();
}
?>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">Quản Trị</div>
    </div>
    <nav class="mt-4">
        <a href="/HRMpv/views/admin/index_admin.php" class="nav-link">
            <i class="fas fa-home"></i>
            <span>Tổng quan</span>
        </a>
        <a href="/HRMpv/views/admin/manage_departments.php" class="nav-link">
            <i class="fas fa-building"></i>
            <span>Phòng ban</span>
        </a>
        <a href="/HRMpv/views/admin/manage_positions.php" class="nav-link">
            <i class="fas fa-briefcase"></i>
            <span>Chức vụ</span>
        </a>
        <a href="/HRMpv/views/admin/manage_users.php" class="nav-link">
            <i class="fas fa-users"></i>
            <span>Người dùng</span>
        </a>
        <a href="/HRMpv/views/admin/login-history.php" class="nav-link">
            <i class="fas fa-history"></i>
            <span>Lịch sử đăng nhập</span>
        </a>
        <a href="/HRMpv/views/admin/manage_promotion_requests.php" class="nav-link">
            <i class="fas fa-tasks"></i>
            <span>Quản Lý Yêu Cầu Thăng Chức/Tăng Lương</span>
        </a>
        <a href="/HRMpv/views/admin/manage_squid_game_leaderboard.php" class="nav-link">
            <i class="fas fa-gamepad"></i>
            <span>Bảng Xếp Hạng Squid Game</span>
        </a>
        <a href="settings.php" class="nav-link">
            <i class="fas fa-tools"></i>
            <span>Cài đặt</span>
        </a>
        <a href="/HRMpv/views/auth/logout.php" class="nav-link text-danger">
            <i class="fas fa-sign-out-alt"></i>
            <span>Đăng xuất</span>
        </a>
    </nav>
</div>
<style>
    /* Biến CSS */
    :root {
        --primary-color: #4f46e5;    /* Màu chính */
        --secondary-color: #818cf8;  /* Màu phụ */
        --sidebar-width: 250px;     /* Chiều rộng sidebar */
    }

    /* Cấu hình chung cho sidebar */
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        bottom: 0;
        width: var(--sidebar-width);
        background: white;
        box-shadow: 2px 0 5px rgba(0, 0, 0, 0.05);
        z-index: 1000;
        transition: transform 0.3s ease;
    }

    /* Header của sidebar */
    .sidebar-header {
        padding: 1.5rem;
        border-bottom: 1px solid #e5e7eb;
    }

    .sidebar-logo {
        font-size: 1.5rem;
        font-weight: 600;
        color: var(--primary-color);
    }

    /* Định dạng các liên kết điều hướng */
    .nav-link {
        padding: 0.75rem 1.5rem;
        color: #4b5563;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        transition: all 0.2s;
    }

    .nav-link:hover {
        background-color: #f3f4f6;
        color: var(--primary-color);
    }

    .nav-link.active {
        background-color: #ede9fe;
        color: var(--primary-color);
        border-right: 3px solid var(--primary-color);
    }

    /* Responsive cho màn hình nhỏ */
    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }
    }
</style>