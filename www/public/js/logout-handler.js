// Logout Modal Handler - Reusable for all pages
document.addEventListener('DOMContentLoaded', function() {
    // Logout modal elements
    const saveDbFromLogoutBtn = document.getElementById('saveDbFromLogoutBtn');
    const logoutModal = document.getElementById('logoutModal');
    const dbSaveSuccessModal = document.getElementById('dbSaveSuccessModal');
    const savedFilePathSpan = document.getElementById('savedFilePath');
    const logoutButton = document.getElementById('logoutButton');

    // Initialize Bootstrap modals
    let logoutModalInstance = null;
    let dbSaveSuccessModalInstance = null;

    if (logoutModal) {
        logoutModalInstance = new bootstrap.Modal(logoutModal);
    }
    if (dbSaveSuccessModal) {
        dbSaveSuccessModalInstance = new bootstrap.Modal(dbSaveSuccessModal);
    }

    // Handle save database from logout modal
    if (saveDbFromLogoutBtn) {
        saveDbFromLogoutBtn.addEventListener('click', function() {
            // Disable the button and show loading state
            saveDbFromLogoutBtn.disabled = true;
            saveDbFromLogoutBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Saving...';
            
            // Determine the correct path to export_db.php based on current page
            const currentPath = window.location.pathname;
            let exportPath = 'export_db.php';
            
            // If we're in a subdirectory (like teacher/), adjust the path
            if (currentPath.includes('/teacher/')) {
                exportPath = '../public/export_db.php';
            } else if (currentPath.includes('/public/')) {
                exportPath = 'export_db.php';
            }
            
            // Make AJAX request to save database
            fetch(exportPath, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                }
            })
            .then(response => response.json())
            .then(data => {
                // Hide logout modal
                if (logoutModalInstance) {
                    logoutModalInstance.hide();
                }
                
                if (data.success) {
                    // Show success modal
                    if (savedFilePathSpan) {
                        savedFilePathSpan.textContent = data.message;
                    }
                    if (dbSaveSuccessModalInstance) {
                        dbSaveSuccessModalInstance.show();
                    }
                } else {
                    // Show error alert
                    alert('Error saving database: ' + (data.error || 'Unknown error occurred'));
                }
            })
            .catch(error => {
                console.error('Error saving database:', error);
                if (logoutModalInstance) {
                    logoutModalInstance.hide();
                }
                alert('Error saving database. Please try again.');
            })
            .finally(() => {
                // Reset button state
                saveDbFromLogoutBtn.disabled = false;
                saveDbFromLogoutBtn.innerHTML = '<i class="bi bi-floppy-fill me-2"></i>Save Database';
            });
        });
    }

    // Clear chatbot conversation on logout
    if (logoutButton) {
        logoutButton.addEventListener('click', function(event) {
            // Clear local storage for chatbot conversation
            localStorage.removeItem('udm_isla_conversation');
            localStorage.removeItem('chatbot_conversation');
            // Let the default logout action proceed
        });
    }
}); 