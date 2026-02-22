// Main script for Research Approval System

document.addEventListener('DOMContentLoaded', function() {
    // Tooltips initialization
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Popovers initialization
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function(popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
    
    // Dropdown on hover for desktop
    if (window.innerWidth > 992) {
        document.querySelectorAll('.navbar .nav-item').forEach(function(everyItem) {
            everyItem.addEventListener('mouseover', function(e) {
                let el_link = this.querySelector('a[data-bs-toggle]');
                if (el_link != null) {
                    let nextEl = el_link.nextElementSibling;
                    el_link.classList.add('show');
                    nextEl.classList.add('show');
                }
            });
            everyItem.addEventListener('mouseleave', function(e) {
                let el_link = this.querySelector('a[data-bs-toggle]');
                if (el_link != null) {
                    let nextEl = el_link.nextElementSibling;
                    el_link.classList.remove('show');
                    nextEl.classList.remove('show');
                }
            });
        });
    }
    
    // Notification auto-dismiss
    const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            if (alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 5000);
    });
    
    // File input validation
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(function(input) {
        input.addEventListener('change', function() {
            const fileName = this.files[0].name;
            const fileSize = this.files[0].size;
            const fileType = this.files[0].type;
            
            // Check file extension
            const allowedExtensions = ['doc', 'docx', 'pdf'];
            const extension = fileName.split('.').pop().toLowerCase();
            
            if (!allowedExtensions.includes(extension)) {
                alert('Only DOC, DOCX, and PDF files are allowed');
                this.value = ''; // Clear the file input
                return;
            }
            
            // Check file size (max 10MB)
            const maxSize = 10 * 1024 * 1024; // 10MB in bytes
            if (fileSize > maxSize) {
                alert('File size exceeds 10MB limit');
                this.value = ''; // Clear the file input
                return;
            }
            
            // Update file input label with filename
            const fileLabel = this.nextElementSibling;
            if (fileLabel && fileLabel.classList.contains('form-file-label')) {
                fileLabel.textContent = fileName;
            }
        });
    });
    
    // Custom form validation
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
    
    // Password strength meter
    const passwordInputs = document.querySelectorAll('.password-strength');
    passwordInputs.forEach(function(input) {
        input.addEventListener('input', function() {
            const password = this.value;
            const meter = this.nextElementSibling;
            
            if (!meter || !meter.classList.contains('password-strength-meter')) {
                return;
            }
            
            // Check password strength
            let strength = 0;
            
            // Length check
            if (password.length >= 8) strength += 1;
            
            // Character variety checks
            if (password.match(/[a-z]+/)) strength += 1;
            if (password.match(/[A-Z]+/)) strength += 1;
            if (password.match(/[0-9]+/)) strength += 1;
            if (password.match(/[^a-zA-Z0-9]+/)) strength += 1;
            
            // Update meter
            meter.value = strength;
            
            // Update feedback text
            const feedback = meter.nextElementSibling;
            if (feedback && feedback.classList.contains('password-feedback')) {
                if (strength < 2) {
                    feedback.textContent = 'Weak password';
                    feedback.className = 'password-feedback text-danger';
                } else if (strength < 4) {
                    feedback.textContent = 'Moderate password';
                    feedback.className = 'password-feedback text-warning';
                } else {
                    feedback.textContent = 'Strong password';
                    feedback.className = 'password-feedback text-success';
                }
            }
        });
    });
    
    // Confirm form submission
    const confirmForms = document.querySelectorAll('form[data-confirm]');
    confirmForms.forEach(form => {
        form.addEventListener('submit', event => {
            const confirmMessage = form.getAttribute('data-confirm');
            if (!confirm(confirmMessage)) {
                event.preventDefault();
            }
        });
    });
    
    // Dynamic dependent dropdowns
    const collegeDropdowns = document.querySelectorAll('.college-dropdown');
    collegeDropdowns.forEach(dropdown => {
        dropdown.addEventListener('change', function() {
            const departmentDropdown = document.querySelector('#' + this.getAttribute('data-department-target'));
            if (!departmentDropdown) return;
            
            // Clear current options
            departmentDropdown.innerHTML = '<option value="" selected disabled>Select department</option>';
            
            // Get department options based on selected college
            const collegeId = this.value;
            if (!collegeId) return;
            
            // This would typically fetch from the server, but we'll use a simple example
            const departments = getDepartmentsForCollege(collegeId);
            
            // Add new options
            departments.forEach(dept => {
                const option = document.createElement('option');
                option.value = dept.id;
                option.textContent = dept.name;
                departmentDropdown.appendChild(option);
            });
            
            // Enable the department dropdown
            departmentDropdown.disabled = false;
        });
    });
    
    // Example function to get departments - would typically be replaced with AJAX
    function getDepartmentsForCollege(collegeId) {
        // Placeholder - replace with actual data or API call
        const departments = {
            'College of Computer Studies': [
                { id: 'cs', name: 'Computer Science' },
                { id: 'it', name: 'Information Technology' }
            ],
            'College of Engineering': [
                { id: 'ce', name: 'Civil Engineering' },
                { id: 'ee', name: 'Electrical Engineering' },
                { id: 'me', name: 'Mechanical Engineering' }
            ]
            // Add more colleges and their departments
        };
        
        return departments[collegeId] || [];
    }
    
    // Date formatting for readable dates
    document.querySelectorAll('.format-date').forEach(element => {
        const dateString = element.textContent;
        const date = new Date(dateString);
        
        if (isNaN(date)) return;
        
        const options = { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        };
        
        element.textContent = date.toLocaleDateString('en-US', options);
    });
});