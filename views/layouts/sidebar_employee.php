<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>

<div class="sidebar">
    <div class="sidebar-header">
        <h3>HRM Nhân Viên</h3>
    </div>
    
    <ul class="list-unstyled">
        <!-- Trang chủ -->
        <li class="<?= $current_page == 'index_employee.php' ? 'active' : '' ?>">
            <a href="index_employee.php">
                <i class="fas fa-house-user"></i> Trang Chủ
            </a>
        </li>

        <!-- Chấm công -->
        <li class="<?= $current_page == 'checkin.php' ? 'active' : '' ?>">
            <a href="checkin.php">
                <i class="fas fa-user-check"></i> Checkin
            </a>
        </li>
        <li class="<?= $current_page == 'checkout.php' ? 'active' : '' ?>">
            <a href="checkout.php">
                <i class="fas fa-door-open"></i> Checkout
            </a>
        </li>

        <!-- Yêu cầu của nhân viên -->
        <li class="<?= $current_page == 'leave-request.php' ? 'active' : '' ?>">
            <a href="leave-request.php">
                <i class="fas fa-plane-departure"></i> Xin Nghỉ Phép
            </a>
        </li>
        <li class="<?= $current_page == 'salary-advance.php' ? 'active' : '' ?>">
            <a href="salary-advance.php">
                <i class="fas fa-hand-holding-dollar"></i> Tạm Ứng Lương
            </a>
        </li>
        <li class="<?= $current_page == 'overtime-request.php' ? 'active' : '' ?>">
            <a href="overtime-request.php">
                <i class="fas fa-user-plus"></i> Đăng Ký Làm Thêm
            </a>
        </li>

        <!-- Lương và thăng chức -->
        <li class="<?= $current_page == 'salary-history.php' ? 'active' : '' ?>">
            <a href="salary-history.php">
                <i class="fas fa-receipt"></i> Lịch Sử Lương
            </a>
        </li>
        <li class="<?= $current_page == 'promotion_request.php' ? 'active' : '' ?>">
            <a href="/HRMpv/views/employee/promotion_request.php">
                <i class="fas fa-arrow-up"></i> Yêu Cầu Thăng Chức/Tăng Lương
            </a>
        </li>
      <!-- Giải trí -->
<li>
    <a href="/HRMpv/views/employee/squid_game_entertainment.php">
        <i class="fas fa-gamepad"></i> Squid Game - Giải Trí
    </a>
</li>

<!-- Giải trình -->
<li>
    <a href="/HRMpv/views/employee/attendance.php">
        <i class="fas fa-clipboard-check"></i> Giải trình
    </a>
</li>
        <!-- Đăng xuất -->
        <li>
            <a class="dropdown-item" href="/HRMpv/views/auth/logout.php">
                <i class="fas fa-arrow-right-from-bracket"></i> Đăng Xuất
            </a>
        </li>
    </ul>
</div>

<style>
.sidebar {
    width: 250px;
    min-height: 100vh; /* Chiều cao full màn hình */
    position: fixed;
    top: 0;  /* Dính sát lên trên */
    bottom: 0;
    left: 0;
    z-index: 100;
    background: #2c3e50;
    color: white;
    transition: all 0.3s;
    overflow-y: auto;
}
.sidebar {
    top: 0 !important;
    min-height: 100vh !important;
}

.sidebar-header {
    text-align: center;
    padding: 15px 10px;
    font-size: 1.2rem;
    font-weight: bold;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    background: #1a252f; /* Màu header */
}

.sidebar ul {
    flex-grow: 1;
    padding: 0;
    margin: 0;
    list-style: none;
}

.sidebar ul li {
    padding: 10px 20px;
}

.sidebar ul li a {
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    transition: background 0.3s;
}

.sidebar ul li a i {
    margin-right: 10px;
}

.sidebar ul li:hover, 
.sidebar ul li.active {
    background: #34495e;
    border-left: 4px solid #1abc9c;
}

/* Giữ phần Đăng xuất ở cuối sidebar */
.sidebar ul li:last-child {
    margin-top: auto;
    border-top: 1px solid rgba(255, 255, 255, 0.2);
}

</style>
