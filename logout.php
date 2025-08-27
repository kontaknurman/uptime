<?php
require_once 'bootstrap.php';

$auth->logout();
redirect('/login.php');