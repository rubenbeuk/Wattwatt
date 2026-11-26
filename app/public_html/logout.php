<?php
require __DIR__ . '/inc/bootstrap.php';
csrf_check();
$_SESSION = [];
session_destroy();
redirect('login.php');
