<?php
include __DIR__ . '/../inc/pro.php';
if (!hp_files_allowed()) { http_response_code(403); exit; }
hp_render(__('Archivos y transferencias'), 'transfers', 'transfers');
