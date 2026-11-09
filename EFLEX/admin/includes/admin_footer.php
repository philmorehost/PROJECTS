</div> <!-- /.container-fluid -->
    </div> <!-- /.admin-content -->
</div> <!-- /.admin-wrapper -->

<!-- Bootstrap JS Bundle with Popper -->
<script src="../js/bootstrap.bundle.min.js"></script>
<!-- Chart.js for graphs -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- Custom Admin JS -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Sidebar toggle functionality
    const sidebarCollapse = document.getElementById('sidebarCollapse');
    const sidebar = document.querySelector('.admin-sidebar');
    const overlay = document.querySelector('.sidebar-overlay');

    const toggleSidebar = function() {
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
    };

    if (sidebarCollapse) {
        sidebarCollapse.addEventListener('click', toggleSidebar);
    }
    if(overlay) {
        overlay.addEventListener('click', toggleSidebar);
    }

    // Prevent dropdown from closing on click inside
    document.querySelectorAll('.dropdown-menu').forEach(function(element){
        element.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    });
});
</script>

</body>
</html>
