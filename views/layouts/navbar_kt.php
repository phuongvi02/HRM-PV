<nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top">
    <div class="container-fluid">
        <button class="btn btn-link text-light" id="menu-toggle">
            <i class="fas fa-bars"></i>
        </button>
        <a class="navbar-brand ms-3" href="#">HRM System</a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarContent">
            <form class="d-flex ms-auto me-3">
                <div class="input-group">
                    <input class="form-control" type="search" placeholder="Tìm kiếm...">
                    <button class="btn btn-light" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>

            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="notificationsDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-bell"></i>
                        <span class="badge bg-danger">3</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <h6 class="dropdown-header">Thông báo</h6>
                        <a class="dropdown-item" href="#">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-user-plus text-success"></i>
                                </div>
                                <div class="ms-2">
                                    <p class="mb-0">Nhân viên mới được thêm</p>
                                    <small class="text-muted">3 phút trước</small>
                                </div>
                            </div>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center" href="#">Xem tất cả</a>
                    </div>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <img src="https://via.placeholder.com/32" class="rounded-circle" alt="User">
                        <span class="ms-2">Admin</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-user me-2"></i>Hồ sơ
                        </a>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-cog me-2"></i>Cài đặt
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="/HRMpv/views/auth/logout.php/">
                            <i class="fas fa-sign-out-alt me-2"></i>Đăng xuất
                        </a>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>

<style>
    
    /* Main Layout */
body {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    padding-top: 56px;
}

#wrapper {
    display: flex;
    min-height: calc(100vh - 56px);
}

/* Sidebar */
.sidebar {
    width: 250px;
    min-height: calc(100vh - 56px);
    position: fixed;
    top: 56px;
    bottom: 0;
    left: 0;
    z-index: 100;
    transition: all 0.3s;
    overflow-y: auto;
}

.sidebar-heading {
    padding: 0.875rem 1.25rem;
    font-size: 1.2rem;
}

.list-group-item {
    border: none;
    padding: 0.75rem 1.25rem;
}

.list-group-item-action:hover {
    background-color: rgba(255, 255, 255, 0.1) !important;
}

.list-group-item.active {
    background-color: #0d6efd !important;
    border-color: #0d6efd !important;
}

/* Content */
#content-wrapper {
    width: 100%;
    margin-left: 250px;
    transition: all 0.3s;
    padding: 20px;
}

/* Toggled State */
#wrapper.toggled .sidebar {
    margin-left: -250px;
}

#wrapper.toggled #content-wrapper {
    margin-left: 0;
}

/* Navbar */
.navbar {
    box-shadow: 0 2px 4px rgba(0,0,0,.1);
}

.navbar-brand {
    font-weight: 600;
}

/* User Dropdown */
.nav-item .dropdown-menu {
    min-width: 200px;
}

.nav-item .dropdown-item i {
    width: 20px;
}

/* Notifications */
.badge {
    position: absolute;
    top: 0;
    right: 0;
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

/* Footer */
.footer {
    background-color: #f8f9fa;
    padding: 1rem 0;
    margin-top: auto;
}

/* Responsive */
@media (max-width: 768px) {
    .sidebar {
        margin-left: -250px;
    }

    #content-wrapper {
        margin-left: 0;
    }

    #wrapper.toggled .sidebar {
        margin-left: 0;
    }

    #wrapper.toggled #content-wrapper {
        margin-left: 250px;
    }
}

/* Cards */
.card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1.5rem;
}

.card-header {
    background-color: #fff;
    border-bottom: 1px solid rgba(0,0,0,.125);
}

/* Tables */
.table thead th {
    border-top: none;
    background-color: #f8f9fa;
}

/* Forms */
.form-control:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
}

/* Buttons */
.btn {
    font-weight: 500;
}

.btn-primary {
    background-color: #0d6efd;
    border-color: #0d6efd;
}

.btn-primary:hover {
    background-color: #0b5ed7;
    border-color: #0a58ca;
}

/* Custom Scrollbar */
::-webkit-scrollbar {
    width: 6px;
}

::-webkit-scrollbar-track {
    background: #f1f1f1;
}

::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 3px;
}

::-webkit-scrollbar-thumb:hover {
    background: #555;
}
</style>