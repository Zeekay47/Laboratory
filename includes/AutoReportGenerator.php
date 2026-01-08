<?php
class AutoReportGenerator {
    private $db;
    
    public function __construct($db = null) {
        if (!$db) {
            require_once 'Database.php';
            $this->db = new Database();
        } else {
            $this->db = $db;
        }
    }
    
    public function generateReport($order_id, $user_id) {
        $this->db->query('SELECT * FROM reports WHERE order_id = :order_id ORDER BY generated_at DESC LIMIT 1');
        $this->db->bind(':order_id', $order_id);
        $existing_report = $this->db->single();
        
        if ($existing_report) {
            return [
                'success' => true,
                'report_path' => $existing_report['report_path'],
                'report_number' => $existing_report['report_number'],
                'message' => 'Report already exists'
            ];
        }
        
        // Get order details
        $this->db->query('SELECT o.*, p.* FROM orders o 
                         JOIN patients p ON o.patient_id = p.id 
                         WHERE o.id = :id');
        $this->db->bind(':id', $order_id);
        $order = $this->db->single();
        
        if (!$order) {
            return [
                'success' => false,
                'message' => 'Order not found'
            ];
        }
        
        // Get order tests
        $this->db->query('SELECT ot.*, t.test_name, t.test_code, t.category
                         FROM order_tests ot 
                         JOIN tests t ON ot.test_id = t.id 
                         WHERE ot.order_id = :order_id 
                         ORDER BY t.category, t.test_name');
        $this->db->bind(':order_id', $order_id);
        $tests = $this->db->resultSet();
        
        if (empty($tests)) {
            return [
                'success' => false,
                'message' => 'No tests found for this order'
            ];
        }
        
        // Generate report number
        $report_number = $this->generateReportNumber($order_id);
        
        // Create PDF using mPDF
        $pdf_path = $this->createPDF($order, $tests, $report_number);
        
        if (!$pdf_path) {
            return [
                'success' => false,
                'message' => 'Failed to create PDF'
            ];
        }
        
        // Save to database
        $filename = basename($pdf_path);
        $this->db->query('INSERT INTO reports (order_id, report_number, report_path, generated_by) 
                         VALUES (:order_id, :report_number, :report_path, :generated_by)');
        $this->db->bind(':order_id', $order_id);
        $this->db->bind(':report_number', $report_number);
        $this->db->bind(':report_path', $filename);
        $this->db->bind(':generated_by', $user_id);
        
        if ($this->db->execute()) {
            // Update order with report path
            $this->db->query('UPDATE orders SET report_path = :path WHERE id = :id');
            $this->db->bind(':path', $filename);
            $this->db->bind(':id', $order_id);
            $this->db->execute();
            
            return [
                'success' => true,
                'report_path' => $filename,
                'report_number' => $report_number,
                'message' => 'Report generated successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to save report to database'
        ];
    }
    
    /**
     * Generate unique report number
     */
    private function generateReportNumber($order_id) {
        $date_part = date('Ymd');
        $base_number = 'RPT-' . $date_part . '-' . str_pad($order_id, 4, '0', STR_PAD_LEFT);
        
        // Check for duplicates
        $this->db->query('SELECT COUNT(*) as count FROM reports WHERE report_number LIKE :search');
        $this->db->bind(':search', $base_number . '%');
        $result = $this->db->single();
        
        if ($result['count'] > 0) {
            return $base_number . '-' . ($result['count'] + 1);
        }
        
        return $base_number;
    }
    
    /**
     * Create PDF using mPDF
     */
    private function createPDF($order, $tests, $report_number) {
        // Check for mPDF
        $mpdf_available = false;
        $mpdf = null;
        
        // Try to load mPDF
        $autoload_path = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload_path)) {
            require_once $autoload_path;
            $mpdf_available = true;
        } else {
            // Try direct path
            $mpdf_path = __DIR__ . '/vendor/mpdf/mpdf/src/Mpdf.php';
            if (file_exists($mpdf_path)) {
                
                $this->loadMPDFDependencies();
                require_once $mpdf_path;
                $mpdf_available = true;
            }
        }
        
        if (!$mpdf_available) {
            return $this->createTextReport($order, $tests, $report_number);
        }
        
        try {
            // Create mPDF instance
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_top' => 10,
                'margin_bottom' => 10,
                'margin_header' => 5,
                'margin_footer' => 5,
                'default_font' => 'dejavusans',
                'default_font_size' => 9
            ]);
            
            $mpdf->SetDisplayMode('fullpage');
            
            // Generate HTML content
            $html = $this->generateReportHTML($order, $tests, $report_number);
            
            // Write to PDF
            $mpdf->WriteHTML($html);
            
            // Save to file
            $reports_dir = 'reports/';
            if (!is_dir($reports_dir)) {
                mkdir($reports_dir, 0755, true);
            }
            
            $filename = 'report_' . $order['order_number'] . '_' . date('Ymd_His') . '.pdf';
            $filepath = $reports_dir . $filename;
            
            $mpdf->Output($filepath, 'F');
            
            return $filepath;
            
        } catch (Exception $e) {
            error_log("PDF Generation Error: " . $e->getMessage());
            // Fallback to text report
            return $this->createTextReport($order, $tests, $report_number);
        }
    }
    
