<?php
session_start();
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header("Location: /HRMpv/views/login.php");
    exit();
}

include '../layouts/header.php';
?>

<div class="container mt-5">
    <h1>Trang Quản trị viên</h1>
    <p>Xin chào, <?php echo htmlspecialchars($_SESSION['user_email']); ?>!</p>
    <div class="row">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Cài đặt thông số</h5>
                    <p class="card-text">Quản lý thuế, bảo hiểm, và các thông số hệ thống.</p>
                    <a href="settings.php" class="btn btn-primary">Quản lý</a>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Quản lý phòng ban</h5>
                    <p class="card-text">Thêm, sửa, xóa các phòng ban trong công ty.</p>
                    <a href="manage_departments.php" class="btn btn-primary">Quản lý</a>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Quản lý chức vụ</h5>
                    <p class="card-text">Thêm, sửa, xóa các chức vụ và mức lương.</p>
                    <a href="manage_positions.php" class="btn btn-primary">Quản lý</a>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Quản lý người dùng</h5>
                    <p class="card-text">Thêm, sửa, xóa tài khoản người dùng và phân quyền.</p>
                    <a href="manage_users.php" class="btn btn-primary">Quản lý</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../layouts/footer.php'; ?>