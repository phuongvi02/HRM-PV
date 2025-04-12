<?php
session_start();
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header("Location: /auth/login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HRM System - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #4f46e5;
            --secondary-color: #818cf8;
            --sidebar-width: 250px;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            min-height: 100vh;
        }

        /* Sidebar Styles */
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

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .sidebar-logo {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
        }

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

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 2rem;
        }

        /* Header */
        .dashboard-header {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            border-radius: 0.5rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
        }

        /* Recent Activity */
        .activity-card {
            background: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .activity-item {
            padding: 1rem 0;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ede9fe;
            color: var(--primary-color);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: block !important;
            }
        }

        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1001;
            background: white;
            border: none;
            padding: 0.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">Quản Trị</div>
        </div>
        <nav class="mt-4">
            <a href="index_admin.php" class="nav-link active">
                <i class="fas fa-home"></i>
                <span>Tổng quan</span>
            </a>
            <a href="manage_departments.php" class="nav-link">
                <i class="fas fa-building"></i>
                <span>Phòng ban</span>
            </a>
            <a href="manage_positions.php" class="nav-link">
                <i class="fas fa-briefcase"></i>
                <span>Chức vụ</span>
            </a>
            <a href="manage_users.php" class="nav-link">
                <i class="fas fa-users"></i>
                <span>Người dùng</span>
            </a>
            <a href="login-history.php" class="nav-link">
                <i class="fas fa-history"></i>
                <span>Lịch sử đăng nhập</span>
            </a>

    <a href="/HRMpv/views/admin/manage_promotion_requests.php" class="nav-link">
        <i class="fas fa-tasks"></i> Quản Lý Yêu Cầu Thăng Chức/Tăng Lương
    </a>
    <a href="/HRMpv/views/admin/manage_squid_game_leaderboard.php">
    <i class="fas fa-trophy"></i> Bảng Xếp Hạng Squid Game
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

    <!-- Main Content -->
    <main class="main-content">
        <div class="dashboard-header">
            <h1 class="h3">Xin chào, <?php echo htmlspecialchars($_SESSION['user_email']); ?>!</h1>
            <p class="text-muted">Chào mừng bạn đến với Hệ thống Quản lý Nhân sự</p>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-blue-100 text-blue-600">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value">150</div>
                <div class="stat-label">Tổng nhân viên</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-green-100 text-green-600">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-value">8</div>
                <div class="stat-label">Phòng ban</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-purple-100 text-purple-600">
                    <i class="fas fa-briefcase"></i>
                </div>
                <div class="stat-value">12</div>
                <div class="stat-label">Chức vụ</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-orange-100 text-orange-600">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-value">24</div>
                <div class="stat-label">Hoạt động hôm nay</div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="activity-card">
            <h2 class="h4 mb-4">Hoạt động gần đây</h2>
            <div class="activity-item">
                <div class="activity-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div>
                    <div class="fw-bold">Thêm nhân viên mới</div>
                    <div class="text-muted">Nguyễn Văn A đã được thêm vào hệ thống</div>
                    <small class="text-muted">2 giờ trước</small>
                </div>
            </div>
            <div class="activity-item">
                <div class="activity-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div>
                    <div class="fw-bold">Thay đổi phòng ban</div>
                    <div class="text-muted">Trần Thị B được chuyển sang phòng Kỹ thuật</div>
                    <small class="text-muted">4 giờ trước</small>
                </div>
            </div>
            <div class="activity-item">
                <div class="activity-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div>
                    <div class="fw-bold">Cập nhật trạng thái</div>
                    <div class="text-muted">Lê Văn C đã hoàn thành thời gian thử việc</div>
                    <small class="text-muted">1 ngày trước</small>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        }

        // Add active class to current nav item
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.location.pathname;
            const navLinks = document.querySelectorAll('.nav-link');
            
            navLinks.forEach(link => {
                if (link.getAttribute('href') === currentPath.split('/').pop()) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>