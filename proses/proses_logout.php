<?php
session_start();
session_destroy();
header("Location: ../pages/login_owner.php");
exit();
?>