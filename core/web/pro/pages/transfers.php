<?php hp_page_header(__('Archivos y transferencias'), __('Explora, edita y transfiere archivos entre tus servidores.')); ?>
<div style="overflow:hidden">
    <iframe title="<?= __e('Archivos y transferencias') ?>" src="/pro/transfers/service/<?= !empty($_GET['open']) ? '?open=' . rawurlencode((string)$_GET['open']) : '' ?>" style="display:block;width:100%;height:calc(100vh - 190px);min-height:850px;border:0;background:transparent" referrerpolicy="same-origin"></iframe>
</div>
