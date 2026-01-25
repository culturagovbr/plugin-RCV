<?php
/**
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 * @var \MapasCulturais\App $app
 */

use MapasCulturais\i;

$this->import('
    mc-loading
');
?>

<div class="rcv-relatorio-table">
    <div v-if="loading" class="rcv-relatorio-table__loading">
        <mc-loading></mc-loading>
        <p><?= i::__('Carregando dados...') ?></p>
    </div>

    <div v-else-if="error" class="rcv-relatorio-table__error">
        <p>{{ error }}</p>
    </div>

    <div v-else class="rcv-relatorio-table__content">
        <div class="rcv-relatorio-table__filters">
            <h4 class="rcv-relatorio-table__filters-title"><?= i::__('Filtrar por Cenários:') ?></h4>
            <div class="rcv-relatorio-table__filters-options">
                <label class="rcv-relatorio-table__checkbox" title="<?= i::esc_attr__('Inscrição do Ponto encontrada no cadastro e aprovada') ?>">
                    <input type="checkbox" value="1" v-model="cenariosSelected" @change="applyFilters">
                    <span><?= i::__('Cenário 1') ?></span>
                </label>
                <label class="rcv-relatorio-table__checkbox" title="<?= i::esc_attr__('Organização encontrada por CNPJ/CPF, inscrição criada') ?>">
                    <input type="checkbox" value="2" v-model="cenariosSelected" @change="applyFilters">
                    <span><?= i::__('Cenário 2') ?></span>
                </label>
                <label class="rcv-relatorio-table__checkbox" title="<?= i::esc_attr__('Proprietário encontrado, organização e inscrição criadas') ?>">
                    <input type="checkbox" value="3.1" v-model="cenariosSelected" @change="applyFilters">
                    <span><?= i::__('Cenário 3.1') ?></span>
                </label>
                <label class="rcv-relatorio-table__checkbox" title="<?= i::esc_attr__('Nada encontrado, criação completa (usuário, agente, organização e inscrição)') ?>">
                    <input type="checkbox" value="4" v-model="cenariosSelected" @change="applyFilters">
                    <span><?= i::__('Cenário 4') ?></span>
                </label>
                <label class="rcv-relatorio-table__checkbox" title="<?= i::esc_attr__('Organização encontrada mas com CPF divergente, aguardando regularização') ?>">
                    <input type="checkbox" value="5" v-model="cenariosSelected" @change="applyFilters">
                    <span><?= i::__('Cenário 5') ?></span>
                </label>
            </div>
            
            <div class="rcv-relatorio-table__filters-divider"></div>
            
            <div class="rcv-relatorio-table__filters-section">
                <h4 class="rcv-relatorio-table__filters-title"><?= i::__('Filtrar por Status:') ?></h4>
                <div class="rcv-relatorio-table__filters-options">
                    <label class="rcv-relatorio-table__checkbox rcv-relatorio-table__checkbox--success" title="<?= i::esc_attr__('Exibir apenas inscrições processadas com sucesso (padrão)') ?>">
                        <input type="radio" name="statusFilter" value="normal" v-model="statusFilter" @change="applyFilters">
                        <span><?= i::__('Apenas OK') ?></span>
                    </label>
                    <label class="rcv-relatorio-table__checkbox rcv-relatorio-table__checkbox--error" title="<?= i::esc_attr__('Exibir apenas inscrições que tiveram erros de processamento') ?>">
                        <input type="radio" name="statusFilter" value="errors" v-model="statusFilter" @change="applyFilters">
                        <span><?= i::__('Apenas com Erros') ?></span>
                    </label>
                    <label class="rcv-relatorio-table__checkbox rcv-relatorio-table__checkbox--all" title="<?= i::esc_attr__('Exibir todas as inscrições (OK + erros)') ?>">
                        <input type="radio" name="statusFilter" value="all" v-model="statusFilter" @change="applyFilters">
                        <span><?= i::__('Tudo (OK + Erros)') ?></span>
                    </label>
                </div>
            </div>
        </div>

        <div class="rcv-relatorio-table__info">
            <div class="rcv-relatorio-table__info-stats">
                <p><strong><?= i::__('Cenários selecionados:') ?></strong> {{ cenariosSelected.length > 0 ? cenariosSelected.join(', ') : 'Nenhum' }}</p>
                <p><strong>{{ paginationInfo }}</strong></p>
            </div>
            <button @click="exportarExcel" class="rcv-relatorio-table__export-btn" v-if="dados.length > 0">
                <span><?= i::__('Exportar Excel') ?></span>
            </button>
        </div>

        <div v-if="dados.length > 0" class="rcv-relatorio-table__column-control">
            <button @click="toggleSeletorColunas" class="rcv-relatorio-table__column-toggle">
                <span v-if="!mostrarSeletorColunas">▼ <?= i::__('Exibir/Ocultar Colunas') ?></span>
                <span v-else>▲ <?= i::__('Exibir/Ocultar Colunas') ?></span>
            </button>
            
            <div v-show="mostrarSeletorColunas" class="rcv-relatorio-table__column-selector">
                <p class="rcv-relatorio-table__column-selector-hint">
                    <?= i::__('Marque as colunas que deseja visualizar na tabela:') ?>
                </p>
                <div class="rcv-relatorio-table__column-options">
                    <label v-for="coluna in todasColunas" :key="coluna" class="rcv-relatorio-table__column-checkbox">
                        <input 
                            type="checkbox" 
                            :value="coluna"
                            :checked="colunaVisivel(coluna)"
                            @change="toggleColuna(coluna)"
                        >
                        <span>{{ coluna }}</span>
        
                    </label>
                </div>
            </div>
        </div>
        
        <div v-if="dados.length > 0" class="rcv-relatorio-table__wrapper">
            <table class="rcv-relatorio-table__table">
                <thead>
                    <tr>
                        <th v-for="coluna in todasColunas" v-show="colunaVisivel(coluna)" :key="coluna">
                            {{ coluna }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(registro, index) in dados" :key="index">
                        <td v-for="coluna in todasColunas" v-show="colunaVisivel(coluna)" :key="coluna">
                            {{ registro[coluna] }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="totalPages > 1" class="rcv-relatorio-table__pagination">
            <div class="rcv-relatorio-table__pagination-controls">
                <button 
                    @click="prevPage" 
                    :disabled="!hasPreviousPage"
                    class="rcv-relatorio-table__pagination-btn"
                    :class="{'rcv-relatorio-table__pagination-btn--disabled': !hasPreviousPage}">
                    ← <?= i::__('Anterior') ?>
                </button>

                <div class="rcv-relatorio-table__pagination-info">
                    <span><?= i::__('Página') ?></span>
                    <input 
                        type="number" 
                        v-model.number="currentPage" 
                        @change="goToPage(currentPage)"
                        min="1" 
                        :max="totalPages"
                        class="rcv-relatorio-table__pagination-input">
                    <span><?= i::__('de') ?> {{ totalPages }}</span>
                </div>

                <button 
                    @click="nextPage" 
                    :disabled="!hasNextPage"
                    class="rcv-relatorio-table__pagination-btn"
                    :class="{'rcv-relatorio-table__pagination-btn--disabled': !hasNextPage}">
                    <?= i::__('Próxima') ?> →
                </button>
            </div>

            <div class="rcv-relatorio-table__pagination-size">
                <label>
                    <span><?= i::__('Inscrições por página:') ?></span>
                    <select v-model.number="itemsPerPage" @change="changeItemsPerPage(itemsPerPage)" class="rcv-relatorio-table__pagination-select">
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="30">30</option>
                        <option :value="50">50</option>
                    </select>
                </label>
            </div>
        </div>

        <div v-else class="rcv-relatorio-table__empty">
            <p><?= i::__('Nenhum registro encontrado.') ?></p>
        </div>
    </div>
</div>
