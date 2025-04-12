<?php
require_once __DIR__ . "/../core/Database.php";

class SalaryCalculator {
    private $basic_salary;
    private $allowance;
    private $personal_deduction;
    private $details = [];
    private $settings = [];

    public function __construct($basic_salary, $allowance) {
        $this->basic_salary = $basic_salary;
        $this->allowance = $allowance;

        // Lấy thông số từ bảng settings
        $db = Database::getInstance()->getConnection();
        $settingsStmt = $db->query("SELECT name, value FROM settings");
        $this->settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Gán giá trị từ settings, nếu không có thì dùng giá trị mặc định
        $this->personal_deduction = $this->settings['personal_deduction'] ?? 11000000;

        $this->calculate();
    }

    private function calculate() {
        // Lấy tỷ lệ bảo hiểm từ settings, nếu không có thì dùng giá trị mặc định
        $bhxh_rate = ($this->settings['bhxh_rate'] ?? 8) / 100;
        $bhyt_rate = ($this->settings['bhyt_rate'] ?? 1.5) / 100;
        $bhtn_rate = ($this->settings['bhtn_rate'] ?? 1) / 100;

        // Tính các khoản bảo hiểm
        $bhxh = $this->basic_salary * $bhxh_rate;
        $bhyt = $this->basic_salary * $bhyt_rate;
        $bhtn = $this->basic_salary * $bhtn_rate;
        $total_insurance = $bhxh + $bhyt + $bhtn;

        // Tính thuế TNCN
        $taxable_income = $this->basic_salary - $total_insurance - $this->personal_deduction;
        $income_tax = 0;

        if ($taxable_income > 0) {
            $remaining_income = $taxable_income;
            $previous_limit = 0;
            $tax_rates = [
                5000000 => $this->settings['tax_rate_1'] ?? 5,
                10000000 => $this->settings['tax_rate_2'] ?? 10,
                18000000 => $this->settings['tax_rate_3'] ?? 15,
                32000000 => $this->settings['tax_rate_4'] ?? 20,
                52000000 => $this->settings['tax_rate_5'] ?? 25,
                80000000 => $this->settings['tax_rate_6'] ?? 30,
                PHP_FLOAT_MAX => $this->settings['tax_rate_7'] ?? 35
            ];

            foreach ($tax_rates as $limit => $rate) {
                if ($remaining_income <= 0) break;

                $taxable_in_bracket = min($remaining_income, $limit - $previous_limit);
                $income_tax += $taxable_in_bracket * ($rate / 100);
                $remaining_income -= $taxable_in_bracket;
                $previous_limit = $limit;
            }
        }

        // Tính lương thực nhận
        $net_salary = $this->basic_salary + $this->allowance - $total_insurance - $income_tax;

        // Lưu chi tiết
        $this->details = [
            'basic_salary' => $this->basic_salary,
            'allowance' => $this->allowance,
            'bhxh' => $bhxh,
            'bhyt' => $bhyt,
            'bhtn' => $bhtn,
            'total_insurance' => $total_insurance,
            'personal_deduction' => $this->personal_deduction,
            'taxable_income' => max($taxable_income, 0),
            'income_tax' => $income_tax,
            'net_salary' => $net_salary,
            'deductions' => 0,
            'bonuses' => 0,
            'overtime_pay' => 0,
            'unexplained_absence_penalty' => 0
        ];
    }

    public function getSalaryDetails() {
        return $this->details;
    }

    public static function formatCurrency($amount) {
        return number_format($amount, 0, ',', '.') . ' VNĐ';
    }
}