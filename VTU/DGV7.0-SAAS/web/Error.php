<?php
/**
 * Site-level "not found" page.
 *
 * Deliberately dependency-free: this is the page a broken URL lands on, so it must not
 * require a database connection to render. It uses the shared Plate auth shell, which is
 * presentation-only and needs no connection.
 */
require_once(__DIR__ . "/../func/bc-plate-auth.php");

bc_plate_auth_head('Page not found');
bc_plate_auth_top(array(
    'icon'      => 'warn',
    'icon_style'=> 'warn',
    'title'     => 'Page not found',
    'sub'       => 'We are sorry, but the page you are looking for does not exist. Please check the address, or go back to the homepage.',
));
?>
        <div class="pk-auth__field">
            <a class="pk-auth__btn" href="<?php echo htmlspecialchars($GLOBALS['web_http_host'] ?? ''); ?>/index.php">
                <?php echo bc_plate_auth_icon('home', 18); ?> Back to homepage
            </a>
        </div>
        <p class="pk-auth__note">
            If you believe this is a mistake, contact the site administrator.
        </p>
<?php
bc_plate_auth_end();
