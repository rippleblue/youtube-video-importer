<?php
echo $_SERVER["HTTPS"] . '<br/>';
echo isset($_SERVER["HTTPS"]) . '<br/>';
echo $_SERVER["SERVER_PORT"] . '<br/>';
echo ($_SERVER["SERVER_PORT"] === 443) . '<br/>';
echo ($_SERVER["SERVER_PORT"] == 443) . '<br/>' ;
echo $_SERVER["HTTP_ORIGIN"]  . '<br/>' ;
?>