<?php
$password = 'adviser123';
$hash = password_hash($password, PASSWORD_DEFAULT);
echo "Adviser Password: " . $password . "<br>";
echo "Generated hash: " . $hash;
