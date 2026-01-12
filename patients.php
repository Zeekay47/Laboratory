<?php
$page_title = 'Patients';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('receptionist');

$db = new Database();
$message = '';
$existing_patient_info = null; // To store existing patient info if CNIC exists

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
        $db->query('SELECT MAX(CAST(SUBSTRING(patient_code, 5) AS UNSIGNED)) as max_patient_num FROM patients WHERE patient_code LIKE "LAB-%"');
        $result = $db->single();
        $next_patient_num = ($result['max_patient_num'] ?? 0) + 1;
        $patient_code = 'LAB-' . str_pad($next_patient_num, 4, '0', STR_PAD_LEFT);
        
        // Check if CNIC already exists (separate check for better messaging)
        $cnic_exists = false;
        if (!empty($cnic)) {
            $db->query('SELECT * FROM patients WHERE cnic = :cnic');
            $db->bind(':cnic', $cnic);
            $existing_patient_info = $db->single();
            $cnic_exists = !empty($existing_patient_info);
        }
        
        // Check if phone already exists
        $phone_exists = false;
        if (!empty($phone)) {
            $db->query('SELECT * FROM patients WHERE phone = :phone');
            $db->bind(':phone', $phone);
            $existing_phone_patient = $db->single();
            $phone_exists = !empty($existing_phone_patient);
        }
        
        if ($cnic_exists) {
            $message = '<div class="alert alert-danger">
                        <h6><i class="bi bi-exclamation-triangle"></i> CNIC Already Registered!</h6>
                        <p>This CNIC is already registered with the following patient:</p>
                        <div class="mt-2 p-2 bg-light rounded">
                            <strong>Patient Code:</strong> ' . $existing_patient_info['patient_code'] . '<br>
                            <strong>Name:</strong> ' . $existing_patient_info['full_name'] . '<br>
                            <strong>Phone:</strong> ' . $existing_patient_info['phone'] . '<br>
                            <strong>Registered:</strong> ' . date('d M Y', strtotime($existing_patient_info['registered_date'])) . '
                        </div>
                        <p class="mt-2 mb-0">Please search for this patient instead of registering again.</p>
                        </div>';
        } elseif ($phone_exists) {
            $message = '<div class="alert alert-danger">
                        <h6><i class="bi bi-exclamation-triangle"></i> Phone Number Already Registered!</h6>
                        <p>This phone number is already registered with the following patient:</p>
                        <div class="mt-2 p-2 bg-light rounded">
                            <strong>Patient Code:</strong> ' . $existing_phone_patient['patient_code'] . '<br>
                            <strong>Name:</strong> ' . $existing_phone_patient['full_name'] . '<br>
                            <strong>CNIC:</strong> ' . ($existing_phone_patient['cnic'] ?: 'N/A') . '<br>
                            <strong>Registered:</strong> ' . date('d M Y', strtotime($existing_phone_patient['registered_date'])) . '
                        </div>
                        <p class="mt-2 mb-0">Please search for this patient instead of registering again.</p>
                        </div>';
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
                $message = '<div class="alert alert-success">
                            <i class="bi bi-check-circle"></i> Patient registered successfully!
                            <div class="mt-2 p-2 bg-light rounded">
                                <strong>Patient Code:</strong> ' . $patient_code . '<br>
                                <strong>Name:</strong> ' . $full_name . '<br>
                                <strong>Phone:</strong> ' . $phone . '<br>
                                <strong>CNIC:</strong> ' . ($cnic ?: 'Not provided') . '
                            </div>
                            </div>';
                // Clear form after successful registration
                echo '<script>
                    setTimeout(function() {
                        document.getElementById("patientForm").reset();
                    }, 100);
                </script>';
            } else {
                $message = '<div class="alert alert-danger">Registration failed. Please try again.</div>';
            }
        }
    }
}

// Get all registered patients in ascending order for the list
$db->query('SELECT * FROM patients ORDER BY full_name ASC');
$all_patients = $db->resultSet();
$total_patients = count($all_patients);

// Initialize variables for search
$search_results = [];
$search_performed = false;
$search_term = '';

