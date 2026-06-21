<?php

$cfg['AllowArbitraryServer'] = false;

if (isset($i) && $i > 0) {
    $cfg['Servers'][$i]['host'] = 'localhost';
    $cfg['Servers'][$i]['auth_type'] = 'cookie';
    $cfg['Servers'][$i]['AllowNoPassword'] = false;
}

$cfg['LoginCookieValidity'] = 1800;
$cfg['LoginCookieStore'] = 0;
