<?php
require_once __DIR__ . '/security.php';

require_once __DIR__ . '/auth.php';

fm_logout_user();
fm_redirect(fm_url('signin.php?notice=logged_out'));