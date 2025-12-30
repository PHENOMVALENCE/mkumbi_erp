</div>
        <!-- /.content-wrapper -->
    </div>
    <!-- ./wrapper -->

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- AdminLTE App -->
    <script>
        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.getElementById('mainSidebar');
            sidebar.classList.toggle('sidebar-open');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('mainSidebar');
            const toggle = document.querySelector('[onclick="toggleSidebar()"]');
            
            if (window.innerWidth < 992) {
                if (!sidebar.contains(event.target) && toggle && !toggle.contains(event.target)) {
                    sidebar.classList.remove('sidebar-open');
                }
            }
        });

        // Dropdown menu toggle
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

        // Auto-open active menu item
        document.addEventListener('DOMContentLoaded', function() {
            const activeLink = document.querySelector('.nav-treeview .nav-link.active');
            if (activeLink) {
                const navItem = activeLink.closest('.nav-item.menu-open');
                if (navItem) {
                    navItem.classList.add('menu-open');
                }
            }
        });
    </script>
</body>
</html>