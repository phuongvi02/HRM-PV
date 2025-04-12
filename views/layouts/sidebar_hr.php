<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR</title>
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }
        .navbar {
            width: 100%;
            background: #2c3e50;
            color: white;
            padding: 0 20px;
            height: 60px;
            position: fixed;
            top: 0; /* Luôn hiển thị, không ẩn */
            left: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            z-index: 1000;
        }
        .navbar ul {
            list-style: none;
            display: flex;
            gap: 20px;
        }
        .navbar ul li {
            position: relative;
            display: flex;
            align-items: center;
        }
        .navbar ul li a {
            text-decoration: none;
            color: white;
            font-size: 16px;
            padding: 10px 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        .navbar ul li a:hover {
            background: #34495e;
            border-radius: 5px;
            transform: translateY(-2px);
        }
        .navbar ul li a::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            background: #1abc9c;
            bottom: 5px;
            left: 50%;
            transition: all 0.3s ease;
        }
        .navbar ul li a:hover::after {
            width: 70%;
            left: 15%;
        }
        .search-container {
            display: flex;
            align-items: center;
            position: relative;
        }
        .search-container input[type="text"] {
            padding: 8px 40px 8px 15px;
            border: none;
            border-radius: 20px;
            font-size: 14px;
            width: 200px;
            background: #34495e;
            color: white;
            outline: none;
            transition: width 0.3s ease, background 0.3s ease;
        }
        .search-container input[type="text"]:focus {
            width: 250px;
            background: #3e5c76;
        }
        .search-container input[type="text"]::placeholder {
            color: #bdc3c7;
        }
        .search-container button {
            position: absolute;
            right: 5px;
            background: none;
            border: none;
            color: white;
            font-size: 16px;
            cursor: pointer;
            padding: 5px;
            transition: color 0.3s ease;
        }
        .search-container button:hover {
            color: #1abc9c;
        }
        .content {
            flex: 1;
            padding: 20px;
            margin-top: 70px;
            transition: margin-top 0.4s ease;
        }
        .employee-list ul {
            list-style: none;
        }
        .employee-list li {
            padding: 15px;
            background: #ecf0f1;
            margin-bottom: 10px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .employee-list li img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }
        .employee-list li .info {
            flex: 1;
        }
        .employee-list li .info span {
            font-weight: bold;
            display: block;
        }
        .employee-list li .info small {
            color: #7f8c8d;
        }

        @media (max-width: 768px) {
            .navbar {
                height: auto;
                padding: 10px 20px;
                flex-direction: column;
                align-items: flex-start;
            }
            .navbar ul {
                flex-direction: column;
                gap: 10px;
                width: 100%;
            }
            .search-container {
                margin-top: 10px;
                width: 100%;
            }
            .search-container input[type="text"] {
                width: 100%;
            }
            .search-container input[type="text"]:focus {
                width: 100%;
            }
            .content {
                margin-top: 0;
            }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="navbar" id="navbar">
    <ul>
        <li><a href="/HRMpv/views/HR/list_employee.php"><span>Quản lý nhân viên</span></a></li>
        <li><a href="/HRMpv/views/HR/attendance.php"><span>Quản lý chấm công</span></a></li>
        <li><a href="/HRMpv/views/HR/contract.php"><span>Hợp đồng</span></a></li>
        <li><a href="/HRMpv/views/HR/approve-requests.php"><span>Phê duyệt nghỉ và làm thêm</span></a></li>
        <li><a href="/HRMpv/views/HR/hr_job_applications.php"><span>Ứng tuyển  </span></a></li>
        <li><a href="/HRMpv/views/HR/hr_attendance_explanations.php"><span>Danh sách giải trình </span></a></li>
        <li><a href="/HRMpv/views/HR/leave-history.php"><span>Lịch sử phê duyệt</span></a></li>
        <li><a href="/HRMpv/views/auth/logout.php"><span>Đăng xuất</span></a></li>
    </ul>
 
</div>

<!-- Nội dung -->
<div class="content">
    <div class="employee-list" id="employeeList">
        <!-- Danh sách nhân viên sẽ được thêm bởi JavaScript -->
    </div>
</div>

<script>
    // Hàm định dạng tiền tệ
    function formatCurrency(amount) {
        if (amount === null || amount === undefined) return 'Chưa cập nhật';
        return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(amount);
    }

    // Hàm định dạng ngày tháng
    function formatDate(date) {
        return new Date(date).toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    // Hàm hiển thị danh sách nhân viên
    function displayEmployees(employees) {
        const employeeList = document.getElementById("employeeList");
        employeeList.innerHTML = "";
        if (!employees || employees.length === 0) {
            employeeList.innerHTML = "<p>Không tìm thấy nhân viên nào.</p>";
            return;
        }
        const ul = document.createElement("ul");
        employees.forEach(employee => {
            const li = document.createElement("li");
            li.innerHTML = `
                <img src="${employee.avatarUrls.thumbnail}" alt="${employee.full_name}">
                <div class="info">
                    <span>${employee.full_name}</span>
                    <small>Email: ${employee.email}</small>
                    <small>Phone: ${employee.phone || 'Chưa cập nhật'}</small>
                    <small>Phòng ban: ${employee.department || 'Chưa xác định'}</small>
                    <small>Vị trí: ${employee.position || 'Chưa xác định'}</small>
                    <small>Lương: ${formatCurrency(employee.salary)}</small>
                    <small>Ngày tạo: ${formatDate(employee.created_at)}</small>
                </div>
            `;
            ul.appendChild(li);
        });
        employeeList.appendChild(ul);
    }

    // Xử lý tìm kiếm
    document.getElementById("searchInput").addEventListener("input", async function() {
        const query = this.value.trim();
        if (query.length < 2) {
            displayEmployees([]);
            return;
        }
        try {
            const response = await fetch(`/api/search_employees.php?q=${encodeURIComponent(query)}`);
            const employees = await response.json();
            displayEmployees(employees);
        } catch (error) {
            console.error("Lỗi khi tìm kiếm:", error);
            displayEmployees([]);
        }
    });

    // Load danh sách nhân viên mặc định khi mở trang
    (async function loadDefaultEmployees() {
        try {
            const response = await fetch(`/api/search_employees.php`);
            const employees = await response.json();
            displayEmployees(employees);
        } catch (error) {
            console.error("Lỗi khi tải danh sách mặc định:", error);
        }
    })();
</script>

</body>
</html>