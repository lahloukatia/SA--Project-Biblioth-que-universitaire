<?php
require __DIR__ . '/config.php';
demarrer_session();
rediriger(utilisateur() ? 'catalogue.php' : 'login.php');
