<?php
require_once __DIR__ . '/../inc/security.php';
start_secure_session();
session_destroy();
header('Location: /admin/login.php');