    /**
     * Generate HTML for PDF
     */
    private function generateReportHTML($order, $tests, $report_number) {
        $html = '<!DOCTYPE html>
        <html>
        <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <style>
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9pt; margin: 0; padding: 0; }
            .header { text-align: center; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #0066cc; }
            .header h2 { color: #0066cc; margin: 0 0 5px 0; font-size: 14pt; font-weight: bold; }
            .header p { color: #666; margin: 0 0 8px 0; font-size: 8pt; }
            .header h3 { color: #333; margin: 0; font-size: 11pt; font-weight: bold; }
            .patient-info, .report-info { font-size: 8.5pt; }
            .info-table { width: 100%; margin-bottom: 10px; border-collapse: collapse; }
            .info-table td { padding: 5px; vertical-align: top; }
            .section-label { color: #0066cc; font-weight: bold; font-size: 9pt; margin-bottom: 5px; display: block; }
            .test-section { margin-bottom: 15px; }
            .test-title { color: #004080; margin: 10px 0 5px 0; font-size: 10pt; font-weight: bold; }
            .results-table { width: 100%; border-collapse: collapse; margin-top: 5px; font-size: 8.5pt; }
            .results-table th { background-color: #0066cc; color: white; padding: 6px; text-align: left; font-weight: bold; }
            .results-table td { padding: 5px; border: 1px solid #ddd; }
            .footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 8pt; color: #666; text-align: center; }
        </style>
        </head>
        <body>';
        
        // Header
        $html .= '
        <div class="header">
            <h2>DTC DIAGNOSTIC CENTER</h2>
            <p>Laboratory Management System</p>
            <h3>LABORATORY TEST REPORT</h3>
        </div>';
        
        // Patient and Order Info
        $html .= '
        <table class="info-table">
            <tr>
                <td width="50%" style="border-right: 1px solid #ddd;">
                    <span class="section-label">PATIENT INFORMATION</span><br>
                    <strong>Name:</strong> ' . htmlspecialchars($order['full_name']) . '<br>
                    <strong>Age/Gender:</strong> ' . htmlspecialchars($order['age']) . ' / ' . htmlspecialchars($order['gender'][0]) . '<br>
                    <strong>Patient ID:</strong> ' . htmlspecialchars($order['patient_code']) . '<br>
                    <strong>Phone:</strong> ' . htmlspecialchars($order['phone']) . '
                </td>
                <td width="50%" style="padding-left: 10px;">
                    <span class="section-label">REPORT INFORMATION</span><br>
                    <strong>Order #:</strong> ' . htmlspecialchars($order['order_number']) . '<br>
                    <strong>Report #:</strong> ' . $report_number . '<br>
                    <strong>Date:</strong> ' . date('d/m/Y H:i', strtotime($order['order_date'])) . '<br>
                    <strong>Status:</strong> ' . ucfirst($order['status']) . '
                </td>
            </tr>
        </table>';
        
        // Tests
        $html .= '<div class="test-section">';
        
        foreach ($tests as $test) {
            $html .= '<div class="test-title">' . htmlspecialchars($test['test_name']) . 
                    ' (' . htmlspecialchars($test['test_code']) . ')</div>';
            
            // Get test results
            $this->db->query('SELECT tp.parameter_name, tp.parameter_code, tr.result_value, tr.result_unit, 
                             tr.flag, tr.reference_range
                      FROM test_parameters tp
                      LEFT JOIN test_results tr ON tp.id = tr.parameter_id 
                      AND tr.order_test_id = :order_test_id
                      WHERE tp.test_id = :test_id AND tp.is_active = 1
                      ORDER BY tp.sort_order');
            $this->db->bind(':order_test_id', $test['id']);
            $this->db->bind(':test_id', $test['test_id']);
            $parameters = $this->db->resultSet();
            
            if (!empty($parameters)) {
                $html .= '
                <table class="results-table">
                    <thead>
                        <tr>
                            <th width="40%">Parameter</th>
                            <th width="20%">Result</th>
                            <th width="15%">Unit</th>
                            <th width="25%">Reference Range</th>
                        </tr>
                    </thead>
                    <tbody>';
                
                foreach ($parameters as $param) {
                    $html .= '
                    <tr>
                        <td>' . htmlspecialchars($param['parameter_name']) . '</td>
                        <td>' . htmlspecialchars($param['result_value'] ?? '') . '</td>
                        <td>' . htmlspecialchars($param['result_unit'] ?? '') . '</td>
                        <td>' . htmlspecialchars($param['reference_range'] ?? '') . '</td>
                    </tr>';
                }
                
                $html .= '</tbody></table>';
            } else {
                $html .= '<p><em>No parameters available</em></p>';
            }
        }
        
        $html .= '</div>';
        
        // Footer
        $html .= '
        <div class="footer">
            Report Generated: ' . date('d/m/Y H:i:s') . '<br>
            DTC Diagnostic Center - Laboratory Management System
        </div>
        </body>
        </html>';
        
        return $html;
    }
    
    /**
     * Create text report as fallback
     */
    private function createTextReport($order, $tests, $report_number) {
        $reports_dir = __DIR__ . '/reports/';
        if (!is_dir($reports_dir)) {
            mkdir($reports_dir, 0755, true);
        }
        
        $filename = 'report_' . $order['order_number'] . '_' . date('Ymd_His') . '.txt';
        $filepath = $reports_dir . $filename;
        
        $content = "================================\n";
        $content .= "DTC DIAGNOSTIC CENTER\n";
        $content .= "LABORATORY TEST REPORT\n";
        $content .= "================================\n\n";
        $content .= "Report #: " . $report_number . "\n";
        $content .= "Order #: " . $order['order_number'] . "\n";
        $content .= "Date: " . date('d/m/Y H:i:s') . "\n\n";
        $content .= "PATIENT INFORMATION\n";
        $content .= "-------------------\n";
        $content .= "Name: " . $order['full_name'] . "\n";
        $content .= "Age/Gender: " . $order['age'] . " / " . $order['gender'] . "\n";
        $content .= "Patient ID: " . $order['patient_code'] . "\n";
        $content .= "Phone: " . $order['phone'] . "\n\n";
        $content .= "TESTS PERFORMED\n";
        $content .= "---------------\n";
        
        foreach ($tests as $test) {
            $content .= "\n" . $test['test_name'] . " (" . $test['test_code'] . ")\n";
            $content .= "Sample ID: " . ($test['sample_id'] ?? 'N/A') . "\n";
            
            // Get results
            $this->db->query('SELECT tp.parameter_name, tr.result_value, tr.result_unit, tr.reference_range
                      FROM test_parameters tp
                      LEFT JOIN test_results tr ON tp.id = tr.parameter_id 
                      AND tr.order_test_id = :order_test_id
                      WHERE tp.test_id = :test_id AND tp.is_active = 1
                      ORDER BY tp.sort_order');
            $this->db->bind(':order_test_id', $test['id']);
            $this->db->bind(':test_id', $test['test_id']);
            $parameters = $this->db->resultSet();
            
            foreach ($parameters as $param) {
                $content .= "  - " . $param['parameter_name'] . ": " . 
                          ($param['result_value'] ?? '') . " " . 
                          ($param['result_unit'] ?? '') . 
                          " [" . ($param['reference_range'] ?? '') . "]\n";
            }
        }
        
        $content .= "\n================================\n";
        $content .= "End of Report\n";
        $content .= "Generated automatically by DTC Laboratory System\n";
        
        file_put_contents($filepath, $content);
        
        return $filepath;
    }
    
    /**
     * Load mPDF dependencies manually
     */
    private function loadMPDFDependencies() {
        // This is a simplified version - adjust paths as needed
        $vendor_dir = __DIR__ . '/vendor/';
        
        if (file_exists($vendor_dir . 'psr/log/Psr/Log/LoggerInterface.php')) {
            require_once $vendor_dir . 'psr/log/Psr/Log/LoggerInterface.php';
            require_once $vendor_dir . 'psr/log/Psr/Log/LoggerAwareInterface.php';
            require_once $vendor_dir . 'psr/log/Psr/Log/LoggerAwareTrait.php';
            require_once $vendor_dir . 'psr/log/Psr/Log/AbstractLogger.php';
            require_once $vendor_dir . 'psr/log/Psr/Log/NullLogger.php';
        }
        
        if (file_exists($vendor_dir . 'myclabs/deep-copy/src/DeepCopy/DeepCopy.php')) {
            require_once $vendor_dir . 'myclabs/deep-copy/src/DeepCopy/DeepCopy.php';
        }
        
        if (file_exists($vendor_dir . 'setasign/fpdi/src/autoload.php')) {
            require_once $vendor_dir . 'setasign/fpdi/src/autoload.php';
        }
    }
    
    /**
     * Download existing report
     */
    public function downloadReport($order_id) {
        $this->db->query('SELECT r.* FROM reports r WHERE order_id = :order_id ORDER BY generated_at DESC LIMIT 1');
        $this->db->bind(':order_id', $order_id);
        $report = $this->db->single();
        
        if (!$report) {
            return [
                'success' => false,
                'message' => 'Report not found'
            ];
        }
        
        $filepath = __DIR__ . '/reports/' . $report['report_path'];
        
        if (!file_exists($filepath)) {
            return [
                'success' => false,
                'message' => 'Report file not found'
            ];
        }
        
        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => $report['report_path'],
            'report' => $report
        ];
    }
    
    /**
     * Check if report needs to be generated
     * Called when order status changes
     */
    public function checkAndGenerate($order_id, $user_id) {
        // Check order status
        $this->db->query('SELECT status FROM orders WHERE id = :id');
        $this->db->bind(':id', $order_id);
        $order = $this->db->single();
        
        if (!$order || $order['status'] != 'completed') {
            return [
                'success' => false,
                'message' => 'Order is not completed'
            ];
        }
        
        // Check if report already exists
        $this->db->query('SELECT id FROM reports WHERE order_id = :order_id');
        $this->db->bind(':order_id', $order_id);
        $existing = $this->db->single();
        
        if ($existing) {
            return [
                'success' => true,
                'message' => 'Report already exists',
                'exists' => true
            ];
        }
        
        // Generate report
        return $this->generateReport($order_id, $user_id);
    }
}

/**
 * Global function to trigger report generation
 * Call this when order status changes to 'completed'
 */
function autoGenerateReportOnComplete($order_id, $user_id) {
    $generator = new AutoReportGenerator();
    return $generator->checkAndGenerate($order_id, $user_id);
}

/**
 * Function to download report
 */
function downloadReportFile($order_id) {
    $generator = new AutoReportGenerator();
    return $generator->downloadReport($order_id);
}
?>