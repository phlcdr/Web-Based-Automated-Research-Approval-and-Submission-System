<?php
session_start();

include_once '../config/database.php';
include_once '../includes/functions.php';

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect based on role
    if ($_SESSION['role'] === 'student') {
        header("Location: ../student/dashboard.php");
    } elseif ($_SESSION['role'] === 'panel' || $_SESSION['role'] === 'adviser') {
        header("Location: ../panel/dashboard.php");
    } elseif ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
    }
    exit();
}

$error = '';
$success = '';

// Check if database connection exists
if (!isset($conn)) {
    $error = "Database connection failed. Please try again later.";
}

// Function to get setting value
function get_setting($conn, $key, $default = '') {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return $result !== false ? $result : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

// Function to validate password strength
function validate_password($password, $conn) {
    $min_length = intval(get_setting($conn, 'min_password_length', 8));
    $require_special = get_setting($conn, 'require_special_chars', '1') == '1';
    
    $errors = [];
    
    if (strlen($password) < $min_length) {
        $errors[] = "Password must be at least {$min_length} characters long";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if ($require_special && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return $errors;
}

// Updated colleges and departments mapping
$college_departments = [
    'College of Engineering' => [
        'Computer Engineering',
        'Civil Engineering', 
        'Electrical Engineering'
    ],
    'College of Nursing and Allied Sciences' => [
        'Nursing',
        'Midwifery',
        'Nutrition and Dietetics'
    ],
    'College of Computer Studies' => [
        'Information Technology',
        'Computer Science',
        'Entertainment and Multimedia Computing',
        'Associate in Computer Technology'
    ],
    'College of Education' => [
        'Secondary Education',
        'Elementary Education'
    ],
    'College of Business and Management' => [
        'Business Administration',
        'Hospitality Management',
        'Tourism Management',
        'Accountancy',
        'Entrepreneurship'
    ],
    'College of Arts and Sciences' => [
        'Biology',
        'Political Science'
    ],
    'College of Agriculture and Fisheries' => [
        'Agriculture',
        'Fisheries'
    ]
];

// Get colleges for dropdown
$colleges = array_keys($college_departments);

// Handle registration form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($conn)) {
    $username = validate_input($_POST['username']);
    $email = validate_input($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $first_name = validate_input($_POST['first_name']);
    $last_name = validate_input($_POST['last_name']);
    $college = validate_input($_POST['college']);
    $department = validate_input($_POST['department']);
    $student_id = validate_input($_POST['student_id'] ?? '');
    
    // Basic validation - Student ID is now required
    if (empty($username) || empty($email) || empty($password) || empty($first_name) || empty($last_name) || empty($college) || empty($department) || empty($student_id)) {
        $error = "All required fields must be filled out";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (!isset($college_departments[$college]) || !in_array($department, $college_departments[$college])) {
        $error = "Selected department does not belong to the selected college";
    } else {
        // Validate password strength
        $password_errors = validate_password($password, $conn);
        if (!empty($password_errors)) {
            $error = implode(". ", $password_errors);
        } else {
            // Check if username already exists
            try {
                $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->rowCount() > 0) {
                    $error = "Username already exists";
                } else {
                    // Check if email already exists
                    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->rowCount() > 0) {
                        $error = "Email already exists";
                    } else {
                        // Check if someone with the same first name and last name already exists in users table
                        $stmt = $conn->prepare("SELECT username, email FROM users WHERE LOWER(TRIM(first_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))");
                        $stmt->execute([$first_name, $last_name]);
                        if ($stmt->rowCount() > 0) {
                            $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);
                            $error = "A user with the name '" . htmlspecialchars($first_name . " " . $last_name) . "' already exists in the system. If this is you, please try logging in with your existing account or contact an administrator for assistance.";
                        } else {
                            // Also check in group_memberships for non-registered users
                            $full_name = trim($first_name . " " . $last_name);
                            $stmt = $conn->prepare("SELECT member_name FROM group_memberships WHERE LOWER(TRIM(member_name)) = LOWER(TRIM(?)) AND is_registered_user = 0");
                            $stmt->execute([$full_name]);
                            if ($stmt->rowCount() > 0) {
                                $error = "A person with the name '" . htmlspecialchars($first_name . " " . $last_name) . "' already exists as a group member. If this is you, please contact an administrator for assistance.";
                            } else {
                                // Check if student ID already exists (now required)
                                $stmt = $conn->prepare("SELECT * FROM users WHERE student_id = ?");
                                $stmt->execute([$student_id]);
                                if ($stmt->rowCount() > 0) {
                                    $error = "Student ID already exists";
                                } else {
                                    // All validations passed, proceed with registration
                                    try {
                                        // Hash password
                                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                        
                                        // Check if admin approval is required
                                        $require_approval = get_setting($conn, 'require_admin_approval', '1') == '1';
                                        
                                        // Insert new user (student role by default, pending approval if required for self-registration)
                                        $sql = "INSERT INTO users (username, password, email, first_name, last_name, role, college, department, student_id, is_active, registration_status) 
                                                VALUES (?, ?, ?, ?, ?, 'student', ?, ?, ?, 1, ?)";
                                        
                                        // Self-registration requires approval, admin-created users are auto-approved
                                        $registration_status = $require_approval ? 'pending' : 'approved';
                                        $stmt = $conn->prepare($sql);

                                        if ($stmt->execute([$username, $hashed_password, $email, $first_name, $last_name, $college, $department, $student_id, $registration_status])) {
                                            if ($require_approval) {
                                                $success = "Registration submitted successfully! Your account is pending admin approval. Please wait for an administrator to review and approve your registration before you can log in.";
                                            } else {
                                                $success = "Registration successful! You can now log in with your credentials.";
                                            }
                                            // Clear form data on success
                                            $_POST = array();
                                        } else {
                                            $error = "An error occurred during registration. Please try again.";
                                        }
                                    } catch (Exception $e) {
                                        $error = "Registration failed. Please try again.";
                                        error_log("Registration error: " . $e->getMessage());
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                $error = "Database error. Please try again later.";
                error_log("Database error: " . $e->getMessage());
            }
        }
    }
}

// Get password requirements for display (only if database connection exists)
$min_length = isset($conn) ? get_setting($conn, 'min_password_length', 8) : 8;
$require_special = isset($conn) ? (get_setting($conn, 'require_special_chars', '1') == '1') : true;
$require_approval = isset($conn) ? (get_setting($conn, 'require_admin_approval', '1') == '1') : true;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Register - Research Approval System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .password-requirements {
            font-size: 0.875rem;
            color: #6c757d;
            background-color: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            border-left: 3px solid #007bff;
            margin-top: 8px;
        }
        .password-requirements ul {
            margin: 8px 0 0 0;
            padding-left: 1.2rem;
        }
        .password-requirements li {
            margin-bottom: 2px;
        }
        .password-strength {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .strength-weak { color: #dc3545; }
        .strength-medium { color: #fd7e14; }
        .strength-strong { color: #198754; }
        .approval-notice {
            background-color: #e7f3ff;
            border-left: 4px solid #0066cc;
            padding: 12px 16px;
            margin-bottom: 24px;
            border-radius: 0.25rem;
        }
        .password-toggle {
            position: relative;
        }
        .toggle-password {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            z-index: 10;
        }
        .toggle-password:hover {
            color: #495057;
        }
        .password-toggle input {
            padding-right: 45px;
        }
        
        /* Custom styling to match login page */
        body {
            background-color: #f8f9fa;
        }
        
        .registration-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo-section img {
            width: 80px;
            height: 80px;
            margin-bottom: 1rem;
        }
        
        .logo-section h3 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .logo-section .text-muted {
            color: #6c757d !important;
            font-size: 0.95rem;
        }
        
        .form-control:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        
        .form-select:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
            font-weight: 500;
            padding: 0.75rem;
        }
        
        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #0056b3;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .alert-success {
            background-color: #d1edff;
            color: #0c5460;
        }
        
        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 0.5rem;
        }
        
        .form-text {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .row.g-3 > * {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .registration-card {
                margin: 1rem;
            }
            
            .logo-section h3 {
                font-size: 1.3rem;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="row justify-content-center my-5">
            <div class="col-lg-8 col-md-10">
                <div class="card registration-card">
                    <div class="card-body p-5">
                        <div class="logo-section">
                            <img src="../assets/images/essu logo.png" alt="ESSU Logo">
                            <h3>Student Registration</h3>
                            <p class="text-muted">Eastern Samar State University</p>
                        </div>

                        <?php if ($require_approval): ?>
                            <div class="approval-notice">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Registration Notice:</strong> New user registrations require administrator approval. 
                                After submitting your registration, please wait for approval before attempting to log in.
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>
                                <?php echo htmlspecialchars($success); ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="registrationForm">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="first_name" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" 
                                           value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="last_name" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name"
                                           value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="student_id" class="form-label">Student ID *</label>
                                <input type="text" class="form-control" id="student_id" name="student_id"
                                       value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id']) : ''; ?>" required>
                                <div class="form-text">Enter your official student identification number</div>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address *</label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="username" class="form-label">Username *</label>
                                <input type="text" class="form-control" id="username" name="username"
                                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
                                <div class="form-text">Username cannot contain spaces</div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="password" class="form-label">Password *</label>
                                    <div class="password-toggle">
                                        <input type="password" class="form-control" id="password" name="password" required>
                                        <i class="bi bi-eye-slash toggle-password" onclick="togglePassword('password', this)" title="Show/Hide Password"></i>
                                    </div>
                                    <div class="password-strength" id="passwordStrength"></div>
                                </div>
                                <div class="col-md-6">
                                    <label for="confirm_password" class="form-label">Confirm Password *</label>
                                    <div class="password-toggle">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                        <i class="bi bi-eye-slash toggle-password" onclick="togglePassword('confirm_password', this)" title="Show/Hide Password"></i>
                                    </div>
                                    <div id="passwordMatch"></div>
                                </div>
                            </div>

                            <div class="password-requirements">
                                <strong><i class="bi bi-info-circle me-1"></i>Password Requirements:</strong>
                                <ul>
                                    <li>At least <?php echo $min_length; ?> characters long</li>
                                    <li>Contains uppercase letter</li>
                                    <li>Contains lowercase letter</li>
                                    <li>Contains number</li>
                                    <?php if ($require_special): ?>
                                        <li>Contains special character</li>
                                    <?php endif; ?>
                                </ul>
                            </div>

                            <div class="row g-3 mt-2">
                                <div class="col-md-6">
                                    <label for="college" class="form-label">College *</label>
                                    <select class="form-select" id="college" name="college" required>
                                        <option value="" disabled <?php echo !isset($_POST['college']) ? 'selected' : ''; ?>>Select College</option>
                                        <?php foreach ($colleges as $college_option): ?>
                                            <option value="<?php echo htmlspecialchars($college_option); ?>" 
                                                    <?php echo (isset($_POST['college']) && $_POST['college'] == $college_option) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($college_option); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="department" class="form-label">Department *</label>
                                    <select class="form-select" id="department" name="department" required disabled>
                                        <option value="">Select college first</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary w-100">
                                    <?php echo $require_approval ? 'Submit Registration for Approval' : 'Register'; ?>
                                </button>
                            </div>
                        </form>

                        <div class="text-center mt-4">
                            <p class="mb-2">Already have an account? <a href="login.php">Login</a></p>
                            <a href="../index.php" class="text-decoration-none">Back to Home</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // College-Department mapping from PHP
        const collegeDepartments = <?php echo json_encode($college_departments); ?>;

        // Username space prevention
        function preventSpaceInUsername() {
            const usernameField = document.getElementById('username');
            
            // Prevent space on keypress
            usernameField.addEventListener('keypress', function(e) {
                if (e.key === ' ' || e.keyCode === 32) {
                    e.preventDefault();
                    // Show brief feedback
                    const feedback = document.createElement('div');
                    feedback.className = 'text-danger small mt-1';
                    feedback.textContent = 'Username cannot contain spaces';
                    feedback.style.position = 'absolute';
                    
                    // Remove existing feedback if any
                    const existingFeedback = usernameField.parentNode.querySelector('.text-danger');
                    if (existingFeedback && existingFeedback !== usernameField.nextElementSibling) {
                        existingFeedback.remove();
                    }
                    
                    usernameField.parentNode.appendChild(feedback);
                    
                    // Remove feedback after 2 seconds
                    setTimeout(() => {
                        if (feedback.parentNode) {
                            feedback.remove();
                        }
                    }, 2000);
                }
            });
            
            // Remove spaces on paste or input
            usernameField.addEventListener('input', function(e) {
                this.value = this.value.replace(/\s/g, '');
            });
            
            // Remove spaces on paste specifically
            usernameField.addEventListener('paste', function(e) {
                setTimeout(() => {
                    this.value = this.value.replace(/\s/g, '');
                }, 1);
            });
        }

        // Toggle password visibility
        function togglePassword(fieldId, icon) {
            const field = document.getElementById(fieldId);
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
            
            // Toggle the icon
            if (type === 'text') {
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            } else {
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        }

        // Cascading dropdown functionality
        function setupCascadingDropdowns() {
            const collegeSelect = document.getElementById('college');
            const departmentSelect = document.getElementById('department');

            collegeSelect.addEventListener('change', function() {
                const selectedCollege = this.value;
                
                // Clear department dropdown
                departmentSelect.innerHTML = '<option value="">Select department</option>';
                
                if (selectedCollege && collegeDepartments[selectedCollege]) {
                    // Enable department dropdown
                    departmentSelect.disabled = false;
                    
                    // Populate departments for selected college
                    collegeDepartments[selectedCollege].forEach(function(department) {
                        const option = document.createElement('option');
                        option.value = department;
                        option.textContent = department;
                        departmentSelect.appendChild(option);
                    });
                } else {
                    // Disable department dropdown if no college selected
                    departmentSelect.disabled = true;
                    departmentSelect.innerHTML = '<option value="">Select college first</option>';
                }
            });

            // Initialize department dropdown on page load if college is already selected
            if (collegeSelect.value) {
                collegeSelect.dispatchEvent(new Event('change'));
                
                // Restore selected department if form was submitted with errors
                const selectedDepartment = '<?php echo isset($_POST['department']) ? htmlspecialchars($_POST['department']) : ''; ?>';
                if (selectedDepartment) {
                    setTimeout(() => {
                        departmentSelect.value = selectedDepartment;
                    }, 100);
                }
            }
        }

        // Password strength checker
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthDiv = document.getElementById('passwordStrength');
            const minLength = <?php echo $min_length; ?>;
            const requireSpecial = <?php echo $require_special ? 'true' : 'false'; ?>;
            
            let strength = 0;
            let message = '';
            
            if (password.length >= minLength) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (requireSpecial && /[^A-Za-z0-9]/.test(password)) strength++;
            
            const maxStrength = requireSpecial ? 5 : 4;
            
            if (password.length === 0) {
                message = '';
                strengthDiv.className = 'password-strength';
            } else if (strength < 2) {
                message = 'Weak password';
                strengthDiv.className = 'password-strength strength-weak';
            } else if (strength < maxStrength) {
                message = 'Medium password';
                strengthDiv.className = 'password-strength strength-medium';
            } else {
                message = 'Strong password';
                strengthDiv.className = 'password-strength strength-strong';
            }
            
            strengthDiv.textContent = message;
        });
        
        // Password confirmation check
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            const matchDiv = document.getElementById('passwordMatch');
            
            if (confirmPassword) {
                if (password === confirmPassword) {
                    matchDiv.innerHTML = '<small class="text-success"><i class="bi bi-check-circle"></i> Passwords match</small>';
                } else {
                    matchDiv.innerHTML = '<small class="text-danger"><i class="bi bi-x-circle"></i> Passwords do not match</small>';
                }
            } else {
                matchDiv.innerHTML = '';
            }
        });
        
        // Form validation
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const college = document.getElementById('college').value;
            const department = document.getElementById('department').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (!college) {
                e.preventDefault();
                alert('Please select a college.');
                return false;
            }
            
            if (!department) {
                e.preventDefault();
                alert('Please select a department.');
                return false;
            }
            
            // Check if all required fields are filled
            const requiredFields = this.querySelectorAll('[required]');
            for (let field of requiredFields) {
                if (!field.value.trim()) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                    field.focus();
                    return false;
                }
            }
        });

        // Initialize all functionality when page loads
        document.addEventListener('DOMContentLoaded', function() {
            preventSpaceInUsername();
            setupCascadingDropdowns();
        });
    </script>
</body>
</html>