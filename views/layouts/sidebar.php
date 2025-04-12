<div class="sidebar bg-dark">
    <div class="sidebar-heading text-center py-4 text-light">
        <i class="fas fa-users-cog fa-2x"></i>
        <h6 class="mt-2">Quản Lý Nhân Sự</h6>
    </div>

    <div class="list-group list-group-flush">
        <!-- Tổng quan -->
        <a href="#overviewSubmenu" class="list-group-item list-group-item-action bg-dark text-light" data-bs-toggle="collapse">
            <i class="fas fa-tachometer-alt me-2"></i>Tổng quan
            <i class="fas fa-angle-down float-end"></i>
        </a>
        <div class="collapse" id="overviewSubmenu">
            <a href="/HRMPV/views/index_admin.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-tachometer-alt me-2"></i>Trang chính
            </a>
            <a href="/HRMPV/views/salary/chatbot.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-robot me-2"></i>Chatbot
            </a>
        </div>

        <!-- Nhân viên -->
        <a href="#employeeSubmenu" class="list-group-item list-group-item-action bg-dark text-light" data-bs-toggle="collapse">
            <i class="fas fa-user-tie me-2"></i>Nhân viên
            <i class="fas fa-angle-down float-end"></i>
        </a>
        <div class="collapse" id="employeeSubmenu">
            <a href="/HRMPV/views/HR/list_employee.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-list me-2"></i>Danh sách
            </a>
            <a href="/HRMPV/views/HR/add_employee.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-plus me-2"></i>Thêm mới
            </a>
            <a href="/HRMPV/views/HR/leave-approve.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-plus me-2"></i>Xin nghỉ
            </a>
            <a href="/HRMPV/views/HR/leave-history.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-plus me-2"></i>Lịch sử nghỉ 
            </a>
            <a href="/HRMPV/views/HR/attendance.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-plus me-2"></i>Chấm công 
            </a>
            
        </div>

        <!-- Phòng ban -->
        <a href="#departmentSubmenu" class="list-group-item list-group-item-action bg-dark text-light" data-bs-toggle="collapse">
            <i class="fas fa-building me-2"></i>Phòng ban
            <i class="fas fa-angle-down float-end"></i>
        </a>
        <div class="collapse" id="departmentSubmenu">
            <a href="/HRMPV/views/departments/list.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-list me-2"></i>Danh sách
            </a>
            <a href="/HRMPV/views/departments/create.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-plus me-2"></i>Thêm mới
            </a>
            <a href="/HRMPV/views/HR/add_reward.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-plus me-2"></i>Thưởng 
                
            </a>
        </div>

        <!-- Hợp đồng -->
        <a href="#contractSubmenu" class="list-group-item list-group-item-action bg-dark text-light" data-bs-toggle="collapse">
            <i class="fas fa-file-contract me-2"></i>Hợp đồng
            <i class="fas fa-angle-down float-end"></i>
        </a>
        <div class="collapse" id="contractSubmenu">
            <a href="/HRMPV/views/HR/contract.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-list me-2"></i>Danh sách
            </a>
            <a href="/HRMPV/views/HR/create_contract.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-plus me-2"></i>Thêm mới
                
            </a>
            <a href="/HRMPV/views/HR/add_reward.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-plus me-2"></i>Thưởng 
                
            </a>
        </div>

        <!-- Chấm công -->
        <a href="#attendanceSubmenu" class="list-group-item list-group-item-action bg-dark text-light" data-bs-toggle="collapse">
            <i class="fas fa-calendar-check me-2"></i>Chấm công
            <i class="fas fa-angle-down float-end"></i>
        </a>
        <div class="collapse" id="attendanceSubmenu">
            <a href="/HRMPV/views/HR/daily.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-clock me-2"></i>Chấm công ngày
            </a>
            <a href="/HRMPV/views/attendance/monthly.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-calendar-alt me-2"></i>Bảng công tháng
            </a>
        </div>

        <!-- Lương -->
        <a href="#salarySubmenu" class="list-group-item list-group-item-action bg-dark text-light" data-bs-toggle="collapse">
            <i class="fas fa-money-bill-wave me-2"></i>Lương
            <i class="fas fa-angle-down float-end"></i>
        </a>
        <div class="collapse" id="salarySubmenu">
            <a href="/HRMPV/views/ketoan/tinhluong.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-calculator me-2"></i>Tính lương
            </a>
            <a href="/HRMPV/views/salary/payroll.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-file-invoice-dollar me-2"></i>Bảng lương
            </a>
        </div>

        <!-- Nghỉ phép -->
        <a href="#leaveSubmenu" class="list-group-item list-group-item-action bg-dark text-light" data-bs-toggle="collapse">
            <i class="fas fa-bed me-2"></i>Nghỉ phép
            <i class="fas fa-angle-down float-end"></i>
        </a>
        <div class="collapse" id="leaveSubmenu">
            <a href="/HRMPV/views/leave/requests.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-list-alt me-2"></i>Đơn xin nghỉ
            </a>
            <a href="/HRMPV/views/HR/approve-requests.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-check-circle me-2"></i>Phê duyệt
            </a>
        </div>

        <!-- Báo cáo -->
        <a href="#reportsSubmenu" class="list-group-item list-group-item-action bg-dark text-light" data-bs-toggle="collapse">
            <i class="fas fa-chart-bar me-2"></i>Báo cáo
            <i class="fas fa-angle-down float-end"></i>
        </a>
        <div class="collapse" id="reportsSubmenu">
            <a href="/HRMPV/views/baocao/reports.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-users me-2"></i>Nhân sự
            </a>
            <a href="/HRMPV/views/reports/salary.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-dollar-sign me-2"></i>Lương thưởng
            </a>
        </div>

        <!-- Cài đặt -->
        <a href="#settingsSubmenu" class="list-group-item list-group-item-action bg-dark text-light" data-bs-toggle="collapse">
            <i class="fas fa-cog me-2"></i>Cài đặt
            <i class="fas fa-angle-down float-end"></i>
        </a>
        <div class="collapse" id="settingsSubmenu">
            <a href="/HRMPV/views/settings/company.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-building me-2"></i>Công ty
            </a>
            <a href="/HRMPV/views/settings/users.php" class="list-group-item list-group-item-action bg-secondary text-light ps-4">
                <i class="fas fa-users-cog me-2"></i>Người dùng
            </a>
        </div>
    </div>
</div>

<!-- Thêm các thư viện JavaScript -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- JavaScript để xử lý toggle và xoay mũi tên -->
<script>
    $(document).ready(function() {
        // Xử lý sự kiện khi mở submenu: xoay mũi tên và đóng các submenu khác
        $('.collapse').on('show.bs.collapse', function() {
            $('.collapse').not(this).collapse('hide');
            $(this).prev().find('.fa-angle-down').addClass('fa-rotate-180');
        });

        // Xử lý sự kiện khi đóng submenu: bỏ xoay mũi tên
        $('.collapse').on('hide.bs.collapse', function() {
            $(this).prev().find('.fa-angle-down').removeClass('fa-rotate-180');
        });

        // Đảm bảo rằng khi nhấn vào mục cha, submenu sẽ toggle
        $('.list-group-item[data-bs-toggle="collapse"]').on('click', function(e) {
            e.preventDefault();
            var target = $(this).attr('href');
            $(target).collapse('toggle');
        });
    });
</script>