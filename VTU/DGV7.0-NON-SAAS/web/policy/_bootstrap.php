<?php
/**
 * Shared bootstrap for public policy pages.
 * Sets up DB connection without requiring a logged-in user.
 */
error_reporting(0);
ini_set('display_errors', 0);
include_once(__DIR__ . '/../../func/bc-connect.php');
