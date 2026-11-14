<?php
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=3600, s-maxage=86400');
readfile(__DIR__ . '/../assets/style.css');
