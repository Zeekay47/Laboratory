<?php
$page_title = 'Patients';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('receptionist');

$db = new Database();
$message = '';

// Handle patient registration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $full_name = trim($_POST['full_name']);
    $cnic = trim($_POST['cnic']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $age = (int)$_POST['age'];
    $gender = $_POST['gender'];
    $address = trim($_POST['address']);
    
    // Validate CNIC format (optional)
    if (!empty($cnic) && !preg_match('/^\d{5}-\d{7}-\d{1}$/', $cnic)) {
        $message = '<div class="alert alert-danger">CNIC must be in format: 12345-1234567-1</div>';
    } else {
        // Generate patient code
        $db->query('SELECT MAX(id) as max_id FROM patients');
        $result = $db->single();
        $next_id = ($result['max_id'] ?? 0) + 1;
        $patient_code = 'LAB-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);
        
        // Check if phone or CNIC already exists
        $db->query('SELECT id FROM patients WHERE phone = :phone OR cnic = :cnic');
        $db->bind(':phone', $phone);
        $db->bind(':cnic', $cnic);
        $existing = $db->single();
        
        if ($existing) {
            $message = '<div class="alert alert-danger">Patient with this phone number or CNIC already exists!</div>';
        } else {
            $db->query('INSERT INTO patients (patient_code, cnic, full_name, phone, email, age, gender, address) 
                       VALUES (:code, :cnic, :name, :phone, :email, :age, :gender, :address)');
            $db->bind(':code', $patient_code);
            $db->bind(':cnic', $cnic);
            $db->bind(':name', $full_name);
            $db->bind(':phone', $phone);
            $db->bind(':email', $email);
            $db->bind(':age', $age);
            $db->bind(':gender', $gender);
            $db->bind(':address', $address);
            
            if ($db->execute()) {
                $message = '<div class="alert alert-success">Patient registered successfully! Patient Code: ' . $patient_code . '</div>';
            } else {
                $message = '<div class="alert alert-danger">Registration failed. Please try again.</div>';
            }
        }
    }
}

// Search patient
$search_results = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search'])) {
    $search_term = trim($_POST['search_term']);
    $db->query('SELECT * FROM patients 
               WHERE phone LIKE :term OR full_name LIKE :term OR patient_code LIKE :term OR cnic LIKE :term 
               ORDER BY full_name ASC');
    $db->bind(':term', '%' . $search_term . '%');
    $search_results = $db->resultSet();
}

// Get all registered patients in ascending order for the list
$db->query('SELECT * FROM patients ORDER BY full_name ASC');
$all_patients = $db->resultSet();
$total_patients = count($all_patients);
?>

<div class="row">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">
                <h5>Register New Patient</h5>
            </div>
            <div class="card-body">
                <?php echo $message; ?>
                <form method="POST" action="" id="patientForm">
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Full Name *</label>
                        <input type="text" class="form-control" id="full_name" name="full_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="cnic" class="form-label">CNIC (National ID)</label>
                        <input type="text" class="form-control" id="cnic" name="cnic" 
                               placeholder="12345-1234567-1" pattern="\d{5}-\d{7}-\d{1}">
                        <small class="text-muted">Format: 12345-1234567-1</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number *</label>
                                <input type="tel" class="form-control" id="phone" name="phone" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="age" class="form-label">Age</label>
                                <input type="number" class="form-control" id="age" name="age" min="0" max="150">
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="gender" class="form-label">Gender</label>
                                <select class="form-select" id="gender" name="gender">
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                    </div>
                    
                    <button type="submit" name="register" class="btn btn-primary w-100">
                        <i class="bi bi-person-plus"></i> Register Patient
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5>Search Patient</h5>
                    <span class="badge bg-primary">Total Patients: <?php echo $total_patients; ?></span>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="mb-4">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search_term" 
                               placeholder="Search by name, phone, CNIC, or patient code..." required>
                        <button type="submit" name="search" class="btn btn-primary">
                            <i class="bi bi-search"></i> Search
                        </button>
                    </div>
                </form>
                
                <?php if (!empty($search_results)): ?>
                <div class="table-responsive mb-4">
                    <h6 class="mb-3">Search Results (<?php echo count($search_results); ?> found):</h6>
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>CNIC</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($search_results as $patient): ?>
                            <tr>
                                <td><strong><?php echo $patient['patient_code']; ?></strong></td>
                                <td><?php echo $patient['full_name']; ?></td>
                                <td><?php echo $patient['phone']; ?></td>
                                <td><?php echo $patient['cnic'] ?: '<span class="text-muted">N/A</span>'; ?></td>
                                <td>
                                    <a href="new_order.php?patient_id=<?php echo $patient['id']; ?>" 
                                       class="btn btn-sm btn-success" title="Create Order">
                                        <i class="bi bi-plus-circle"></i>
                                    </a>
                                    <a href="patient_history.php?id=<?php echo $patient['id']; ?>" 
                                       class="btn btn-sm btn-info" title="View History">
                                        <i class="bi bi-clock-history"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php elseif ($_SERVER['REQUEST_METHOD'] == 'POST'): ?>
                    <div class="alert alert-info mb-4">No patients found matching your search.</div>
                <?php endif; ?>
                
                <!-- Patient List Section -->
                <div class="patient-list-section">
                    <h6 class="mb-3">Registered Patients (Alphabetical Order):</h6>
                    
                    <?php if (!empty($all_patients)): ?>
                        <!-- First 5 patients (always visible) -->
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>Age/Gender</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $counter = 0;
                                    foreach ($all_patients as $patient): 
                                        $counter++;
                                        if ($counter <= 5): ?>
                                        <tr>
                                            <td><strong><?php echo $patient['patient_code']; ?></strong></td>
                                            <td><?php echo $patient['full_name']; ?></td>
                                            <td><?php echo $patient['phone']; ?></td>
                                            <td><?php echo $patient['age']; ?>/<?php echo substr($patient['gender'], 0, 1); ?></td>
                                            <td>
                                                <a href="new_order.php?patient_id=<?php echo $patient['id']; ?>" 
                                                   class="btn btn-sm btn-success" title="Create Order">
                                                    <i class="bi bi-plus-circle"></i>
                                                </a>
                                                <a href="patient_history.php?id=<?php echo $patient['id']; ?>" 
                                                   class="btn btn-sm btn-info" title="View History">
                                                    <i class="bi bi-clock-history"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endif; 
                                    endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Scrollable section for remaining patients -->
                        <?php if ($total_patients > 5): ?>
                        <div class="scrollable-patient-list mt-3" style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 5px;">
                            <table class="table table-sm table-hover mb-0">
                                <tbody>
                                    <?php 
                                    $counter = 0;
                                    foreach ($all_patients as $patient): 
                                        $counter++;
                                        if ($counter > 5): ?>
                                        <tr>
                                            <td style="width: 15%"><strong><?php echo $patient['patient_code']; ?></strong></td>
                                            <td style="width: 30%"><?php echo $patient['full_name']; ?></td>
                                            <td style="width: 20%"><?php echo $patient['phone']; ?></td>
                                            <td style="width: 15%"><?php echo $patient['age']; ?>/<?php echo substr($patient['gender'], 0, 1); ?></td>
                                            <td style="width: 20%">
                                                <a href="new_order.php?patient_id=<?php echo $patient['id']; ?>" 
                                                   class="btn btn-sm btn-success" title="Create Order">
                                                    <i class="bi bi-plus-circle"></i>
                                                </a>
                                                <a href="patient_history.php?id=<?php echo $patient['id']; ?>" 
                                                   class="btn btn-sm btn-info" title="View History">
                                                    <i class="bi bi-clock-history"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endif; 
                                    endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-muted text-center mt-2 small">
                            Showing <?php echo min(5, $total_patients); ?> of <?php echo $total_patients; ?> patients
                            <?php if ($total_patients > 5): ?>
                                - Scroll to see more
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> No patients registered yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.scrollable-patient-list::-webkit-scrollbar {
    width: 8px;
}

.scrollable-patient-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.scrollable-patient-list::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}

