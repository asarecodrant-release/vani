<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require "email.php";

$result = sendWelcomeEmail(
    "sushrut.asare.itmoe@gmail.com",
    "TEST123",
    "Password@123"
);

var_dump($result);