// Check if this is a fresh page load (not a form submission)
$is_fresh_load = $_SERVER['REQUEST_METHOD'] !== 'POST';

// Only process search if this is a POST request (form submission)
if (!$is_fresh_load && isset($_POST['search'])) {
    $search_term = trim($_POST['search_term']);
    $search_performed = true;
    $db->query('SELECT * FROM patients 
               WHERE phone LIKE :term OR full_name LIKE :term OR patient_code LIKE :term OR cnic LIKE :term 
               ORDER BY full_name ASC');
    $db->bind(':term', '%' . $search_term . '%');
    $search_results = $db->resultSet();
}
?>

<div class="row row-equal-height">
    <div class="col-md-5 d-flex">
        <div class="card flex-fill">
            <div class="card-header">
                <h5>Register New Patient</h5>
            </div>
            <div class="card-body d-flex flex-column">
                <?php echo $message; ?>
                <form method="POST" action="" id="patientForm" class="flex-fill d-flex flex-column">
                    <div class="flex-fill">
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
                    </div>
                    
                    <button type="submit" name="register" class="btn btn-primary w-100 mt-auto">
                        <i class="bi bi-person-plus"></i> Register Patient
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-7 d-flex">
        <div class="card flex-fill">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5>Patient Management</h5>
                    <span class="badge bg-primary">Total Patients: <?php echo $total_patients; ?></span>
                </div>
            </div>
            <div class="card-body d-flex flex-column">
                <!-- Search Section -->
                <div class="mb-4">
                    <form method="POST" action="" class="mb-0" id="searchForm">
                        <div class="input-group">
                            <input type="text" class="form-control" name="search_term" 
                                   placeholder="Search by name, phone, CNIC, or patient code..." 
                                   value="<?php echo htmlspecialchars($search_term); ?>"
                                   required>
                            <button type="submit" name="search" class="btn btn-primary">
                                <i class="bi bi-search"></i> Search
                            </button>
                            <?php if ($search_performed && !empty($search_term)): ?>
                            <button type="button" class="btn btn-outline-secondary" id="clearSearchBtn">
                                <i class="bi bi-x-circle"></i> Clear
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <?php if ($search_performed && !empty($search_results)): ?>
                <div class="search-results mb-4 flex-fill d-flex flex-column">
                    <h6 class="mb-3">Search Results (<?php echo count($search_results); ?> found):</h6>
                    <div class="table-responsive flex-fill" style="max-height: 300px; overflow-y: auto;">
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
                    <div class="mt-3 pt-2 border-top">
                        <small class="text-muted">
                            <a href="javascript:void(0)" class="text-primary" id="showAllPatients">
                                <i class="bi bi-arrow-left"></i> Back to all patients
                            </a>
                        </small>
                    </div>
                </div>
                <?php elseif ($search_performed && empty($search_results)): ?>
                    <div class="alert alert-info mb-4">
                        No patients found matching your search.
                        <div class="mt-2">
                            <a href="javascript:void(0)" class="text-primary" id="showAllPatients">
                                <i class="bi bi-arrow-left"></i> Back to all patients
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Patient List Section - Default View -->
                <div class="patient-list-section flex-fill d-flex flex-column" <?php echo ($search_performed && !empty($search_results)) ? 'style="display: none;"' : ''; ?>>
                    <h6 class="mb-3">Registered Patients (Alphabetical Order):</h6>
                    
                    <?php if (!empty($all_patients)): ?>
                        <div class="table-responsive flex-fill" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 15%">Code</th>
                                        <th style="width: 30%">Name</th>
                                        <th style="width: 20%">Phone</th>
                                        <th style="width: 15%">Age/Gender</th>
                                        <th style="width: 20%">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    foreach ($all_patients as $patient): 
                                        // Add highlight class if this patient was just registered
                                        $highlight_class = '';
                                        if (isset($message) && strpos($message, $patient['patient_code']) !== false && strpos($message, 'success') !== false) {
                                            $highlight_class = 'table-success';
                                        }
                                    ?>
                                    <tr class="<?php echo $highlight_class; ?>">
                                        <td><strong><?php echo $patient['patient_code']; ?></strong></td>
                                        <td><?php echo $patient['full_name']; ?></td>
                                        <td><?php echo $patient['phone']; ?></td>
                                        <td>
                                            <?php if ($patient['age']): ?>
                                                <?php echo $patient['age']; ?>/<?php echo substr($patient['gender'], 0, 1); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
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
                        
                        <div class="mt-3 pt-2 border-top">
                            <div class="row">
                                <div class="col">
                                    <small class="text-muted">
                                        Showing <?php echo $total_patients; ?> patient<?php echo $total_patients != 1 ? 's' : ''; ?>
                                    </small>
                                </div>
                                <div class="col-auto">
                                    <small class="text-muted">
                                        <i class="bi bi-arrow-down-up"></i> Sorted by name
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                    <?php else: ?>
                        <div class="alert alert-warning flex-fill d-flex align-items-center justify-content-center">
                            <div class="text-center">
                                <i class="bi bi-people display-4 text-muted mb-3"></i>
                                <h5>No patients registered yet</h5>
                                <p class="text-muted">Register your first patient using the form on the left.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Equal height rows */
.row-equal-height {
    display: flex;
    flex-wrap: wrap;
}

.row-equal-height > [class*='col-'] {
    display: flex;
    flex-direction: column;
}

.card.flex-fill {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
}

.card-body.d-flex.flex-column {
    min-height: 0;
}

/* Scrollable table containers */
.table-responsive {
    overflow-y: auto;
    border: 1px solid #dee2e6;
    border-radius: 5px;
}

.table-responsive table thead {
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 10;
    box-shadow: 0 2px 2px -1px rgba(0,0,0,0.1);
}

/* Custom scrollbar */
.table-responsive::-webkit-scrollbar {
    width: 8px;
}

.table-responsive::-webkit-scrollbar-track {
    background: #f8f9fa;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: #adb5bd;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover {
    background: #6c757d;
}

/* Table row hover effect */
.table-hover tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05);
}

