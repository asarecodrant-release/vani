<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require "email.php";

$result = sendWelcomeEmail(
    "sushrut.asare@gmail.com",
    "TEST123",
    "Local Mail Test",
    "Password@123"
);

var_dump($result);
