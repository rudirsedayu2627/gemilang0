<?php
require_once __DIR__ . '/includes/helpers.php';
logout_user();
session_start();
flash('Kamu sudah keluar. Sampai jumpa lagi!');
redirect('login.php');