/* Form field spacing */
.form-control, .form-select {
    padding: 0.5rem 0.75rem;
}

/* Alert styling */
.alert {
    border-radius: 0.5rem;
}

.alert .bg-light {
    background-color: rgba(255, 255, 255, 0.5) !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .row-equal-height {
        flex-direction: column;
    }
    
    .row-equal-height > [class*='col-'] {
        margin-bottom: 20px;
    }
    
    .table-responsive {
        max-height: 250px !important;
    }
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
    
    // Clear search button functionality
    $('#clearSearchBtn, #showAllPatients').click(function(e) {
        e.preventDefault();
        clearSearch();
    });
    
    // Function to clear search
    function clearSearch() {
        // Clear search input
        $('input[name="search_term"]').val('');
        
        // Hide search results
        $('.search-results').hide();
        $('.alert-info').hide();
        
        // Show patient list
        $('.patient-list-section').show();
        
        // Focus on search input
        $('input[name="search_term"]').focus();
    }
    
    // Highlight and scroll to newly added patient
    <?php if (isset($message) && strpos($message, 'successfully') !== false): ?>
        setTimeout(function() {
            // Show patient list and hide search results if any
            $('.search-results').hide();
            $('.alert-info').hide();
            $('.patient-list-section').show();
            
            // Scroll to highlighted patient
            var highlightedRow = $('.table-success').first();
            if (highlightedRow.length) {
                var container = highlightedRow.closest('.table-responsive');
                if (container.length) {
                    var rowPosition = highlightedRow.position().top;
                    var containerScroll = container.scrollTop();
                    container.animate({
                        scrollTop: containerScroll + rowPosition - 100
                    }, 500);
                }
            }
        }, 500);
    <?php endif; ?>
    
    // Auto-populate search with CNIC if duplicate found
    <?php if (!empty($existing_patient_info)): ?>
        $('input[name="search_term"]').val('<?php echo $existing_patient_info["cnic"]; ?>');
    <?php endif; ?>
    
    // Detect page refresh/back button
    window.addEventListener('pageshow', function(event) {
        // If page is loaded from cache (back/forward navigation), clear search
        if (event.persisted) {
            clearSearch();
        }
    });
    
    // Prevent form resubmission on refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>