.scrollable-patient-list::-webkit-scrollbar-thumb:hover {
    background: #555;
}

.scrollable-patient-list tr:hover {
    background-color: #f8f9fa;
}
</style>

<script>
$(document).ready(function() {
    // Format CNIC input
    $('#cnic').on('input', function() {
        var value = $(this).val().replace(/\D/g, '');
        if (value.length > 0) {
            if (value.length <= 5) {
                value = value;
            } else if (value.length <= 12) {
                value = value.substring(0, 5) + '-' + value.substring(5);
            } else {
                value = value.substring(0, 5) + '-' + value.substring(5, 12) + '-' + value.substring(12, 13);
            }
        }
        $(this).val(value);
    });
    
    // Validate CNIC format on form submission
    $('#patientForm').submit(function(e) {
        var cnic = $('#cnic').val();
        if (cnic && !/^\d{5}-\d{7}-\d{1}$/.test(cnic)) {
            e.preventDefault();
            alert('CNIC must be in format: 12345-1234567-1');
            return false;
        }
        return true;
    });
    
    // Auto-focus search input
    $('input[name="search_term"]').focus();
    
    // Highlight search term in patient list
    <?php if (isset($_POST['search_term']) && !empty($_POST['search_term'])): ?>
        var searchTerm = '<?php echo $_POST['search_term']; ?>';
        if (searchTerm) {
            $('.patient-list-section').hide();
        }
    <?php endif; ?>
});
</script>

<?php require_once 'includes/footer.php'; ?>