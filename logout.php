<?php
require __DIR__ . '/lib.php';
db();
logout_everything();
flash('Du wurdest abgemeldet.', 'success');
redirect('login.php');
