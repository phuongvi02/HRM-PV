<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HRM System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/HRMPV/public/css/admin.css">
</head>
<body class="bg-light">
    
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