<?php
include __DIR__ . '/../inc/pro.php';
if (!empty($_SESSION['look'])) { http_response_code(403); exit; }
hp_render(__('Archivos y transferencias'), 'transfers', 'transfers');
