<?php
// パスワードのハッシュを作る:  php scripts/make_password_hash.php "パスワード"
echo password_hash($argv[1] ?? "", PASSWORD_DEFAULT), "\n";
