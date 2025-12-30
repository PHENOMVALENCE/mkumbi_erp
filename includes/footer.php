</div>
    <!-- /.content-wrapper -->

    <!-- Professional Footer -->
    <footer class="main-footer">
        <div class="footer-left">
            <span><strong>Enterprise ERP</strong> &copy; <?php echo date('Y'); ?></span>
            <span class="footer-divider"></span>
            <span>Version 1.0.0</span>
            <span class="footer-divider"></span>
            <span>All rights reserved</span>
        </div>
        <div class="footer-right">
            <a href="#" class="footer-link">
                <i class="fas fa-life-ring me-1"></i> Support
            </a>
            <span class="footer-divider"></span>
            <a href="#" class="footer-link">
                <i class="fas fa-file-alt me-1"></i> Documentation
            </a>
            <span class="footer-divider"></span>
            <a href="#" class="footer-link">
                <i class="fas fa-info-circle me-1"></i> About
            </a>
        </div>
    </footer>

</div>
<!-- ./wrapper -->

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Application Scripts -->
<script>
    // Toggle Sidebar for Mobile
    function toggleSidebar() {
        const sidebar = document.getElementById('mainSidebar');
        sidebar.classList.toggle('sidebar-open');
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('mainSidebar');
        const toggle = document.querySelector('[onclick*="toggleSidebar"]');
        
        if (window.innerWidth < 992) {
            if (!sidebar.contains(event.target) && toggle && !toggle.contains(event.target)) {
                sidebar.classList.remove('sidebar-open');
            }
        }
    });
    
    // Dropdown menu toggle for sidebar
    document.querySelectorAll('.nav-link[data-toggle="dropdown"]').forEach(function(element) {
        element.addEventListener('click', function(e) {
            e.preventDefault();
            const navItem = this.closest('.nav-item');
            const isOpen = navItem.classList.contains('menu-open');
            
            // Close all other dropdowns
            document.querySelectorAll('.nav-item.menu-open').forEach(function(item) {
                if (item !== navItem) {
                    item.classList.remove('menu-open');
                }
            });
            
            // Toggle current dropdown
            if (isOpen) {
                navItem.classList.remove('menu-open');
            } else {
                navItem.classList.add('menu-open');
            }
        });
    });
    
    // Auto-open active menu item on page load
    document.addEventListener('DOMContentLoaded', function() {
        const activeLink = document.querySelector('.nav-treeview .nav-link.active');
        if (activeLink) {
            const parentNavItem = activeLink.closest('.nav-item');
            if (parentNavItem && parentNavItem.previousElementSibling) {
                parentNavItem.previousElementSibling.classList.add('menu-open');
            }
        }
    });
</script>
</body>
</html>