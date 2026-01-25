<?php
/**
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 * @var \MapasCulturais\App $app
 */

use MapasCulturais\i;

$this->import('
    rcv-relatorio-table
');
?>

<?php $this->applyTemplateHook('rcv-relatorio', 'before') ?>
<div class="main-app rcv-relatorio">
    <?php $this->applyTemplateHook('rcv-relatorio', 'begin') ?>
    
    <h3 class="rcv-relatorio__title">
        <?= i::__('Relatório de Importação PNAB') ?>
    </h3>
    
    <div class="rcv-relatorio__content">
        <rcv-relatorio-table cenarios="<?= $cenarios ?? '' ?>"></rcv-relatorio-table>
    </div>

    <?php $this->applyTemplateHook('rcv-relatorio', 'end') ?>
</div>
<?php $this->applyTemplateHook('rcv-relatorio', 'after') ?>
