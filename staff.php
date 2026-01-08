<?php
$page_title = 'Staff Management';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('manager');

$db = new Database();
$message = '';

// Handle staff creation/update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_staff'])) {
        // Add new staff
        $username = trim($_POST['username']);
        $full_name = trim($_POST['full_name']);
        $role = $_POST['role'];
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validation
        if (empty($username) || empty($full_name) || empty($password)) {
            $message = '<div class="alert alert-danger">All fields are required!</div>';
        } elseif ($password !== $confirm_password) {
            $message = '<div class="alert alert-danger">Passwords do not match!</div>';
        } else {
            // Check if username exists
            $db->query('SELECT id FROM staff WHERE username = :username');
            $db->bind(':username', $username);
            $existing = $db->single();
            
            if ($existing) {
                $message = '<div class="alert alert-danger">Username already exists!</div>';
            } else {
                // Hash password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                $db->query('INSERT INTO staff (username, full_name, role, password_hash) 
                           VALUES (:username, :full_name, :role, :password_hash)');
                $db->bind(':username', $username);
                $db->bind(':full_name', $full_name);
                $db->bind(':role', $role);
                $db->bind(':password_hash', $password_hash);
                
                if ($db->execute()) {
                    $message = '<div class="alert alert-success">Staff member added successfully!</div>';
                } else {
                    $message = '<div class="alert alert-danger">Failed to add staff member.</div>';
                }
            }
        }
    }
    elseif (isset($_POST['update_staff'])) {
        // Update staff
        $staff_id = $_POST['staff_id'];
        $full_name = trim($_POST['full_name']);
        $role = $_POST['role'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Update password if provided
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (!empty($password)) {
            if ($password !== $confirm_password) {
                $message = '<div class="alert alert-danger">Passwords do not match!</div>';
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                $db->query('UPDATE staff SET full_name = :full_name, role = :role,
                           password_hash = :password_hash, is_active = :active
                           WHERE id = :id');
                $db->bind(':password_hash', $password_hash);
            }
        } else {
            $db->query('UPDATE staff SET full_name = :full_name, role = :role,
                       is_active = :active WHERE id = :id');
        }
        
        $db->bind(':full_name', $full_name);
        $db->bind(':role', $role);
        $db->bind(':active', $is_active);
        $db->bind(':id', $staff_id);
        
        if ($db->execute()) {
            $message = '<div class="alert alert-success">Staff member updated successfully!</div>';
        }
    }
}

// Handle delete action
if (isset($_GET['delete'])) {
    $staff_id = $_GET['delete'];
    
    // Cannot delete self
    if ($staff_id == $_SESSION['user_id']) {
        $message = '<div class="alert alert-danger">You cannot delete your own account!</div>';
    } else {
        $db->query('DELETE FROM staff WHERE id = :id');
        $db->bind(':id', $staff_id);
        if ($db->execute()) {
            $message = '<div class="alert alert-success">Staff member deleted successfully!</div>';
        }
    }
}

// Handle activate/deactivate
if (isset($_GET['toggle_active'])) {
    $staff_id = $_GET['toggle_active'];
    
    // Cannot deactivate self
    if ($staff_id == $_SESSION['user_id']) {
        $message = '<div class="alert alert-danger">You cannot deactivate your own account!</div>';
    } else {
        $db->query('UPDATE staff SET is_active = NOT is_active WHERE id = :id');
        $db->bind(':id', $staff_id);
        if ($db->execute()) {
            $message = '<div class="alert alert-success">Status updated successfully!</div>';
        }
    }
}

// Get all staff members
$db->query('SELECT * FROM staff ORDER BY role, full_name');
$staff_members = $db->resultSet();

// Group staff by role
$staff_by_role = [];
foreach ($staff_members as $staff) {
    $staff_by_role[$staff['role']][] = $staff;
}

// Get staff details for editing
$edit_staff = null;
if (isset($_GET['edit'])) {
    $staff_id = $_GET['edit'];
    $db->query('SELECT * FROM staff WHERE id = :id');
    $db->bind(':id', $staff_id);
    $edit_staff = $db->single();
}
?>

<div class="row">
    <div class="col-md-12">
        <?php echo $message; ?>
        
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5>Staff Management</h5>
            </div>
            <div class="card-body">
                <!-- Add/Edit Staff Form -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6><?php echo $edit_staff ? 'Edit Staff Member' : 'Add New Staff Member'; ?></h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <?php if ($edit_staff): ?>
                                <input type="hidden" name="staff_id" value="<?php echo $edit_staff['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Username *</label>
                                        <input type="text" class="form-control" id="username" 
                                               name="username" value="<?php echo $edit_staff['username'] ?? ''; ?>"
                                               <?php echo $edit_staff ? 'readonly' : 'required'; ?>>
                                        <small class="text-muted">Unique username for login</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="full_name" class="form-label">Full Name *</label>
                                        <input type="text" class="form-control" id="full_name" 
                                               name="full_name" value="<?php echo $edit_staff['full_name'] ?? ''; ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Role *</label>
                                        <select class="form-select" id="role" name="role" required>
                                            <option value="receptionist" <?php echo ($edit_staff['role'] ?? '') == 'receptionist' ? 'selected' : ''; ?>>Receptionist</option>
                                            <option value="technician" <?php echo ($edit_staff['role'] ?? '') == 'technician' ? 'selected' : ''; ?>>Lab Technician</option>
                                            <option value="manager" <?php echo ($edit_staff['role'] ?? '') == 'manager' ? 'selected' : ''; ?>>Manager</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="password" class="form-label">
                                            <?php echo $edit_staff ? 'New Password (leave blank to keep current)' : 'Password *'; ?>
                                        </label>
                                        <input type="password" class="form-control" id="password" 
                                               name="password" <?php echo !$edit_staff ? 'required' : ''; ?>>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm Password *</label>
                                        <input type="password" class="form-control" id="confirm_password" 
                                               name="confirm_password" <?php echo !$edit_staff ? 'required' : ''; ?>>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($edit_staff): ?>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_active" 
                                               name="is_active" value="1"
                                               <?php echo ($edit_staff['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">
                                            Account is Active
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-grid gap-2 d-md-flex mt-3">
                                <?php if ($edit_staff): ?>
                                    <button type="submit" name="update_staff" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Update Staff
                                    </button>
                                    <a href="staff.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle"></i> Cancel
                                    </a>
                                <?php else: ?>
                                    <button type="submit" name="add_staff" class="btn btn-success">
                                        <i class="bi bi-plus-circle"></i> Add Staff
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Staff List -->
                <div class="card">
                    <div class="card-header">
                        <h6>Staff Members (<?php echo count($staff_members); ?>)</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($staff_by_role as $role => $role_staff): ?>
                        <h6 class="mt-3 border-bottom pb-2">
                            <?php echo ucfirst($role) . 's'; ?> (<?php echo count($role_staff); ?>)
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Full Name</th>
                                        <th>Role</th>
                                        <th>Last Login</th>
                                        <th>Created</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($role_staff as $staff): ?>
                                    <tr class="<?php echo !$staff['is_active'] ? 'table-secondary' : ''; ?>">
                                        <td><strong><?php echo $staff['username']; ?></strong></td>
                                        <td><?php echo $staff['full_name']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                switch($staff['role']) {
                                                    case 'receptionist': echo 'primary'; break;
                                                    case 'technician': echo 'info'; break;
                                                    case 'manager': echo 'success'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo ucfirst($staff['role']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($staff['last_login']): ?>
                                                <?php echo date('d M Y, h:i A', strtotime($staff['last_login'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('d M Y', strtotime($staff['created_at'])); ?></td>
                                        <td>
                                            <?php if ($staff['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="staff.php?edit=<?php echo $staff['id']; ?>" 
                                                   class="btn btn-outline-primary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if ($staff['id'] != $_SESSION['user_id']): ?>
                                                    <a href="staff.php?toggle_active=<?php echo $staff['id']; ?>" 
                                                       class="btn btn-outline-<?php echo $staff['is_active'] ? 'warning' : 'success'; ?>"
                                                       onclick="return confirm('<?php echo $staff['is_active'] ? 'Deactivate' : 'Activate'; ?> this account?')">
                                                        <i class="bi bi-power"></i>
                                                    </a>
                                                    <a href="staff.php?delete=<?php echo $staff['id']; ?>" 
                                                       class="btn btn-outline-danger"
                                                       onclick="return confirm('Delete this staff member permanently?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>