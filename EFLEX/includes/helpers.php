<?php
function generate_unique_transaction_id($mysqli) {
    $length = 6;
    $max_attempts = 10;
    $attempt = 0;

    while ($attempt < $max_attempts) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $characters_length = strlen($characters);
        $random_string = '';
        for ($i = 0; $i < $length; $i++) {
            $random_string .= $characters[rand(0, $characters_length - 1)];
        }

        // Check if the ID already exists in the database
        $stmt = $mysqli->prepare("SELECT id FROM orders WHERE transaction_id = ?");
        $stmt->bind_param("s", $random_string);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows == 0) {
            $stmt->close();
            return $random_string;
        }

        $stmt->close();
        $attempt++;
    }

    // Fallback to a longer, more random ID if we can't find a unique one
    return uniqid('TXN-', true);
}

function render_pagination($base_url, $total_pages, $current_page, $query_string = "") {
    if ($total_pages <= 1) {
        return;
    }

    echo '<nav aria-label="Page navigation">';
    echo '<ul class="pagination justify-content-center flex-wrap mt-4">';

    // Previous button
    if ($current_page > 1) {
        echo '<li class="page-item"><a class="page-link" href="' . $base_url . '?' . $query_string . 'page=' . ($current_page - 1) . '">Previous</a></li>';
    }

    // Page numbers logic
    $window = 2; // Number of pages to show around the current page
    if ($total_pages <= (2 * $window) + 5) {
        // Show all pages if there aren't too many
        for ($i = 1; $i <= $total_pages; $i++) {
            echo '<li class="page-item ' . ($i == $current_page ? 'active' : '') . '"><a class="page-link" href="' . $base_url . '?' . $query_string . 'page=' . $i . '">' . $i . '</a></li>';
        }
    } else {
        // Show first page
        echo '<li class="page-item ' . (1 == $current_page ? 'active' : '') . '"><a class="page-link" href="' . $base_url . '?' . $query_string . 'page=1">1</a></li>';

        // Ellipsis after first page
        if ($current_page > $window + 2) {
            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }

        // Window of pages around current
        $start = max(2, $current_page - $window);
        $end = min($total_pages - 1, $current_page + $window);
        for ($i = $start; $i <= $end; $i++) {
            echo '<li class="page-item ' . ($i == $current_page ? 'active' : '') . '"><a class="page-link" href="' . $base_url . '?' . $query_string . 'page=' . $i . '">' . $i . '</a></li>';
        }

        // Ellipsis before last page
        if ($current_page < $total_pages - $window - 1) {
            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }

        // Show last page
        echo '<li class="page-item ' . ($total_pages == $current_page ? 'active' : '') . '"><a class="page-link" href="' . $base_url . '?' . $query_string . 'page=' . $total_pages . '">' . $total_pages . '</a></li>';
    }

    // Next button
    if ($current_page < $total_pages) {
        echo '<li class="page-item"><a class="page-link" href="' . $base_url . '?' . $query_string . 'page=' . ($current_page + 1) . '">Next</a></li>';
    }

    echo '</ul>';
    echo '</nav>';
}

function check_remember_me($mysqli) {
    if (empty($_SESSION["loggedin"]) && isset($_COOKIE['remember_me'])) {
        $parts = explode(':', $_COOKIE['remember_me'], 2);
        if (count($parts) === 2) {
            list($token, $user_id) = $parts;

            if (!empty($token) && is_numeric($user_id)) {
                $hashed_token = hash('sha256', $token);
                $user_id = (int)$user_id;
                $stmt = $mysqli->prepare("SELECT user_id FROM auth_tokens WHERE token = ? AND user_id = ? AND expires_at > NOW()");
                $stmt->bind_param("si", $hashed_token, $user_id);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows == 1) {
                    // Token is valid, log the user in
                    session_regenerate_id();

                    // Fetch user details to populate session
                    $user_stmt = $mysqli->prepare("SELECT id, username, email, role_id FROM users WHERE id = ?");
                    $user_stmt->bind_param("i", $user_id);
                    $user_stmt->execute();
                    $user_result = $user_stmt->get_result();
                    $user = $user_result->fetch_assoc();

                    $_SESSION["loggedin"] = true;
                    $_SESSION["id"] = $user['id'];
                    $_SESSION["username"] = $user['username'];
                    // Add any other necessary session variables here, like email
                    $_SESSION['email'] = $user['email'];
                    // null for customers, so isset($_SESSION['role_id']) stays false
                    $_SESSION['role_id'] = $user['role_id'];

                    $user_stmt->close();
                }
                $stmt->close();
            }
        }
    }
}
?>