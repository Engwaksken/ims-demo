</main><!-- Close main-content -->

    <!-- JavaScript -->
    <script src="../js/main.js"></script>
    <script src="../js/stat-filters.js"></script>
<?php if (empty($load_chartjs)): /* already loaded in <head> by header.php when set */ ?>
    <!-- Chart.js pinned to the version the floating "npm/chart.js" URL served (4.5.1) so it can carry SRI. -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<?php endif; ?>
</body>
</html>
