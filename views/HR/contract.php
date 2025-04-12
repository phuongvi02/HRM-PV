    <?php
    require_once __DIR__ . "/../../core/Database.php";
    require_once __DIR__ . "/../../core/salary-calculate.php"; // Đảm bảo file SalaryCalculator được include
    require_once __DIR__ . '/../../views/layouts/sidebar_hr.php';

    // Lấy kết nối từ singleton Database
    $db = Database::getInstance()->getConnection();

    // Lấy thông số từ bảng settings
    $settingsStmt = $db->query("SELECT name, value FROM settings");
    $settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Gán giá trị mặc định nếu không có trong bảng settings
    $bhxh_rate = $settings['bhxh_rate'] ?? 8; // 8%
    $bhyt_rate = $settings['bhyt_rate'] ?? 1.5; // 1.5%
    $bhtn_rate = $settings['bhtn_rate'] ?? 1; // 1%
    $personal_deduction = $settings['personal_deduction'] ?? 11000000; // 11 triệu
    $tax_rates = [
        5000000 => $settings['tax_rate_1'] ?? 5, // 5%
        10000000 => $settings['tax_rate_2'] ?? 10, // 10%
        18000000 => $settings['tax_rate_3'] ?? 15, // 15%
        32000000 => $settings['tax_rate_4'] ?? 20, // 20%
        52000000 => $settings['tax_rate_5'] ?? 25, // 25%
        80000000 => $settings['tax_rate_6'] ?? 30, // 30%
        PHP_FLOAT_MAX => $settings['tax_rate_7'] ?? 35 // 35%
    ];

    // Truy vấn danh sách tất cả nhân viên, lấy lương từ cả employees và positions
    $stmt = $db->query("SELECT e.*, p.name as position_name, d.name as department_name, p.salary as position_salary
                        FROM employees e
                        LEFT JOIN positions p ON e.position_id = p.id
                        LEFT JOIN departments d ON e.department_id = d.id
                        ORDER BY e.created_at DESC");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tính toán lương chi tiết cho từng nhân viên
    $employeeSalaries = [];
    foreach ($employees as $employee) {
        // Lấy hợp đồng mới nhất của nhân viên (nếu có)
        $stmt = $db->prepare("SELECT * FROM contracts WHERE employee_id = :employee_id AND status = 'active' ORDER BY start_date DESC LIMIT 1");
        $stmt->execute([':employee_id' => $employee['id']]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        // Xác định lương cơ bản: ưu tiên từ hợp đồng, nếu không có thì từ employees, cuối cùng là positions
        $basicSalary = $contract['basic_salary'] ?? ($employee['salary'] ?? 0);
        $allowance = $contract['allowance'] ?? 0;
        $salarySource = $contract ? 'contracts' : ($employee['salary'] ? 'employees' : 'positions');

        if ($basicSalary == 0) {
            $basicSalary = $employee['position_salary'] ?? 0;
        }

        // Tính toán lương chi tiết
        $bhxh = $basicSalary * ($bhxh_rate / 100);
        $bhyt = $basicSalary * ($bhyt_rate / 100);
        $bhtn = $basicSalary * ($bhtn_rate / 100);
        $total_insurance = $bhxh + $bhyt + $bhtn;

        $tax = 0;
        $taxable_income = $basicSalary - $total_insurance - $personal_deduction;
        if ($taxable_income > 0) {
            $remaining_income = $taxable_income;
            $previous_limit = 0;
            foreach ($tax_rates as $limit => $rate) {
                if ($remaining_income <= 0) break;
                $taxable_in_bracket = min($remaining_income, $limit - $previous_limit);
                $tax += $taxable_in_bracket * ($rate / 100);
                $remaining_income -= $taxable_in_bracket;
                $previous_limit = $limit;
            }
        }

        $net_salary = $basicSalary + $allowance - $total_insurance - $tax;

        $employeeSalaries[$employee['id']] = [
            'contract' => $contract,
            'basic_salary' => $basicSalary,
            'allowance' => $allowance,
            'bhxh' => $bhxh,
            'bhyt' => $bhyt,
            'bhtn' => $bhtn,
            'total_insurance' => $total_insurance,
            'income_tax' => $tax,
            'net_salary' => $net_salary,
            'source' => $salarySource
        ];
    }
    ?>


    <!-- Bootstrap CSS (nếu chưa có trong header_hr.php) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>/* Reset và cơ bản */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        background: #f4f7fa;
        display: flex;
        flex-direction: column;
        overflow-x: hidden;
        padding-top: 0 !important; /* Ghi đè để đảm bảo không có padding-top */
    }

    /* Container */
    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }

    /* Flexbox cho tiêu đề và nút */
    .d-flex {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .align-items-center {
        align-items: center;
    }

    .mb-4 {
        margin-bottom: 1.5rem;
    }

    h2 {
        font-size: 28px;
        font-weight: 600;
        color: #1e3c72;
        letter-spacing: 1px;
    }

    /* Table */
    .table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        background: #ffffff;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }

    .table th,
    .table td {
        padding: 15px;
        text-align: left;
        border-bottom: 1px solid #e5e7eb;
    }

    .table th {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        color: #ffffff;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 14px;
        letter-spacing: 0.5px;
    }

    .table tbody tr {
        transition: background 0.3s ease;
    }

    .table.table-hover tbody tr:hover {
        background: #f9fafb;
    }

    .table td {
        font-size: 15px;
        color: #374151;
        vertical-align: middle;
    }

    .table td small.text-muted {
        font-size: 13px;
        color: #6b7280;
    }

    /* Trạng thái */
    .text-success {
        color: #28a745 !important;
        font-weight: 500;
    }

    .text-danger {
        color: #dc3545 !important;
        font-weight: 500;
    }

    .text-warning {
        color: #ffc107 !important;
        font-weight: 500;
    }

    .text-secondary {
        color: #6c757d !important;
        font-weight: 500;
    }

    .text-center {
        text-align: center !important;
    }

    /* Button */
    .btn {
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 500;
        text-align: center;
        transition: all 0.3s ease;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-success {
        background: #28a745;
        color: #ffffff;
    }

    .btn-success:hover {
        background: #218838;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }

    .btn-info {
        background: #17a2b8;
        color: #ffffff;
    }

    .btn-info:hover {
        background: #138496;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);
    }

    .btn-warning {
        background: #ffc107;
        color: #212529;
    }

    .btn-warning:hover {
        background: #e0a800;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);
    }

    .btn-primary {
        background: #007bff;
        color: #ffffff;
    }

    .btn-primary:hover {
        background: #0056b3;
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
    }

    .btn-sm {
        padding: 6px 12px;
        font-size: 14px;
    }

    .btn i {
        font-size: 16px;
    }

    /* Button Group */
    .btn-group {
        display: flex;
        gap: 8px;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .container {
            padding: 15px;
        }

        .table th,
        .table td {
            padding: 12px;
            font-size: 14px;
        }

        .btn {
            padding: 8px 16px;
            font-size: 14px;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 13px;
        }
    }

    @media (max-width: 768px) {
        .d-flex {
            flex-direction: column;
            gap: 15px;
            align-items: flex-start;
        }

        h2 {
            font-size: 24px;
        }

        .table {
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }

        .table th,
        .table td {
            padding: 10px;
            font-size: 13px;
        }

        .table td small.text-muted {
            font-size: 12px;
        }

        .btn-group {
            flex-wrap: wrap;
        }

        .btn {
            padding: 8px 14px;
            font-size: 13px;
        }

        .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }
    }

    @media (max-width: 480px) {
        h2 {
            font-size: 20px;
        }

        .table th,
        .table td {
            padding: 8px;
            font-size: 12px;
        }

        .table td small.text-muted {
            font-size: 11px;
        }

        .btn {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-sm {
            padding: 4px 6px;
            font-size: 11px;
        }

        .btn i {
            font-size: 14px;
        }
    }
    .content {
    flex: initial !important; /* Ghi đè flex: 1 */
    padding: 0 !important;    /* Ghi đè padding: 20px */
    margin-top: 0 !important; /* Ghi đè margin-top: 70px */
    transition: none !important; /* Ghi đè transition */
}
    /* override.css */
    body {
        min-height: auto !important; /* Ghi đè min-height */
        display: block !important;   /* Ghi đè display: flex */
        flex-direction: initial !important; /* Ghi đè flex-direction */
        padding-top: 0 !important;   /* Ghi đè padding-top */
    }
    .form-control {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid #ced4da;
    font-size: 14px;
    transition: border-color 0.3s ease;
}

.form-control:focus {
    border-color: #1e3c72;
    box-shadow: 0 0 5px rgba(30, 60, 114, 0.3);
    outline: none;
}

.gap-3 {
    gap: 15px; /* Khoảng cách giữa ô tìm kiếm và các nút */
}</style>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Quản Lý Hợp Đồng</h2>
            <input type="text" id="searchInput" class="form-control" placeholder="Tìm kiếm nhân viên..." style="max-width: 300px;">
            <a href="/HRMpv/views/HR/create_contract.php/" class="btn btn-success">Tạo Hợp Đồng Mới</a>
            <button class="btn btn-primary" onclick="printTable()">In Danh Sách</button>
        </div>

        <table class="table table-bordered table-hover">
            <thead class="thead-dark">
                <tr>
                    <th>Mã NV</th>
                    <th>Nhân Viên</th>
                    <th>Phòng Ban</th>
                    <th>Chức Vụ</th>
                    <th>Loại Hợp Đồng</th>
                    <th>Ngày Bắt Đầu</th>
                    <th>Ngày Kết Thúc</th>
                    <th>Lương Chi Tiết</th>
                    <th>Trạng Thái</th>
                    <th>Thao Tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($employees)): ?>
                <tr>
                    <td colspan="10" class="text-center">Chưa có dữ liệu nhân viên</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($employees as $employee): ?>
                    <?php 
                        $salaryInfo = $employeeSalaries[$employee['id']];
                        $contract = $salaryInfo['contract'];
                        $basicSalary = $salaryInfo['basic_salary'];
                        $allowance = $salaryInfo['allowance'];
                        $bhxh = $salaryInfo['bhxh'];
                        $bhyt = $salaryInfo['bhyt'];
                        $bhtn = $salaryInfo['bhtn'];
                        $totalInsurance = $salaryInfo['total_insurance'];
                        $incomeTax = $salaryInfo['income_tax'];
                        $netSalary = $salaryInfo['net_salary'];
                        $salarySource = $salaryInfo['source'];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($employee['id']) ?></td>
                        <td>
                            <div><?= htmlspecialchars($employee['full_name']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($employee['email']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($employee['department_name'] ?? 'Chưa cập nhật') ?></td>
                        <td><?= htmlspecialchars($employee['position_name'] ?? 'Chưa cập nhật') ?></td>
                        <td><?= htmlspecialchars($employee['contract_type'] ?? 'Chưa có hợp đồng') ?></td>
                        <td><?= $employee['hire_date'] ? date('d/m/Y', strtotime($employee['hire_date'])) : 'Chưa cập nhật' ?></td>
                        <td>
                            <?= $employee['contract_end_date'] ? date('d/m/Y', strtotime($employee['contract_end_date'])) : 'Không xác định' ?>
                        </td>
                        <td class="salary-details">
                            <?php if ($basicSalary > 0): ?>
                                <div>Lương cơ bản: <span class="amount"><?= number_format($basicSalary, 0, ',', '.') ?> VNĐ</span></div>
                                <div class="salary-source">
                                    (Nguồn: <?= $salarySource === 'contracts' ? 'Hợp đồng' : ($salarySource === 'employees' ? 'Nhân viên' : 'Chức vụ') ?>)
                                </div>
                                <div>Tổng bảo hiểm: <span class="amount text-danger">- <?= number_format($totalInsurance, 0, ',', '.') ?> VNĐ</span></div>
                                <div>Thuế TNCN: <span class="amount text-danger">- <?= number_format($incomeTax, 0, ',', '.') ?> VNĐ</span></div>
                                <div>Lương thực nhận: <span class="amount text-success"><?= number_format($netSalary, 0, ',', '.') ?> VNĐ</span></div>
                            <?php else: ?>
                                Chưa cập nhật lương
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $status = '';
                            $statusClass = '';
                            $today = new DateTime();
                            $hireDate = $employee['hire_date'] ? new DateTime($employee['hire_date']) : null;
                            $endDate = $employee['contract_end_date'] ? new DateTime($employee['contract_end_date']) : null;

                            if (!$employee['contract_type']) {
                                $status = 'Chưa có hợp đồng';
                                $statusClass = 'text-secondary';
                            } elseif (!$hireDate) {
                                $status = 'Chưa có hợp đồng';
                                $statusClass = 'text-secondary';
                            } elseif ($hireDate > $today) {
                                $status = 'Chưa hiệu lực';
                                $statusClass = 'text-warning';
                            } elseif (!$endDate) {
                                $status = 'Đang hiệu lực';
                                $statusClass = 'text-success';
                            } elseif ($endDate >= $today) {
                                $status = 'Đang hiệu lực';
                                $statusClass = 'text-success';
                            } else {
                                $status = 'Hết hiệu lực';
                                $statusClass = 'text-danger';
                            }
                            ?>
                            <span class="<?= $statusClass ?>"><?= $status ?></span>
                        </td>
                        <td>
                        <div class="btn-group">
        <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" 
                data-bs-target="#contractModal<?= $employee['id'] ?>" title="Xem chi tiết">
            <i class="fas fa-eye"></i> Xem
        </button>
        <a href="edit_contract.php?id=<?= $employee['id'] ?>" 
        class="btn btn-warning btn-sm" title="Chỉnh sửa">
            <i class="fas fa-edit"></i> Sửa
        </a>
        <a href="print_contract.php?id=<?= $employee['id'] ?>" 
        class="btn btn-primary btn-sm" title="In hợp đồng">
            <i class="fas fa-print"></i> In
        </a>
    </div>
                        </td>
                    </tr>

                    <!-- Modal hiển thị chi tiết hợp đồng -->
                    <div class="modal fade" id="contractModal<?= $employee['id'] ?>" tabindex="-1" 
                        aria-labelledby="contractModalLabel<?= $employee['id'] ?>" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="contractModalLabel<?= $employee['id'] ?>">
                                        Chi Tiết Hợp Đồng - <?= htmlspecialchars($employee['full_name']) ?>
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" 
                                            aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <?php if ($contract): ?>
                                        <div class="section-title">Thông Tin Hợp Đồng</div>
                                        <div class="detail-row">
                                            <span class="label">Mã Hợp Đồng</span>
                                            <span class="value"><?= htmlspecialchars($contract['contract_code']) ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Loại Hợp Đồng</span>
                                            <span class="value"><?= htmlspecialchars($contract['contract_type']) ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Ngày Bắt Đầu</span>
                                            <span class="value"><?= date('Y-m-d', strtotime($contract['start_date'])) ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Ngày Kết Thúc</span>
                                            <span class="value"><?= $contract['end_date'] ? date('Y-m-d', strtotime($contract['end_date'])) : 'Không xác định' ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Thời Gian Làm Việc</span>
                                            <span class="value"><?= htmlspecialchars($contract['work_time'] ?? 'Chưa cập nhật') ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Mô Tả Công Việc</span>
                                            <span class="value"><?= htmlspecialchars($contract['job_description'] ?? 'Chưa cập nhật') ?></span>
                                        </div>

                                        <div class="section-title">Các Khoản Thu Nhập</div>
                                        <div class="detail-row">
                                            <span class="label">Lương Cơ Bản</span>
                                            <span class="value"><?= number_format($basicSalary, 0, ',', '.') ?> VNĐ</span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Phụ Cấp</span>
                                            <span class="value"><?= number_format($allowance, 0, ',', '.') ?> VNĐ</span>
                                        </div>

                                        <div class="section-title">Các Khoản Trừ</div>
                                        <div class="detail-row">
                                            <span class="label">BHXH (<?= $bhxh_rate ?>%)</span>
                                            <span class="value"><?= number_format($bhxh, 0, ',', '.') ?> VNĐ</span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">BHYT (<?= $bhyt_rate ?>%)</span>
                                            <span class="value"><?= number_format($bhyt, 0, ',', '.') ?> VNĐ</span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">BHTN (<?= $bhtn_rate ?>%)</span>
                                            <span class="value"><?= number_format($bhtn, 0, ',', '.') ?> VNĐ</span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Tổng Bảo Hiểm</span>
                                            <span class="value"><?= number_format($totalInsurance, 0, ',', '.') ?> VNĐ</span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Thuế TNCN</span>
                                            <span class="value"><?= number_format($incomeTax, 0, ',', '.') ?> VNĐ</span>
                                        </div>

                                        <div class="section-title">Lương Thực Nhận</div>
                                        <div class="detail-row">
                                            <span class="label">Tổng Thu Nhập</span>
                                            <span class="value"><?= number_format($basicSalary + $allowance, 0, ',', '.') ?> VNĐ</span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Tổng Trừ</span>
                                            <span class="value"><?= number_format($totalInsurance + $incomeTax, 0, ',', '.') ?> VNĐ</span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="label">Lương Thực Nhận</span>
                                            <span class="value"><?= number_format($netSalary, 0, ',', '.') ?> VNĐ</span>
                                        </div>
                                    <?php else: ?>
                                        <p>Nhân viên này chưa có hợp đồng hoạt động.</p>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Bootstrap JS (nếu chưa có trong footer.php) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Thêm tooltip cho các nút
        const tooltips = document.querySelectorAll('[title]');
        tooltips.forEach(tooltip => {
            new bootstrap.Tooltip(tooltip);
        });
    });
    </script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Thêm tooltip cho các nút
    const tooltips = document.querySelectorAll('[title]');
    tooltips.forEach(tooltip => {
        new bootstrap.Tooltip(tooltip);
    });

    // Tìm kiếm tự động
    const searchInput = document.getElementById('searchInput');
    const table = document.querySelector('.table');
    const rows = table.querySelectorAll('tbody tr');

    searchInput.addEventListener('input', function() {
        const searchText = this.value.toLowerCase().trim();

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            if (text.includes(searchText)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });

        // Nếu không có kết quả, hiển thị thông báo
        const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none');
        if (visibleRows.length === 0 && rows.length > 0) {
            const noResultRow = table.querySelector('.no-result-row');
            if (!noResultRow) {
                const tbody = table.querySelector('tbody');
                tbody.innerHTML = '<tr class="no-result-row"><td colspan="10" class="text-center">Không tìm thấy kết quả</td></tr>';
            }
        } else if (visibleRows.length > 0) {
            const noResultRow = table.querySelector('.no-result-row');
            if (noResultRow) noResultRow.remove();
        }
    });

    // Hàm in bảng
    window.printTable = function() {
        const printContents = document.querySelector('.table').outerHTML;
        const originalContents = document.body.innerHTML;
        const printStyle = `
            <style>
                @media print {
                    body * { visibility: hidden; }
                    .table, .table * { visibility: visible; }
                    .table { position: absolute; left: 0; top: 0; width: 100%; }
                    .btn-group { display: none; } /* Ẩn nút thao tác khi in */
                }
            </style>
        `;

        document.body.innerHTML = printStyle + printContents;
        window.print();
        document.body.innerHTML = originalContents;
        window.location.reload(); // Tải lại trang để khôi phục sự kiện
    };
});
</script>
    <?php include __DIR__ . '/../layouts/footer.php'; ?>